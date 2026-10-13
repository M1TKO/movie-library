<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\WorkerPing;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\{LockFactory};
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Process\{Process, ExecutableFinder};

#[AsCommand(name: 'app:serve', description: 'Supervise Caddy, PHP FastCGI, and both workers on localhost or an explicit private LAN address')]
final class ServeCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly MessageBusInterface $bus, private readonly \App\Availability\CheckDispatcher $checks)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Local HTTP port', '8080')
            ->addOption('fcgi-port', null, InputOption::VALUE_REQUIRED, 'Private FastCGI port', '9080')
            ->addOption('caddy', null, InputOption::VALUE_REQUIRED, 'Caddy executable', 'caddy')
            ->addOption('php-cgi', null, InputOption::VALUE_REQUIRED, 'PHP FastCGI executable', 'php-cgi');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($_SERVER['APP_ENV'] ?? '') !== 'prod') {
            throw new \RuntimeException('Use the production start script (APP_ENV=prod).');
        }
        $port = $this->port($input->getOption('port'));
        $fcgiPort = $this->port($input->getOption('fcgi-port'));
        if ($port === $fcgiPort) {
            throw new \RuntimeException('HTTP and FastCGI ports must differ.');
        }
        $address = '127.0.0.1';
        $root = dirname(__DIR__, 2);
        $data = $_SERVER['APP_DATA_DIR'];
        $lock = (new LockFactory(new FlockStore($data)))->createLock('application-supervisor');
        if (!$lock->acquire()) {
            throw new \RuntimeException('Movie Library is already running for this data directory.');
        }
        $children = [];
        $instance = bin2hex(random_bytes(16));
        $stateFile = $data . '/runtime.json';
        $stopFile = $data . '/stop-request';
        $stateWritten = false;
        $logs = [];
        try {
            $restoreLock = (new LockFactory(new FlockStore($data)))->createLock('catalog-restore');
            if (!$restoreLock->acquire()) {
                throw new \RuntimeException('Catalog restore is active.');
            }
            $restoreLock->release();
            if (PHP_OS_FAMILY === 'Windows') {
                (new Process(['powershell','-NoProfile','-ExecutionPolicy','Bypass','-File',$root . '/scripts/Protect-Data.ps1','-Data',$data], $root, null, null, 60))->mustRun();
            } else {
                chmod($data, 0700);
                foreach (['library.sqlite','library.sqlite-wal','library.sqlite-shm','app.secret','tmdb.secret'] as $file) {
                    if (is_file($data . '/' . $file)) {
                        chmod($data . '/' . $file, 0600);
                    }
                }
                foreach (['sessions','cache','logs','posters','backups'] as $dir) {
                    if (is_dir($data . '/' . $dir)) {
                        chmod($data . '/' . $dir, 0700);
                    }
                }
            }
            $finder = new ExecutableFinder();
            $caddyOption = $input->getOption('caddy');
            $cgiOption = $input->getOption('php-cgi');
            $caddy = is_file($caddyOption) ? realpath($caddyOption) : $finder->find($caddyOption);
            $cgi = is_file($cgiOption) ? realpath($cgiOption) : $finder->find($cgiOption);
            if (!$caddy || !$cgi) {
                throw new \RuntimeException('Caddy and php-cgi are required. Supply --caddy and --php-cgi paths or add them to PATH.');
            }
            foreach ([$port,$fcgiPort] as $checkPort) {
                $socket = @stream_socket_server('tcp://' . ($checkPort === $port ? $address : '127.0.0.1') . ':' . $checkPort, $errno, $error);
                if (!$socket) {
                    throw new \RuntimeException('Port ' . $checkPort . ' is already occupied: ' . $error);
                }
                fclose($socket);
            }
            $env = ['APP_ENV' => 'prod','APP_DEBUG' => '0','APP_DATA_DIR' => $data,'APP_INSTANCE_ID' => $instance,
                'APP_BIND_ADDRESS' => $address === '127.0.0.1' ? $address : '127.0.0.1 ' . $address,'APP_HTTP_PORT' => (string) $port,'APP_FCGI_PORT' => (string) $fcgiPort,'APP_PUBLIC_DIR' => str_replace('\\', '/', $root . '/public'),
                'PHP_FCGI_MAX_REQUESTS' => '0'];
            $migration = new Process([PHP_BINARY,'bin/console','doctrine:migrations:migrate','--no-interaction'], $root, $env, null, 60);
            $migration->mustRun();
            $this->db->close();
            if ($this->db->fetchOne("SELECT value FROM settings WHERE name='lan_enabled'") === 'true') {
                $address = (string) $this->db->fetchOne("SELECT value FROM settings WHERE name='lan_address'");
                if (!\App\Security\NetworkPolicy::privateAddress($address) || !$this->db->fetchOne('SELECT COUNT(*) FROM owner_auth')) {
                    throw new \RuntimeException('Set up the owner locally and configure a private IPv4 address before enabling LAN.');
                }
            }
            $env['APP_BIND_ADDRESS'] = $address === '127.0.0.1' ? $address : '127.0.0.1 ' . $address;
            if ($address !== '127.0.0.1') {
                $socket = @stream_socket_server('tcp://' . $address . ':' . $port, $errno, $error);
                if (!$socket) {
                    throw new \RuntimeException('Cannot bind configured LAN address: ' . $error);
                }
                fclose($socket);
            }

            // Existing manually started consumers would defeat supervised ownership.
            foreach (['filesystem','metadata'] as $queue) {
                $probe = (new LockFactory(new FlockStore($data)))->createLock('worker-' . $queue);
                if (!$probe->acquire()) {
                    throw new \RuntimeException('Stop the existing ' . $queue . ' worker before launching the application.');
                }
                $probe->release();
            }
            $validate = new Process([$caddy,'validate','--config',$root . '/config/Caddyfile','--adapter','caddyfile'], $root, $env, null, 30);
            $validate->mustRun();
            if (is_file($stopFile)) {
                unlink($stopFile);
            }
            $commands = [
                'php-fastcgi' => [$cgi,'-b','127.0.0.1:' . $fcgiPort,'-d','cgi.force_redirect=0','-d','display_errors=0'],
                'filesystem' => [PHP_BINARY,'bin/console','messenger:consume','filesystem','--sleep=1','--no-interaction'],
                'metadata' => [PHP_BINARY,'bin/console','messenger:consume','metadata','--sleep=1','--no-interaction'],
                'http' => [$caddy,'run','--config',$root . '/config/Caddyfile','--adapter','caddyfile'],
            ];
            $logDir = $data . '/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0700, true);
            }
            foreach ($commands as $name => $command) {
                $log = $logDir . '/' . $name . '.log';
                $logger = new \App\Maintenance\RuntimeLog($log, [$_SERVER['APP_SECRET'],(new \App\Metadata\Credentials())->read()]);
                $logs[] = $logger;
                $process = new Process($command, $root, $env, null, null);
                $verbose = $this->db->fetchOne("SELECT value FROM settings WHERE name='log_level'") === 'verbose';
                $process->start(static function (string $type, string $buffer) use ($logger, $verbose): void {
                    if ($type === Process::ERR || $verbose) {
                        $logger->append($buffer);
                    }
                });
                $children[$name] = $process;
            }
            $state = ['instance' => $instance,'pid' => getmypid(),'port' => $port,'started_at' => gmdate(DATE_ATOM),'children' => array_map(fn(Process $p) => $p->getPid(), $children)];
            file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
            $stateWritten = true;
            $receipts = [];
            foreach (['filesystem','metadata'] as $queue) {
                $id = bin2hex(random_bytes(16));
                $receipts[] = $id;
                $this->bus->dispatch(new WorkerPing($id, $queue), [new TransportNamesStamp([$queue])]);
            }
            $ready = false;
            $deadline = microtime(true) + 30;
            $stop = false;
            if (function_exists('sapi_windows_set_ctrl_handler')) {
                sapi_windows_set_ctrl_handler(static function (int $event) use (&$stop): void { $stop = true; });
            }
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
                foreach ([SIGINT,SIGTERM] as $signal) {
                    pcntl_signal($signal, static function () use (&$stop): void { $stop = true; });
                }
            }
            while (!$stop) {
                clearstatcache(true, $stopFile);
                foreach ($children as $name => $process) {
                    // Drains log callbacks and detects early exits / crashed workers.
                    $process->getIncrementalOutput();
                    $process->getIncrementalErrorOutput();
                    if (!$process->isRunning()) {
                        throw new \RuntimeException($name . ' stopped. See ' . $logDir . '/' . $name . '.log');
                    }
                    $process->clearOutput();
                    $process->clearErrorOutput();
                }
                if (is_file($stopFile) && trim((string) file_get_contents($stopFile)) === $instance) {
                    break;
                }
                if (!$ready) {
                    $context = stream_context_create(['http' => ['timeout' => 1,'ignore_errors' => true]]);
                    $body = @file_get_contents('http://127.0.0.1:' . $port . '/health', false, $context);
                    $health = $body ? json_decode($body, true) : null;
                    $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM worker_receipts WHERE id IN (?,?)', $receipts);
                    if (($health['status'] ?? null) === 'ok' && $count === 2) {
                        if ($health['php'] !== PHP_VERSION || count($health['extensions'] ?? []) !== 9) {
                            throw new \RuntimeException('Web and CLI PHP runtimes/extensions differ.');
                        }
                        $this->checks->dispatch('backend_start');
                        $ready = true;
                        $output->writeln('Movie Library ready: http://' . $address . ':' . $port . ' (both workers verified)');
                        $output->writeln('Data: ' . $data . ' | Stop using the stop script or Ctrl+C.');
                    } elseif (microtime(true) > $deadline) {
                        throw new \RuntimeException('Startup health check timed out; inspect ' . $logDir);
                    }
                }
                usleep(250000);
            }
            return Command::SUCCESS;
        } finally {
            foreach (array_reverse($children) as $process) {
                $process->stop(3);
            }
            foreach ($logs as $logger) {
                $logger->flush();
            }
            if ($stateWritten) {
                $this->db->executeStatement("UPDATE worker_health SET state='stopped', heartbeat_at=? WHERE instance_id=?", [gmdate(DATE_ATOM),$instance]);
                if (is_file($stateFile)) {
                    unlink($stateFile);
                }
                if (is_file($stopFile)) {
                    unlink($stopFile);
                }
            }
            $lock->release();
        }
    }
    private function port(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1024 || (int) $value > 65535) {
            throw new \InvalidArgumentException('Ports must be integers between 1024 and 65535.');
        }
        return (int) $value;
    }
}
