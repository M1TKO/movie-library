<?php
// Development server router: expose only known public assets.
if (in_array(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), ['/app.css', '/tmdb-logo.svg', '/vendor/htmx/htmx-2.0.8.min.js'], true)) {
    return false;
}
// PHP's development server can otherwise infer an asset/dotted URL as the script base.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require __DIR__.'/index.php';
