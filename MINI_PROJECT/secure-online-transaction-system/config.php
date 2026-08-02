<?php
// config.php
// Composer autoloader (mongodb/mongodb library)
require_once __DIR__ . '/vendor/autoload.php';
// MongoDB Atlas Connection Configuration
define('MONGODB_URI', 'mongodb+srv://vinothyokesh008009_db_user:T6AEVJBfBWlhYx8q@expense-tracker.hqmyhrg.mongodb.net/');
define('DB_NAME', 'smart_transaction_control');
define('ATLAS_CLUSTER', 'expense-tracker');
// Application Configuration
define('APP_NAME', 'Smart Transaction Control');
define('APP_VERSION', '1.0.0');
// Base URL derived from the current request (works in any folder / port)
$stcScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$stcHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$stcDocRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$stcAppRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
$stcRel = '';
if ($stcDocRoot !== '' && strpos($stcAppRoot, $stcDocRoot) === 0) {
    $stcRel = substr($stcAppRoot, strlen($stcDocRoot));
}
define('BASE_URL', $stcScheme . '://' . $stcHost . $stcRel . '/');
define('TIMEZONE', 'Asia/Kolkata');
// Security Configuration
define('SESSION_TIMEOUT', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes
define('PASSWORD_MIN_LENGTH', 8);
// File Upload Configuration
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf']);
// Python & Java Integration
define('PYTHON_API_URL', 'http://localhost:5000/api/');
define('JAVA_JAR_PATH', __DIR__ . '/backend/java/dist/');
// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');
// Timezone
date_default_timezone_set(TIMEZONE);
// Start Session
if (session_status() === PHP_SESSION_NONE) {
    session_name('STC_SESSION');
    // Cookie domain must not include the port (invalid in Set-Cookie)
    $cookieDomain = isset($_SERVER['HTTP_HOST'])
        ? strtolower(parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST))
        : '';
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'domain' => $cookieDomain,
        'secure' => false, // Set true in production with HTTPS
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}
// MongoDB Connection Functions
function getMongoClient() {
    try {
        return new MongoDB\Client(MONGODB_URI);
    } catch (Exception $e) {
        error_log("MongoDB Connection Error: " . $e->getMessage());
        return null;
    }
}
function getMongoDB() {
    $client = getMongoClient();
    if ($client) {
        return $client->selectDatabase(DB_NAME);
    }
    return null;
}
// Get Collections
function getCollection($name) {
    $db = getMongoDB();
    if ($db) {
        return $db->selectCollection($name);
    }
    return null;
}
?>
