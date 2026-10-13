<?php

declare(strict_types=1);

// Isolated, read-only filesystem process. No application bootstrap or database access.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Catalog\Probe;
use App\Discovery\ScanOptions;
use App\Location\{HostPath, WindowsPath};

function emit(array $record): void
{
    echo json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    flush();
}

try {
    $options = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $paths = new HostPath(new WindowsPath());
    $root = $options['root'];
    $normalized = $paths->normalize($root);
    $real = realpath($root);
    if ($real === false || !is_dir($root)) {
        emit(['kind' => 'error','code' => 'root_unavailable','message' => 'The root is offline, absent, or inaccessible.']);
        exit(2);
    }
    if (is_link($root) || $paths->normalize($real) !== $normalized) {
        emit(['kind' => 'error','code' => 'linked_root','message' => 'Symbolic links, junctions, and paths through linked directories are not traversed.']);
        exit(2);
    }
    $start = microtime(true);
    $visited = 0;
    $errors = 0;
    $pending = [];
    $probeService = new Probe();
    $concurrency = max(1, min(2, (int) ($options['probe_concurrency'] ?? 1)));
    register_shutdown_function(static function () use (&$pending): void { foreach ($pending as $entry) { if ($entry['task'][0]->isRunning()) { $entry['task'][0]->stop(0); } } });
    $finish = static function () use (&$pending, $probeService): void {
        $entry = array_shift($pending);
        $probe = $probeService->finish($entry['task']);
        $path = $entry['path'];
        $before = $entry['before'];
        clearstatcache(true, $path);
        $after = @stat($path);
        if ($after === false || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']) {
            $probe = ['probe_state' => 'retry','probe_error' => 'The file changed during inspection. Scan again when copying has finished.','probed_at' => gmdate(DATE_ATOM)];
        }
        emit(['kind' => 'file','path' => $path,'relative' => $entry['relative'],'size' => $before['size'],'mtime' => $before['mtime'],'probe' => $probe]);
    };
    $stack = [[$root, '']];
    while ($stack) {
        [$directory, $relativeDirectory] = array_pop($stack);
        $handle = @opendir($directory);
        if ($handle === false) {
            ++$errors;
            emit(['kind' => 'error','code' => 'directory_unreadable','message' => 'Cannot enumerate a directory: ' . $directory]);
            continue;
        }
        if ($relativeDirectory === '') {
            emit(['kind' => 'accessible']);
        }
        $entries = [];
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (++$visited > 100000 || microtime(true) - $start > 290) {
                    emit(['kind' => 'error','code' => 'scan_limit','message' => 'Root operation reached its entry or time limit; partial discoveries were retained.']);
                    exit(3);
                }
                $entries[] = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($name) : $name;
                $relative = $relativeDirectory === '' ? $name : $relativeDirectory . '/' . $name;
                if (ScanOptions::excluded($relative, $options['exclusions'])) {
                    continue;
                }
                $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
                if (is_link($path)) {
                    continue;
                }
                $resolved = realpath($path);
                if ($resolved === false) {
                    ++$errors;
                    emit(['kind' => 'error','code' => 'entry_unreadable','message' => 'Cannot resolve an entry: ' . $path]);
                    continue;
                }
                if ($paths->normalize($resolved) !== $paths->normalize($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if ($options['recursive']) {
                        $stack[] = [$path, $relative];
                    }
                    continue;
                }
                if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $options['extensions'], true) || !is_file($path)) {
                    continue;
                }
                $before = @stat($path);
                if ($before === false) {
                    ++$errors;
                    emit(['kind' => 'error','code' => 'stat_failed','message' => 'Cannot inspect an entry: ' . $path]);
                    continue;
                }
                $key = $paths->normalize($path);
                $known = $options['known'][$key] ?? null;
                $probe = null;
                if (!$known || (int) $known['size_bytes'] !== $before['size'] || (int) $known['modified_at'] !== $before['mtime'] || $known['probe_state'] !== 'complete') {
                    try {
                        $pending[] = ['path' => $path,'relative' => $relative,'before' => $before,'task' => $probeService->start($path, $options['ffprobe'])];
                        if (count($pending) >= $concurrency) {
                            $finish();
                        }
                        continue;
                    } catch (Throwable) {
                        $probe = ['probe_state' => 'failed','probe_error' => 'Inspection could not start. Check FFprobe.','probed_at' => gmdate(DATE_ATOM)];
                    }
                }
                emit(['kind' => 'file','path' => $path,'relative' => $relative,'size' => $before['size'],'mtime' => $before['mtime'],'probe' => $probe]);
            }
            while ($pending) {
                $finish();
            }
            emit(['kind' => 'directory','relative' => PHP_OS_FAMILY === 'Windows' ? mb_strtolower($relativeDirectory) : $relativeDirectory,'entries' => $entries]);
        } finally {
            closedir($handle);
        }
    }
    emit(['kind' => 'done','errors' => $errors]);
    exit($errors ? 2 : 0);
} catch (Throwable $e) {
    emit(['kind' => 'error','code' => 'operation_failed','message' => 'The filesystem operation could not be completed.']);
    exit(2);
}
