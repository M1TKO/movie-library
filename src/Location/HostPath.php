<?php

declare(strict_types=1);

namespace App\Location;

/** Paths refer to the backend host, never the browser's operating system. */
final class HostPath
{
    public function __construct(private readonly WindowsPath $windows, private readonly string $platform = PHP_OS_FAMILY) {}

    public function normalize(string $path): string
    {
        if ($this->platform === 'Windows') {
            return $this->windows->normalize($path);
        }
        if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Use an absolute folder path on this host, such as /mnt/media/movies.');
        }
        // Preserve case, whitespace and backslashes: all are meaningful on Linux.
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            // Resolving .. lexically would be incorrect if a preceding part is a symlink.
            if ($part === '..') {
                throw new \InvalidArgumentException('Enter a path without parent-directory (..) segments.');
            }
            $parts[] = $part;
        }
        return '/' . implode('/', $parts);
    }

    public function overlaps(string $a, string $b): bool
    {
        if ($this->platform === 'Windows') {
            return $this->windows->overlaps($a, $b);
        }
        $a = rtrim($a, '/') . '/';
        $b = rtrim($b, '/') . '/';
        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }
}
