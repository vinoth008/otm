<?php
declare(strict_types=1);

// Unified API Router - Bridges MINI_PROJECT frontend to MPWT backend
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

// ── Response helpers ─────────────────────────────────────────────
function successResponse($data = null, $message = 'Success') {
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit;
}

function errorResponse($message = 'Error', $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ── Security helpers ─────────────────────────────────────────────
// Note: sanitizeInput / hashPassword / etc. may already be defined
// by the shared MPWT security.php loaded via database.php — guard
// every helper so the router never fatals with "cannot redeclare".
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($value) {
        if (is_array($value)) {
            return array_map('sanitizeInput', $value);
        }
        return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validateAmount')) {
    function validateAmount($amount) {
        return is_numeric($amount) && $amount > 0;
    }
}

if (!function_exists('validateDate')) {
    function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
    }
}

if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

function getJSONRequest() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

function requireActiveSession() {
    if (!isset($_SESSION['user_id'])) {
        errorResponse('Unauthorized. Please login.', 401);
    }
}

function requireRole($roles) {
    requireActiveSession();
    if (!in_array(getCurrentUserRole(), $roles, true)) {
        errorResponse('Access denied', 403);
    }
}

function isValidObjectId($id) {
    return preg_match('/^[a-f0-9]{24}$/i', (string)$id) === 1;
}

function phpDateToMongo($date = null) {
    $ts = $date ? strtotime($date) : time();
    return new MongoDB\BSON\UTCDateTime($ts * 1000);
}

function mongoDateToPHP($mongoDate) {
    if ($mongoDate instanceof MongoDB\BSON\UTCDateTime) {
        return $mongoDate->toDateTime();
    }
    return new DateTime();
}

function createNotification($userId, $type, $title, $message) {
    try {
        $collection = getCollection('notifications');
        if ($collection) {
            $collection->insertOne([
                'user_id' => new MongoDB\BSON\ObjectId($userId),
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'is_read' => false,
                'created_at' => phpDateToMongo(),
                'deleted_at' => null
            ]);
        }
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
    }
}

function logActivity($action, $userId, $details = []) {
    try {
        $collection = getCollection('activity_logs');
        if ($collection) {
            $collection->insertOne([
                'user_id' => $userId ? new MongoDB\BSON\ObjectId($userId) : null,
                'action' => $action,
                'details' => $details,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => phpDateToMongo()
            ]);
        }
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

// ── Route handling ───────────────────────────────────────────────
$module = $_GET['module'] ?? '';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($module) {
    case 'auth':
        require_once __DIR__ . '/auth.php';
        break;
    case 'transactions':
        require_once __DIR__ . '/transactions.php';
        break;
    case 'expenses':
        require_once __DIR__ . '/expenses.php';
        break;
    case 'budgets':
        require_once __DIR__ . '/budgets.php';
        break;
    case 'categories':
        require_once __DIR__ . '/categories.php';
        break;
    case 'reports':
        require_once __DIR__ . '/reports.php';
        break;
    case 'dashboard':
        require_once __DIR__ . '/dashboard.php';
        break;
    case 'users':
        require_once __DIR__ . '/users.php';
        break;
    case 'notifications':
        require_once __DIR__ . '/notifications.php';
        break;
    case 'settings':
        require_once __DIR__ . '/settings.php';
        break;
    case 'profile':
        require_once __DIR__ . '/profile.php';
        break;
    case 'complaints':
        require_once __DIR__ . '/complaints.php';
        break;
    case 'audit':
        require_once __DIR__ . '/audit.php';
        break;
    case 'notes':
        require_once __DIR__ . '/notes.php';
        break;
    case 'appointments':
        require_once __DIR__ . '/appointments.php';
        break;
    case 'wallets':
        require_once __DIR__ . '/wallets.php';
        break;
    case 'goals':
        require_once __DIR__ . '/goals.php';
        break;
    case 'reminders':
        require_once __DIR__ . '/reminders.php';
        break;
    case 'recurring':
        require_once __DIR__ . '/recurring.php';
        break;
    case 'analytics':
        require_once __DIR__ . '/analytics.php';
        break;
    default:
        errorResponse('Invalid module', 404);
}
