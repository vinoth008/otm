<?php
// backend/php/auth.php
/**
 * Authentication Handlers for Smart Transaction Control
 * Handles user registration, login, logout, and password change.
 * OTP / email verification / SMTP have been completely removed.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/session_manager.php';
// Prevent direct access
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
/**
 * Handle user registration
 * POST: email, password, first_name, last_name, phone, role (optional)
 */
function registerUser() {
    // Check rate limit
    if (!checkRateLimit('register', 5, 3600)) {
        errorResponse('Too many registration attempts. Please try again later.', 429);
    }
    // Get and validate JSON request
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    // Verify CSRF token
    if (!isset($data['csrf_token']) || !verifyCSRFToken($data['csrf_token'])) {
        errorResponse('Invalid security token');
    }
    // Extract and sanitize inputs
    $email = sanitizeInput($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $firstName = sanitizeInput($data['first_name'] ?? '');
    $lastName = sanitizeInput($data['last_name'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    $role = sanitizeInput($data['role'] ?? 'user');
    // Validation
    if (empty($email) || empty($password) || empty($firstName)) {
        errorResponse('Email, password, and first name are required');
    }
    if (!validateEmail($email)) {
        errorResponse('Invalid email format');
    }
    $passwordValidation = validatePasswordStrength($password);
    if (!$passwordValidation['valid']) {
        errorResponse(implode(', ', $passwordValidation['errors']));
    }
    if (empty($phone) || !validatePhone($phone)) {
        errorResponse('Invalid phone number. Enter a valid 10-digit Indian mobile number');
    }
    // Only allow self-registration as user role (admin creates manager/auditor accounts)
    if (!in_array($role, ['user', 'customer', 'employee'], true)) {
        $role = 'user';
    }
    // Normalize role naming: employee == user == customer
    $role = ($role === 'customer' || $role === 'employee') ? 'user' : 'user';
    // Check if email already exists
    $collection = getCollection('users');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $existingUser = $collection->findOne(['email' => $email]);
    if ($existingUser) {
        errorResponse('Email already registered');
    }
    // Hash password
    $passwordHash = hashPassword($password);
    // Create user document - auto verified (no OTP)
    $userDocument = [
        'email' => $email,
        'password_hash' => $passwordHash,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'phone' => $phone,
        'role' => $role,
        'status' => 'active',
        'is_verified' => true,
        'login_attempts' => 0,
        'locked_until' => null,
        'currency' => 'INR',
        'theme_preference' => 'light',
        'department' => sanitizeInput($data['department'] ?? 'General'),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'last_login' => null,
        'deleted_at' => null
    ];
    // Insert user
    $result = $collection->insertOne($userDocument);
    if (!$result->getInsertedId()) {
        errorResponse('Registration failed. Please try again.');
    }
    // Get user ID
    $userId = (string)$result->getInsertedId();
    // Log activity
    logActivity('user_registered', $userId, ['email' => $email]);
    // Auto-login after successful registration
    $userDocument['_id'] = $result->getInsertedId();
    createUserSession($userDocument);
    successResponse([
        'user_id' => $userId,
        'email' => $email,
        'name' => $firstName . ' ' . $lastName,
        'role' => $role,
        'redirect' => getRoleDashboardUrl()
    ], 'Registration successful!');
}
/**
 * Handle user login
 * POST: email, password
 */
function loginUser() {
    // Check rate limit
    if (!checkRateLimit('login', 10, 60)) {
        errorResponse('Too many login attempts. Please try again later.', 429);
    }
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    // Verify CSRF token
    if (!isset($data['csrf_token']) || !verifyCSRFToken($data['csrf_token'])) {
        errorResponse('Invalid security token');
    }
    $email = sanitizeInput($data['email'] ?? '');
    $password = $data['password'] ?? '';
    if (empty($email) || empty($password)) {
        errorResponse('Email and password are required');
    }
    // Check brute force protection
    if (!checkBruteForce($email)) {
        errorResponse('Account temporarily locked due to multiple failed attempts. Please try again later.');
    }
    $collection = getCollection('users');
    $user = $collection->findOne(['email' => $email]);
    if (!$user) {
        recordFailedLogin($email);
        errorResponse('Invalid email or password');
    }
    // Check account status
    if (($user['status'] ?? 'active') !== 'active') {
        errorResponse('Your account has been suspended. Please contact support.');
    }
    // Verify password
    if (!verifyPassword($password, $user['password_hash'])) {
        recordFailedLogin($email);
        errorResponse('Invalid email or password');
    }
    // Check if account was locked
    if (isset($user['locked_until'])) {
        $collection->updateOne(
            ['_id' => $user['_id']],
            [
                '$set' => [
                    'login_attempts' => 0,
                    'locked_until' => null
                ]
            ]
        );
    }
    // Reset failed attempts
    resetFailedLoginAttempts($email);
    // Create session
    createUserSession($user);
    successResponse([
        'user_id' => (string)$user['_id'],
        'email' => $user['email'],
        'name' => $user['first_name'] . ' ' . $user['last_name'],
        'role' => $user['role'],
        'redirect' => getRoleDashboardUrl()
    ], 'Login successful');
}
/**
 * Handle user logout
 */
function logoutUser() {
    destroySession();
    successResponse(null, 'Logged out successfully');
}
/**
 * Handle change password (logged in user)
 * POST: old_password, new_password
 */
function changePassword() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!isset($data['csrf_token']) || !verifyCSRFToken($data['csrf_token'])) {
        errorResponse('Invalid security token');
    }
    $oldPassword = $data['old_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    if (empty($oldPassword) || empty($newPassword)) {
        errorResponse('Both old and new passwords are required');
    }
    $passwordValidation = validatePasswordStrength($newPassword);
    if (!$passwordValidation['valid']) {
        errorResponse(implode(', ', $passwordValidation['errors']));
    }
    $collection = getCollection('users');
    $user = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())]);
    if (!$user) {
        errorResponse('User not found');
    }
    // Verify old password
    if (!verifyPassword($oldPassword, $user['password_hash'])) {
        errorResponse('Current password is incorrect');
    }
    // Hash new password
    $passwordHash = hashPassword($newPassword);
    $collection->updateOne(
        ['_id' => $user['_id']],
        [
            '$set' => [
                'password_hash' => $passwordHash,
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Log activity
    logActivity('password_changed', getCurrentUserId());
    successResponse(null, 'Password changed successfully');
}
/**
 * Get current session info
 */
function getSessionInfo() {
    requireActiveSession();
    successResponse(getSessionData());
}
// Route handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'register':
            registerUser();
            break;
        case 'login':
            loginUser();
            break;
        case 'logout':
            logoutUser();
            break;
        case 'change_password':
            changePassword();
            break;
        default:
            errorResponse('Invalid action');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'session_info':
            getSessionInfo();
            break;
        default:
            errorResponse('Invalid action');
    }
} else {
    errorResponse('Method not allowed');
}
?>