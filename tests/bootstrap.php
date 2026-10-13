<?php

declare(strict_types=1);
// Always override inherited app paths so tests cannot open the owner's catalog.
$_SERVER['APP_DATA_DIR'] = $_ENV['APP_DATA_DIR'] = dirname(__DIR__) . '/var/test';
require dirname(__DIR__) . '/config/bootstrap.php';
$kernel = new App\Kernel('test', false);
$application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->setAutoExit(false);
$status = $application->run(new Symfony\Component\Console\Input\ArrayInput([
    'command' => 'doctrine:migrations:migrate', '--no-interaction' => true,
]), new Symfony\Component\Console\Output\NullOutput());
if ($status !== 0) {
    throw new RuntimeException('Test database migration failed.');
}
$kernel->shutdown();
