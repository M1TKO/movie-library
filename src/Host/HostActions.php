<?php

declare(strict_types=1);

namespace App\Host;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;

final class HostActions
{
    public function __construct(private readonly Connection $db, private readonly \App\Location\HostPath $paths) {}
    public function desktop(): bool
    {
        if (getenv('MOVIE_LIBRARY_INTERACTIVE_DESKTOP') !== '1') {
            return false;
        }
        return PHP_OS_FAMILY === 'Windows' ? ((bool) getenv('SESSIONNAME') && strcasecmp((string) getenv('SESSIONNAME'), 'Services') !== 0) : (bool) (getenv('DISPLAY') ?: getenv('WAYLAND_DISPLAY'));
    }
    public function enabled(): bool
    {
        return $this->desktop() && $this->db->fetchOne("SELECT value FROM settings WHERE name='host_actions_enabled'") === 'true';
    }
    public function command(int $id, string $action): array
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('Host actions are disabled or no interactive desktop is configured.');
        }
        if (!in_array($action, ['play','folder'], true)) {
            throw new \InvalidArgumentException('Invalid host action.');
        }
        $file = $this->db->fetchAssociative('SELECT * FROM media_files WHERE id=?', [$id]);
        if (!$file || $file['check_outcome'] !== 'available') {
            throw new \RuntimeException('Only cataloged available files can be opened. Check availability and retry.');
        }
        $path = $file['original_path'];
        $this->paths->normalize($path);
        $target = $action === 'folder' ? dirname($path) : $path;
        if (PHP_OS_FAMILY === 'Windows') {
            // LiteralPath prevents wildcard expansion; the catalog path is a quoted string, never shell code.
            $script = "Invoke-Item -LiteralPath '" . str_replace("'", "''", $target) . "' -ErrorAction Stop";
            return ['powershell.exe','-NoProfile','-NonInteractive','-WindowStyle','Hidden','-EncodedCommand',base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))];
        }
        return ['xdg-open',$target];
    }
    public function launch(int $id, string $action): void
    {
        $process = new Process($this->command($id, $action), null, null, null, 5);
        try {
            $process->mustRun();
        } catch (\Throwable) {
            throw new \RuntimeException('The host could not open the item. Check the desktop session and file association, then retry.');
        }
    }
}
