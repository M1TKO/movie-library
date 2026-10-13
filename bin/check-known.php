<?php

declare(strict_types=1);
// Read-only known-path checks; no recursive traversal, discovery or probing.
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Location\{HostPath, WindowsPath};

function output(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    flush();
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$paths = new HostPath(new WindowsPath());
$root = $input['root'];
$handle = @opendir($root);
if ($handle === false) {
    output(['kind' => 'root','state' => is_dir($root) ? 'access_denied' : 'location_offline']);
    exit(0);
}
closedir($handle);
$real = realpath($root);
if ($real === false || $paths->normalize($real) !== $paths->normalize($root)) {
    output(['kind' => 'root','state' => 'check_failed']);
    exit(0);
}
output(['kind' => 'root','state' => 'available']);
foreach ($input['files'] as $file) {
    $directory = $root;
    $state = 'available';
    // Establish absence only by successfully reading each containing directory.
    foreach (explode('/', $file['relative_path']) as $part) {
        $handle = @opendir($directory);
        if ($handle === false) {
            $state = 'access_denied';
            break;
        }
        $found = false;
        while (($entry = readdir($handle)) !== false) {
            if (PHP_OS_FAMILY === 'Windows' ? mb_strtolower($entry) === mb_strtolower($part) : $entry === $part) {
                $found = true;
                break;
            }
        }
        closedir($handle);
        if (!$found) {
            $state = 'missing';
            break;
        }
        $directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $part;
        $resolved = realpath($directory);
        if (is_link($directory) || $resolved === false || $paths->normalize($resolved) !== $paths->normalize($directory)) {
            $state = 'check_failed';
            break;
        }
    }
    if ($state === 'available' && (!is_file($directory) || !is_readable($directory))) {
        $state = 'access_denied';
    }
    output(['kind' => 'file','id' => $file['id'],'state' => $state]);
}
output(['kind' => 'done']);
