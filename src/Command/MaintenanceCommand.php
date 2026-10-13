<?php
declare(strict_types=1);
namespace App\Command;
use App\Maintenance\Preferences;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:maintenance',description:'Prune expired catalog backups and rotated runtime logs; preserve latest backup and history')]
final class MaintenanceCommand extends Command
{
    public function __construct(private readonly Preferences $preferences) { parent::__construct(); }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $data=$_SERVER['APP_DATA_DIR']; $cutoff=time()-86400*(int)$this->preferences->values()['retention_days']; $removed=0;
        $files=glob($data.'/logs/*.log.[123]')?:[];
        foreach ($files as $path) { if (!is_link($path) && filemtime($path)<$cutoff) { unlink($path); ++$removed; } }
        $backups=glob($data.'/backups/*/manifest.json')?:[]; rsort($backups); array_shift($backups);
        foreach ($backups as $path) {
            $dir=dirname($path);
            if (is_link($dir) || is_link($path) || filemtime($path)>=$cutoff) { continue; }
            // Delete only the two known backup artifacts, never arbitrary directory contents.
            if (is_file($dir.'/catalog.sqlite') && !is_link($dir.'/catalog.sqlite')) { unlink($dir.'/catalog.sqlite'); }
            unlink($path); if (count(scandir($dir))===2) { rmdir($dir); } ++$removed;
        }
        $output->writeln('Removed '.$removed.' expired backups/logs. Latest backup and catalog history retained.'); return self::SUCCESS;
    }
}
