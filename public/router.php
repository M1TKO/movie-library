<?php

declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($path, ['/app.css','/app.js','/tmdb-logo.svg','/vendor/htmx/htmx-2.0.8.min.js'], true)) {
    return false;
}
require __DIR__ . '/index.php';
