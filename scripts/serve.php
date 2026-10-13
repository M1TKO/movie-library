<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);

if (PHP_OS_FAMILY === 'Windows') {
    $command = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($root . '/scripts/Start-Dev.ps1');
} else {
    $command = 'bash ' . escapeshellarg($root . '/scripts/start-dev.sh');
}

if ($arguments !== []) {
    $command .= ' ' . implode(' ', array_map('escapeshellarg', $arguments));
}

passthru($command, $exitCode);
exit($exitCode);