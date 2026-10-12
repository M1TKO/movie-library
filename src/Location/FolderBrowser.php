<?php
declare(strict_types=1);
namespace App\Location;

use Symfony\Component\Process\{Process, PhpExecutableFinder};

final class FolderBrowser
{
    public function __construct(private readonly HostPath $paths) {}

    public function browse(string $path): array
    {
        $this->paths->normalize($path);
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', dirname(__DIR__,2).'/bin/browse-folder.php'], null, null, json_encode(['path'=>$path], JSON_THROW_ON_ERROR), 3);
        try {
            $process->mustRun();
            return json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['accessible'=>false,'children'=>[],'truncated'=>false];
        }
    }
}
