<?php
declare(strict_types=1);
namespace App\Command;
use App\Maintenance\Backups;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputArgument};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\{Question,ConfirmationQuestion};
#[AsCommand(name:'app:restore',description:'Offline local-owner restore with a pre-restore backup')]
final class RestoreCommand extends Command
{
    public function __construct(private readonly Backups $backups) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('directory',InputArgument::REQUIRED,'Backup directory'); }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        if (!$input->isInteractive()) { throw new \RuntimeException('Restore requires an interactive local owner terminal.'); }
        $helper=$this->getHelper('question');
        if (!$helper->ask($input,$output,new ConfirmationQuestion('Replace catalog, preserving current credentials? [y/N] ',false))) { return self::SUCCESS; }
        $q=new Question('Current owner password: '); $q->setHidden(true); $q->setHiddenFallback(false);
        $before=$this->backups->restore($input->getArgument('directory'),(string)$helper->ask($input,$output,$q));
        $output->writeln('Restored. Pre-restore backup: '.$before.'. Restart the application and retry interrupted jobs manually.'); return self::SUCCESS;
    }
}
