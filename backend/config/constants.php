<?php
declare(strict_types=1);

// MongoDB Atlas Connection (unified with MPWT)
define('MONGODB_URI', 'mongodb+srv://vinothyokesh008009_db_user:T6AEVJBfBWlhYx8q@expense-tracker.hqmyhrg.mongodb.net/');
define('DB_NAME', 'smart_transaction_control');

// Security Configuration
define('HASH_COST', 12);
define('OTP_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('SESSION_TIMEOUT_MINUTES', 60);
define('SESSION_TIMEOUT', 3600); // 1 hour
define('LOCKOUT_TIME', 900); // 15 minutes
define('PASSWORD_MIN_LENGTH', 8);

// File Upload Configuration
define('UPLOAD_DIR', __DIR__ . '/../../uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf']);

// Application
define('APP_NAME', 'Secure Online Transaction System');
define('APP_VERSION', '1.0.0');
define('TIMEZONE', 'Asia/Kolkata');

// Base URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $scheme . '://' . $host . '/');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/error.log');

// Timezone
date_default_timezone_set(TIMEZONE);

// Start Session
if (session_status() === PHP_SESSION_NONE) {
    session_name('SOT_SESSION');
    $cookieDomain = isset($_SERVER['HTTP_HOST'])
        ? strtolower(parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST))
        : '';
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'domain' => $cookieDomain,
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}
