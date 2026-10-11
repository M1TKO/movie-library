<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80200) { fwrite(STDERR, "PHP 8.2+ required\n"); exit(1); }
foreach (['ctype', 'iconv', 'pdo_sqlite', 'session', 'mbstring', 'dom', 'xml', 'xmlwriter'] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1); }
}
echo 'PHP '.PHP_VERSION.'; SQLite '.(new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn().PHP_EOL;
