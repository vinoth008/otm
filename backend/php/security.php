<?php
// backend/php/security.php
/**
 * Security Utilities for Smart Transaction Control
 * Handles input validation, sanitization, XSS prevention, CSRF protection
 */
// Prevent direct access
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
/**
 * Sanitize input string
 * @param string $data
 * @return string
 */
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}
/**
 * Validate email format
 * @param string $email
 * @return bool
 */
if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
/**
 * Validate password strength
 * @param string $password
 * @return array ['valid' => bool, 'errors' => array]
 */
function validatePasswordStrength($password) {
    $errors = [];
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    if (!preg_match('/[@$!%*?&#]/', $password)) {
        $errors[] = 'Password must contain at least one special character (@$!%*?&#)';
    }
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}
/**
 * Validate phone number (Indian format)
 * @param string $phone
 * @return bool
 */
function validatePhone($phone) {
    return preg_match('/^[6-9]\d{9}$/', preg_replace('/[^0-9]/', '', $phone));
}
/**
 * Validate amount
 * @param mixed $amount
 * @return bool
 */
if (!function_exists('validateAmount')) {
    function validateAmount($amount) {
        return is_numeric($amount) && $amount >= 0;
    }
}
/**
 * Validate date format (YYYY-MM-DD)
 * @param string $date
 * @return bool
 */
if (!function_exists('validateDate')) {
    function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    // Mirror token in a cookie so the frontend can read it (getCookie('csrf_token'))
    if (!isset($_COOKIE['csrf_token']) || $_COOKIE['csrf_token'] !== $_SESSION['csrf_token']) {
        setcookie('csrf_token', $_SESSION['csrf_token'], [
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Strict'
        ]);
        $_COOKIE['csrf_token'] = $_SESSION['csrf_token'];
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
/**
 * Regenerate CSRF token
 * @return string
 */
function regenerateCSRFToken() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
/**
 * Get CSRF token hidden input field
 * @return string
 */
function getCSRFHiddenField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}
/**
 * Prevent XSS - Output escaping
 * @param string $data
 * @return string
 */
function e($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}
/**
 * Validate and sanitize file upload
 * @param array $file
 * @return array ['success' => bool, 'error' => string, 'filename' => string]
 */
function validateFileUpload($file) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'error' => 'File upload failed',
            'filename' => null
        ];
    }
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        return [
            'success' => false,
            'error' => 'File size exceeds maximum limit of ' . (MAX_FILE_SIZE / 1048576) . 'MB',
            'filename' => null
        ];
    }
    // Check file extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return [
            'success' => false,
            'error' => 'Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS),
            'filename' => null
        ];
    }
    // Check MIME type
    $allowedMime = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf'
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, $allowedMime)) {
        return [
            'success' => false,
            'error' => 'Invalid file format',
            'filename' => null
        ];
    }
    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $ext;
    return [
        'success' => true,
        'error' => null,
        'filename' => $filename
    ];
}
/**
 * Get user IP address
 * @return string
 */
function getUserIP() {
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}
/**
 * Get user agent
 * @return string
 */
function getUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}
/**
 * Log activity
 * @param string $action
 * @param mixed $userId
 * @param array $details
 */
function logActivity($action, $userId = null, $details = []) {
    $collection = getCollection('activity_logs');
    if ($collection) {
        $collection->insertOne([
            'user_id' => $userId ? new MongoDB\BSON\ObjectId($userId) : null,
            'action' => $action,
            'ip_address' => getUserIP(),
            'user_agent' => getUserAgent(),
            'timestamp' => new MongoDB\BSON\UTCDateTime(),
            'details' => $details
        ]);
    }
}
/**
 * Rate limiting - Check if user exceeded limit
 * @param string $action
 * @param int $limit
 * @param int $timeWindow
 * @return bool
 */
function checkRateLimit($action, $limit = 5, $timeWindow = 300) {
    $ip = getUserIP();
    $key = "rate_limit_{$action}_{$ip}";
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'count' => 1,
            'reset_time' => time() + $timeWindow
        ];
        return true;
    }
    if (time() > $_SESSION[$key]['reset_time']) {
        $_SESSION[$key] = [
            'count' => 1,
            'reset_time' => time() + $timeWindow
        ];
        return true;
    }
    if ($_SESSION[$key]['count'] >= $limit) {
        return false;
    }
    $_SESSION[$key]['count']++;
    return true;
}
/**
 * Hash password
 * @param string $password
 * @return string
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}
/**
 * Verify password
 * @param string $password
 * @param string $hash
 * @return bool
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}
/**
 * Generate secure random string
 * @param int $length
 * @return string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}
/**
 * Validate MongoDB ObjectId
 * @param string $id
 * @return bool
 */
function isValidObjectId($id) {
    return preg_match('/^[a-f\d]{24}$/i', $id);
}
/**
 * Convert MongoDB UTCDateTime to PHP DateTime
 * @param MongoDB\BSON\UTCDateTime $date
 * @return DateTime
 */
function mongoDateToPHP($date) {
    return $date->toDateTime();
}
/**
 * Convert PHP DateTime / date string to MongoDB UTCDateTime
 * @param DateTimeInterface|string|null $date
 * @return MongoDB\BSON\UTCDateTime
 */
function phpDateToMongo($date = null) {
    if ($date instanceof DateTimeInterface) {
        return new MongoDB\BSON\UTCDateTime($date->getTimestamp() * 1000);
    }
    if (is_string($date) && $date !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        if (!$parsed) {
            $parsed = DateTime::createFromFormat('Y-m-d', $date);
        }
        if ($parsed) {
            return new MongoDB\BSON\UTCDateTime($parsed->getTimestamp() * 1000);
        }
    }
    return new MongoDB\BSON\UTCDateTime(time() * 1000);
}
/**
 * Send JSON response
 * @param array $data
 * @param int $statusCode
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
/**
 * Send error response
 * @param string $message
 * @param int $statusCode
 */
function errorResponse($message, $statusCode = 400) {
    jsonResponse(['success' => false, 'error' => $message], $statusCode);
}
/**
 * Send success response
 * @param mixed $data
 * @param string $message
 */
function successResponse($data = null, $message = 'Success') {
    jsonResponse([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}
/**
 * Require authentication
 */
function requireAuth() {
    if (!isLoggedIn()) {
        if (isAjaxRequest()) {
            errorResponse('Authentication required', 401);
        } else {
            header('Location: ' . BASE_URL . 'index.php');
            exit;
        }
    }
}
/**
 * Require admin role
 */
function requireAdmin() {
    requireAuth();
    if (!isAdmin()) {
        if (isAjaxRequest()) {
            errorResponse('Admin access required', 403);
        } else {
            header('Location: ' . BASE_URL . 'frontend/html/dashboard.html');
            exit;
        }
    }
}
/**
 * Check if request is AJAX
 * @return bool
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
/**
 * Validate JSON request
 * @return array|false
 */
function getJSONRequest() {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $data;
    }
    return false;
}
/**
 * Get request input from JSON body OR form-encoded body
 * @return array
 */
function getRequestData() {
    $data = getJSONRequest();
    if (!$data || !is_array($data)) {
        $data = $_POST;
    }
    return $data;
}
/**
 * Set security headers
 */
function setSecurityHeaders() {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

// Apply security headers to all requests
setSecurityHeaders();
?>
