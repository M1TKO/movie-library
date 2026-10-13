<?php
declare(strict_types=1);
namespace App\Command;
use App\Maintenance\Backups;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:backup',description:'Create a consistent catalog backup without credentials')]
final class BackupCommand extends Command
{
    public function __construct(private readonly Backups $backups) { parent::__construct(); }
    protected function execute(InputInterface $input,OutputInterface $output): int { $output->writeln($this->backups->create()); return self::SUCCESS; }
}
