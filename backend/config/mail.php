<?php
declare(strict_types=1);

/**
 * Gmail SMTP Configuration for Secure Online Transaction System
 *
 * Credentials are read from (highest priority first):
 *   1. Real server environment variables
 *   2. A ".env" file in the project root (XAMPP friendly - PHP does not auto-read .env)
 *
 * Required variables (see .env.example):
 *   SMTP_USERNAME=yourapp@gmail.com
 *   SMTP_PASSWORD=your_16_char_app_password
 */

// --- Load ".env" from the project root so SMTP works on XAMPP without a dotenv library ---
function mailLoadEnv(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Strip surrounding quotes
        if (strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

mailLoadEnv((string)realpath(dirname(__DIR__, 2)) . '/.env');

// --- SMTP constants (with sensible defaults) ---
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Secure Online Transaction System');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');

function is_email_configured(): bool
{
    return SMTP_USERNAME !== '' && SMTP_PASSWORD !== '';
}