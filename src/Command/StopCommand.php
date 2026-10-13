<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[AsCommand(name: 'app:stop', description: 'Ask the owned application supervisor to stop its child processes')]
final class StopCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = $_SERVER['APP_DATA_DIR'];
        $lock = (new LockFactory(new FlockStore($data)))->createLock('application-supervisor');
        if ($lock->acquire()) {
            $lock->release();
            $output->writeln('No active supervisor for this data directory.');
            return 0;
        }
        $state = json_decode((string) @file_get_contents($data . '/runtime.json'), true);
        if (!isset($state['instance'])) {
            throw new \RuntimeException('Supervisor is still starting. Retry shortly.');
        }
        file_put_contents($data . '/stop-request', $state['instance']);
        $deadline = microtime(true) + 20;
        do {
            usleep(200000);
            if ($lock->acquire()) {
                $lock->release();
                $output->writeln('Movie Library stopped.');
                return 0;
            }
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Stop requested but supervisor did not finish within 20 seconds. Inspect local logs.');
    }
}
