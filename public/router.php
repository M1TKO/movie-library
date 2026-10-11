<?php
// Development server router: expose only known public assets.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/app.css') {
    return false;
}
require __DIR__.'/index.php';
