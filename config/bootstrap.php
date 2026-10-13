<?php
declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

umask(0077);
require dirname(__DIR__).'/vendor/autoload.php';
// PHP web SAPIs can omit process environment variables from $_SERVER.
foreach (['APP_ENV', 'APP_DEBUG', 'APP_DATA_DIR', 'APP_SECRET'] as $key) {
    $value = getenv($key);
    if ($value !== false && !isset($_SERVER[$key])) { $_SERVER[$key] = $_ENV[$key] = $value; }
}
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$env = $_SERVER['APP_ENV'] ?? 'dev';
$defaultData = PHP_OS_FAMILY === 'Windows'
    ? (getenv('LOCALAPPDATA') ?: dirname(__DIR__).'/var').'/MovieLibrary'
    : ((getenv('XDG_DATA_HOME') ?: ((getenv('HOME') ?: dirname(__DIR__).'/var').'/.local/share')).'/movie-library');
$data = $_SERVER['APP_DATA_DIR'] ?? ($env === 'test'
    ? dirname(__DIR__).'/var/test'
    : $defaultData);
if (!is_dir($data) && !mkdir($data, 0700, true) && !is_dir($data)) {
    throw new RuntimeException('Cannot create application data directory. Set APP_DATA_DIR to a writable local directory.');
}
$data = realpath($data);
if (PHP_OS_FAMILY !== 'Windows') { chmod($data,0700); }
if (PHP_OS_FAMILY === 'Windows') { $data = str_replace('\\', '/', $data); }
$secretPath = $data.'/app.secret';
if (!is_file($secretPath)) {
    $handle = @fopen($secretPath, 'x');
    if ($handle !== false) {
        fwrite($handle, bin2hex(random_bytes(32)));
        fclose($handle);
        @chmod($secretPath, 0600);
    }
}
$values = ['APP_DATA_DIR' => $data, 'DATABASE_PATH' => $data.'/library.sqlite',
    'APP_SECRET' => $_SERVER['APP_SECRET'] ?? trim((string) file_get_contents($secretPath))];
foreach ($values as $key => $value) {
    $_SERVER[$key] = $_ENV[$key] = $value;
    putenv($key.'='.$value);
}
