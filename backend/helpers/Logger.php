<?php
declare(strict_types=1);

function log_event(string $file, string $message): void
{
    $dir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($dir . '/' . $file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}