<?php
// config.php — Unified configuration (reads .env with fallback defaults)
// Composer autoloader (mongodb/mongodb library)
require_once __DIR__ . '/vendor/autoload.php';

// ── Load .env ────────────────────────────────────────────────────
function _cfgEnvLoad(string $file): void
{
    if (!is_file($file) || !is_readable($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}
_cfgEnvLoad(__DIR__ . '/.env');

function _cfgEnv(string $key, string $default = ''): string { $v = getenv($key); return ($v !== false && $v !== '') ? $v : $default; }

// ── MongoDB Atlas Connection ─────────────────────────────────────
define('MONGODB_URI', _cfgEnv('MONGO_URI', 'mongodb+srv://vinothyokesh008009_db_user:T6AEVJBfBWlhYx8q@expense-tracker.hqmyhrg.mongodb.net/'));
define('DB_NAME',     _cfgEnv('DB_NAME', 'smart_transaction_control'));
define('ATLAS_CLUSTER', 'expense-tracker');

// ── Application ──────────────────────────────────────────────────
define('APP_NAME',    _cfgEnv('APP_NAME', 'Smart Transaction Control'));
define('APP_VERSION', '1.0.0');

// ── Base URL (auto-detect) ───────────────────────────────────────
$stcScheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$stcHost     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$stcDocRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$stcAppRoot  = rtrim(str_replace('\\', '/', __DIR__), '/');
$stcRel      = '';
if ($stcDocRoot !== '' && strpos($stcAppRoot, $stcDocRoot) === 0) {
    $stcRel = substr($stcAppRoot, strlen($stcDocRoot));
}
define('BASE_URL', $stcScheme . '://' . $stcHost . $stcRel . '/');
define('TIMEZONE', 'Asia/Kolkata');

// ── Security ─────────────────────────────────────────────────────
define('SESSION_TIMEOUT',   (int)_cfgEnv('SESSION_LIFETIME', '3600'));
define('MAX_LOGIN_ATTEMPTS', (int)_cfgEnv('MAX_LOGIN_ATTEMPTS', '5'));
define('LOCKOUT_TIME',      900);
define('PASSWORD_MIN_LENGTH', (int)_cfgEnv('PASSWORD_MIN_LENGTH', '8'));

// ── File Upload ──────────────────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 5242880);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf']);

// ── Python / Java Integration (optional — override in .env) ─────
define('PYTHON_API_URL', _cfgEnv('PYTHON_API_URL', ''));
define('JAVA_JAR_PATH',  __DIR__ . '/backend/java/dist/');

// ── Error Reporting ──────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// ── Timezone ─────────────────────────────────────────────────────
date_default_timezone_set(TIMEZONE);

// ── Start Session ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name('STC_SESSION');
    $cookieDomain = isset($_SERVER['HTTP_HOST'])
        ? strtolower(parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST))
        : '';
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path'     => '/',
        'domain'   => $cookieDomain,
        'secure'   => false,
        'httponly'  => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── MongoDB Connection Functions ──────────────────────────────────
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
    return $client ? $client->selectDatabase(DB_NAME) : null;
}

function getCollection($name) {
    $db = getMongoDB();
    return $db ? $db->selectCollection($name) : null;
}
