<?php
declare(strict_types=1);
namespace App\MessageHandler;

use App\Catalog\CatalogWriter;
use App\Message\Discover;
use App\Worker\WorkerLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final class DiscoverHandler
{
    public function __construct(private readonly Connection $db, private readonly CatalogWriter $catalog, private readonly WorkerLifecycle $worker,
        private readonly float $rootTimeout = 300.0, private readonly float $runTimeout = 1800.0, private readonly ?\App\Availability\DiscoveryReconciler $reconciler = null) {}

    public function __invoke(Discover $message): void
    {
        $lock = (new LockFactory(new FlockStore($_SERVER['APP_DATA_DIR'])))->createLock('filesystem-job');
        if (!$lock->acquire()) { throw new \RuntimeException('Another filesystem job is active.'); }
        $owner = bin2hex(random_bytes(16));
        try {
            $run = $this->db->fetchAssociative('SELECT * FROM runs WHERE id=?', [$message->runId]);
            if (!$run || $run['state'] !== 'queued') { return; }
            if ($run['cancel_requested']) { $this->finish($message->runId, 'cancelled', 'Cancelled before discovery began.'); return; }
            $this->db->update('runs', ['state'=>'running','owner_token'=>$owner,'started_at'=>gmdate(DATE_ATOM),'heartbeat_at'=>gmdate(DATE_ATOM)], ['id'=>$message->runId]);
            $options = json_decode($run['options'], true, 512, JSON_THROW_ON_ERROR);
            $started = microtime(true);
            $errors = false; $verified = [];
            foreach ($options['roots'] as $root) {
                if ($this->cancelled($message->runId)) { $this->finish($message->runId, 'cancelled', 'Cancelled; completed discoveries were retained.'); return; }
                if (microtime(true) - $started >= $this->runTimeout) {
                    $this->finish($message->runId, 'completed_with_errors', 'The 30 minute run limit was reached. Partial discoveries were retained; scan remaining locations separately.'); return;
                }
                $current = $this->db->fetchAssociative('SELECT * FROM locations WHERE id=?', [$root['id']]);
                if (!$current || !$current['enabled'] || $current['normalized_root'] !== $root['normalized_root'] || $current['recursive'] !== $root['recursive'] || $current['exclusions'] !== $root['exclusions']) {
                    $this->rootResult($message->runId, $root['id'], ['enumeration_outcome'=>'skipped','error_code'=>'scope_changed','error_reason'=>'Location was removed, disabled or changed after this scan was queued.']);
                    $errors = true; continue;
                }
                $errors = !$this->scanRoot($message->runId, $owner, $root, $options, $verified, min($this->rootTimeout, $this->runTimeout - (microtime(true) - $started))) || $errors;
            }
            if ($this->cancelled($message->runId)) { $this->finish($message->runId, 'cancelled', 'Cancelled; completed discoveries were retained.'); return; }
            ($this->reconciler ?? new \App\Availability\DiscoveryReconciler($this->db, new \App\Availability\AvailabilityRecorder($this->db)))->reconcile($message->runId, $verified, function () use ($message): void {
                $this->worker->heartbeat();
                static $lastBeat = 0;
                if (time() > $lastBeat) {
                    $this->db->update('runs', ['heartbeat_at'=>gmdate(DATE_ATOM)], ['id'=>$message->runId]);
                    $lastBeat = time();
                }
            });
            if ($this->cancelled($message->runId)) { $this->finish($message->runId, 'cancelled', 'Cancelled; committed results were retained.'); return; }
            $errors = $errors || (int)$this->db->fetchOne('SELECT uncheckable FROM runs WHERE id=?', [$message->runId]) > 0;
            $this->finish($message->runId, $errors ? 'completed_with_errors' : 'completed', $errors ? 'Discovery finished with errors. Partial discoveries were retained; inspect root and file results.' : 'Discovery finished. Missing transitions require successful enumeration evidence from every enabled membership.');
        } catch (\Throwable $e) {
            $this->db->executeStatement("UPDATE runs SET state='failed', finished_at=?, summary=? WHERE id=? AND owner_token=? AND state='running'", [gmdate(DATE_ATOM),'Discovery failed. Committed results were retained; retry from scan history.',$message->runId,$owner]);
            throw $e;
        } finally { $lock->release(); }
    }

    private function scanRoot(string $runId, string $owner, array $root, array $options, array &$verified, float $timeout): bool
    {
        $known = [];
        foreach ($this->db->fetchAllAssociative('SELECT normalized_path,size_bytes,modified_at,probe_state FROM media_files') as $file) { $known[$file['normalized_path']] = $file; }
        $input = ['root'=>$root['root_path'],'recursive'=>(bool)$root['recursive'],'exclusions'=>json_decode($root['exclusions'], true, 512, JSON_THROW_ON_ERROR),
            'extensions'=>$options['extensions'],'ffprobe'=>$options['ffprobe'],'known'=>$known];
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/discover-root.php'], dirname(__DIR__, 2), null, json_encode($input, JSON_THROW_ON_ERROR), $timeout);
        $directories = [];
        $buffer = ''; $done = false; $errors = false; $lastBeat = 0.0;
        $this->rootResult($runId, $root['id'], ['enumeration_outcome'=>'running']);
        $consume = function (string $chunk) use (&$buffer, &$done, &$errors, &$directories, $runId, $root): void {
            $buffer .= $chunk;
            if (strlen($buffer) > 2 * 1024 * 1024) { throw new \RuntimeException('Filesystem output limit exceeded.'); }
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline); $buffer = substr($buffer, $newline + 1);
                $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if ($record['kind'] === 'file') {
                    $this->catalog->record($runId, $root, $record);
                    if (isset($record['probe']['probe_state']) && $record['probe']['probe_state'] !== 'complete') { $errors = true; }
                } elseif ($record['kind'] === 'directory') {
                    $directories[$record['relative']] = array_fill_keys($record['entries'], true);
                } elseif ($record['kind'] === 'accessible') {
                    $this->rootResult($runId, $root['id'], ['accessibility'=>'accessible']);
                } elseif ($record['kind'] === 'error') {
                    $errors = true;
                    $this->rootResult($runId, $root['id'], ['error_code'=>$record['code'],'error_reason'=>$record['message']]);
                } elseif ($record['kind'] === 'done') { $done = true; }
            }
        };
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $consume($process->getIncrementalOutput());
                $process->clearOutput(); $process->clearErrorOutput();
                if (microtime(true) - $lastBeat >= 1) {
                    $this->db->executeStatement('UPDATE runs SET heartbeat_at=? WHERE id=? AND owner_token=?', [gmdate(DATE_ATOM),$runId,$owner]);
                    $this->worker->heartbeat();
                    $lastBeat = microtime(true);
                    if ($this->cancelled($runId)) {
                        $process->stop(0);
                        $this->rootResult($runId, $root['id'], ['enumeration_outcome'=>'cancelled']); return false;
                    }
                }
                usleep(50000);
            }
            $consume($process->getIncrementalOutput());
            if (!$done || !$process->isSuccessful()) { $errors = true; }
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $errors = true;
            $this->rootResult($runId, $root['id'], ['error_code'=>'timeout','error_reason'=>'Root operation exceeded its time limit. Partial discoveries were retained.']);
        } finally {
            if ($process->isRunning()) { $process->stop(0); }
        }
        $result = $this->db->fetchAssociative('SELECT * FROM run_locations WHERE run_id=? AND location_id=?', [$runId,$root['id']]);
        if (!$done && !$result['error_code']) {
            $this->rootResult($runId, $root['id'], ['error_code'=>'incomplete','error_reason'=>'Filesystem subprocess did not finish. Partial discoveries were retained.']);
        }
        if ($done) { $verified[$root['id']] = ['root'=>$root,'directories'=>$directories]; }
        $this->rootResult($runId, $root['id'], ['enumeration_outcome'=>$errors ? 'completed_with_errors' : 'completed']);
        $result = $this->db->fetchAssociative('SELECT * FROM run_locations WHERE run_id=? AND location_id=?', [$runId,$root['id']]);
        $this->db->update('locations', ['last_scan_at'=>gmdate(DATE_ATOM),'status'=>$result['accessibility'] === 'accessible' ? 'accessible' : 'check_failed','last_error'=>$result['error_reason']], ['id'=>$root['id']]);
        return !$errors;
    }

    private function cancelled(string $id): bool { return (bool)$this->db->fetchOne('SELECT cancel_requested FROM runs WHERE id=?', [$id]); }
    private function rootResult(string $run, int|string $location, array $values): void { $this->db->update('run_locations', $values, ['run_id'=>$run,'location_id'=>$location]); }
    private function finish(string $id, string $state, string $summary): void
    {
        $this->db->update('runs', ['state'=>$state,'finished_at'=>gmdate(DATE_ATOM),'heartbeat_at'=>gmdate(DATE_ATOM),'summary'=>$summary], ['id'=>$id]);
        $this->db->executeStatement("UPDATE run_locations SET enumeration_outcome=? WHERE run_id=? AND enumeration_outcome IN ('queued','running')", [$state === 'completed_with_errors' ? 'skipped' : $state,$id]);
    }
}
