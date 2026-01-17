<?php
// Simple PHP built-in server router
// Run with: php -S localhost:8000 server.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

require_once __DIR__ . '/api.php';
?>
