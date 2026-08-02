<?php
// Router for PHP built-in server
$path = $_SERVER['REQUEST_URI'];
$path = parse_url($path, PHP_URL_PATH);
if (is_file(__DIR__ . $path)) {
    return false; // serve static files
}
