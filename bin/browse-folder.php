<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Location\{HostPath, WindowsPath};

try {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $paths = new HostPath(new WindowsPath());
    $path = $input['path'];
    $normalized = $paths->normalize($path);
    $real = realpath($path);
    if ($real === false || is_link($path) || $paths->normalize($real) !== $normalized) { throw new RuntimeException(); }
    $handle = @opendir($path);
    if ($handle === false) { throw new RuntimeException(); }
    $children = []; $truncated = false; $examined = 0;
    try {
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') { continue; }
            if (++$examined > 5000 || count($children) >= 200) { $truncated = true; break; }
            $child = rtrim($path, '/\\').DIRECTORY_SEPARATOR.$name;
            if (!is_link($child) && is_dir($child)) {
                $childReal = realpath($child);
                if ($childReal !== false && $paths->normalize($childReal) === $paths->normalize($child)) { $children[] = ['name'=>$name,'path'=>$child]; }
            }
        }
    } finally { closedir($handle); }
    usort($children, static fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name']));
    echo json_encode(['accessible'=>true,'children'=>$children,'truncated'=>$truncated], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable) {
    echo json_encode(['accessible'=>false,'children'=>[],'truncated'=>false]); exit(2);
}
