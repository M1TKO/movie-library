<?php
declare(strict_types=1);
namespace App\Location;

final class WindowsPath
{
    /** Lexical identity only: never touches a potentially offline share. */
    public function normalize(string $path): string
    {
        $path = str_replace('/', '\\', trim($path));
        if ($path === '' || preg_match('/[\x00-\x1f<>"|?*]/u', $path)) {
            throw new \InvalidArgumentException('Enter a valid absolute Windows folder path.');
        }
        if (preg_match('/^[a-z]:\\\\/i', $path)) {
            $prefix = substr($path, 0, 3);
            $rest = substr($path, 3);
        } elseif (preg_match('/^\\\\\\\\([^\\\\]+)\\\\([^\\\\]+)(?:\\\\(.*))?$/u', $path, $match)) {
            $prefix = '\\\\'.$match[1].'\\'.$match[2].'\\';
            $rest = $match[3] ?? '';
        } else {
            throw new \InvalidArgumentException('Use an absolute drive path (D:\\Movies) or UNC share (\\\\server\\share).');
        }
        $parts = [];
        foreach (explode('\\', $rest) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') {
                if ($parts === []) { throw new \InvalidArgumentException('The path cannot go above its root.'); }
                array_pop($parts);
                continue;
            }
            if (str_contains($part, ':') || rtrim($part, '. ') !== $part) {
                throw new \InvalidArgumentException('Folder names cannot contain colons or end with spaces or dots.');
            }
            $parts[] = $part;
        }
        return mb_strtolower($prefix.implode('\\', $parts), 'UTF-8');
    }
    public function overlaps(string $a, string $b): bool
    {
        $a = rtrim($a, '\\').'\\';
        $b = rtrim($b, '\\').'\\';
        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }
}
