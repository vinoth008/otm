<?php
// backend/php/auth.php
/**
 * Authentication Handlers for Smart Transaction Control
 * Handles user registration, login, logout, and password change.
 * Supports 4 roles: admin, staff, receptionist, customer
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
 * Handle user registration (customer self-registration)
 * POST: email, password, first_name, last_name, phone, account_type
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
    $email = strtolower(trim(sanitizeInput($data['email'] ?? '')));
    $password = $data['password'] ?? '';
    $firstName = sanitizeInput($data['first_name'] ?? '');
    $lastName = sanitizeInput($data['last_name'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    $dob = sanitizeInput($data['dob'] ?? '');
    $address = sanitizeInput($data['address'] ?? '');
    $accountType = sanitizeInput($data['account_type'] ?? 'savings');
    // Validation
    if (empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
        errorResponse('Email, password, first name, and last name are required');
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
    if (!empty($dob) && !validateDate($dob)) {
        errorResponse('Invalid date of birth');
    }
    // Validate account type
    $validAccountTypes = ['savings', 'current', 'salary', 'fixed'];
    if (!in_array($accountType, $validAccountTypes, true)) {
        $accountType = 'savings';
    }
    // Check if email already exists
    $collection = getCollection('users');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $existingUser = $collection->findOne(['email' => $email]);
    if ($existingUser) {
        errorResponse('Email already registered');
    }
    // Generate account number (15-digit)
    $accountNumber = generateAccountNumber();
    // Hash password
    $passwordHash = hashPassword($password);
    // Create customer user document - auto verified (no OTP)
    $userDocument = [
        'email' => $email,
        'password_hash' => $passwordHash,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'phone' => $phone,
        'role' => 'customer',
        'status' => 'active',
        'is_verified' => true,
        'account_number' => $accountNumber,
        'account_type' => $accountType,
        'dob' => $dob ? phpDateToMongo($dob . ' 00:00:00') : null,
        'address' => $address,
        'balance' => 0.00,
        'login_attempts' => 0,
        'locked_until' => null,
        'currency' => 'INR',
        'theme_preference' => 'light',
        'department' => 'Customer',
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

    // Create default wallet for customer
    $walletCollection = getCollection('wallets');
    if ($walletCollection) {
        $walletCollection->insertOne([
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'name' => 'Main Account',
            'account_number' => $accountNumber,
            'balance' => 0.00,
            'currency' => 'INR',
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo(),
            'deleted_at' => null
        ]);
    }

    // Create welcome notification
    $notificationCollection = getCollection('notifications');
    if ($notificationCollection) {
        $notificationCollection->insertOne([
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'type' => 'account',
            'title' => 'Welcome to ' . APP_NAME,
            'message' => 'Your account has been created successfully. Your account number is ' . $accountNumber . '.',
            'read' => false,
            'link' => '',
            'created_at' => phpDateToMongo()
        ]);
    }

    // Log activity
    logActivity('user_registered', $userId, ['email' => $email, 'account_number' => $accountNumber]);
    // Auto-login after successful registration
    $userDocument['_id'] = $result->getInsertedId();
    createUserSession($userDocument);
    successResponse([
        'user_id' => $userId,
        'email' => $email,
        'name' => $firstName . ' ' . $lastName,
        'role' => 'customer',
        'account_number' => $accountNumber,
        'redirect' => getRoleDashboardUrl()
    ], 'Registration successful!');
}

/**
 * Generate a unique 15-digit account number
 * @return string
 */
function generateAccountNumber() {
    $collection = getCollection('users');
    do {
        $number = '5' . random_int(100000000000000, 999999999999999); // 16 digits starting with 5
        if (!$collection) {
            break;
        }
        $exists = $collection->findOne(['account_number' => $number]);
    } while ($exists);
    return $number;
}

/**
 * Handle user login (role-aware)
 * POST: email, password, role (admin/staff/receptionist/customer)
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
    $email = strtolower(trim(sanitizeInput($data['email'] ?? '')));
    $password = $data['password'] ?? '';
    $selectedRole = strtolower(trim(sanitizeInput($data['role'] ?? '')));
    if (empty($email) || empty($password)) {
        errorResponse('Email and password are required');
    }
    // Validate selected role — empty means "any portal" (frontend validates allowed roles)
    $validRoles = ['admin', 'staff', 'receptionist', 'customer'];
    if (!empty($selectedRole) && !in_array($selectedRole, $validRoles, true)) {
        $selectedRole = 'customer';
    }
    // Check brute force protection
    if (!checkBruteForce($email)) {
        recordFailedLogin($email, 'account_locked');
        errorResponse('Account temporarily locked due to multiple failed attempts. Please try again later.');
    }
    $collection = getCollection('users');
    $user = $collection->findOne(['email' => $email]);
    if (!$user) {
        recordFailedLogin($email, 'user_not_found');
        errorResponse('Invalid email or password');
    }
    // Check account status
    if (($user['status'] ?? 'active') !== 'active') {
        recordFailedLogin($email, 'account_suspended');
        errorResponse('Your account has been suspended. Please contact support.');
    }
    // Verify password
    if (!verifyPassword($password, $user['password_hash'])) {
        recordFailedLogin($email, 'invalid_password');
        errorResponse('Invalid email or password');
    }
    // Role enforcement: only enforce when the client explicitly selected a portal.
    // When no role is provided, the front-end validates against its allowed roles.
    $actualRole = normalizeRole($user['role'] ?? 'customer');
    if (!empty($selectedRole) && $actualRole !== $selectedRole) {
        recordFailedLogin($email, 'role_mismatch');
        $roleLabels = ['admin' => 'Admin', 'staff' => 'Staff', 'receptionist' => 'Receptionist', 'customer' => 'Customer'];
        errorResponse('This account does not have ' . ($roleLabels[$selectedRole] ?? $selectedRole) . ' access. Please select the correct portal.');
    }
    // Check if account was locked (expired lock)
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
        'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
        'role' => $actualRole,
        'redirect' => getRoleDashboardUrl()
    ], 'Login successful');
}

/**
 * Handle user logout
 */
function logoutUser() {
    requireActiveSession();
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
 * Handle forgot password - email lookup (no OTP, direct reset is handled in reset_password)
 * POST: email
 */
function forgotPassword() {
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    $email = strtolower(trim(sanitizeInput($data['email'] ?? '')));
    if (empty($email) || !validateEmail($email)) {
        errorResponse('Enter a valid email address');
    }
    $collection = getCollection('users');
    $user = $collection->findOne(['email' => $email]);
    if (!$user) {
        // Do not reveal whether email exists (security)
        successResponse(null, 'If that email exists, a reset link has been sent');
    }
    // Generate reset token
    $resetToken = bin2hex(random_bytes(32));
    $resetExpires = new DateTime();
    $resetExpires->modify('+30 minutes');
    $collection->updateOne(
        ['_id' => $user['_id']],
        [
            '$set' => [
                'reset_token' => $resetToken,
                'reset_token_expires' => phpDateToMongo($resetExpires),
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Store token in session for frontend to use (dev-friendly no-email flow)
    $_SESSION['reset_token'] = $resetToken;
    $_SESSION['reset_email'] = $email;
    // Log activity
    logActivity('password_reset_requested', (string)$user['_id'], ['email' => $email]);
    successResponse([
        'reset_token' => $resetToken,
        'reset_email' => $email
    ], 'Reset token generated');
}

/**
 * Handle password reset with token
 * POST: token, new_password, confirm_password
 */
function resetPassword() {
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    $token = sanitizeInput($data['reset_token'] ?? '');
    $newPassword = $data['new_password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';
    if (empty($token) || empty($newPassword)) {
        errorResponse('Reset token and new password are required');
    }
    if ($newPassword !== $confirmPassword) {
        errorResponse('Passwords do not match');
    }
    $passwordValidation = validatePasswordStrength($newPassword);
    if (!$passwordValidation['valid']) {
        errorResponse(implode(', ', $passwordValidation['errors']));
    }
    $collection = getCollection('users');
    $user = $collection->findOne(['reset_token' => $token]);
    if (!$user) {
        errorResponse('Invalid or expired reset token');
    }
    // Check token expiry
    if (isset($user['reset_token_expires'])) {
        $expires = mongoDateToPHP($user['reset_token_expires']);
        if (new DateTime() > $expires) {
            errorResponse('Reset token has expired');
        }
    }
    // Update password
    $collection->updateOne(
        ['_id' => $user['_id']],
        [
            '$set' => [
                'password_hash' => hashPassword($newPassword),
                'reset_token' => null,
                'reset_token_expires' => null,
                'login_attempts' => 0,
                'locked_until' => null,
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Log activity
    logActivity('password_reset_completed', (string)$user['_id']);
    successResponse(null, 'Password reset successfully. You can now login.');
}

/**
 * Get current session info
 */
function getSessionInfo() {
    $token = generateCSRFToken();
    if (!isLoggedIn()) {
        successResponse([
            'is_logged_in' => false,
            'csrf_token' => $token
        ]);
    }
    $sessionData = getSessionData();
    $sessionData['csrf_token'] = $token;
    successResponse($sessionData);
}

/**
 * Admin: get login history
 * GET: page, limit, user_id, success, from_date, to_date
 */
function getLoginHistory() {
    requireRole(['admin']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $skip = ($page - 1) * $limit;
    $collection = getCollection('login_history');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $filter = [];
    $userId = $_GET['user_id'] ?? '';
    if (isValidObjectId($userId)) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId($userId);
    }
    if (isset($_GET['success']) && ($_GET['success'] === '1' || $_GET['success'] === '0')) {
        $filter['success'] = $_GET['success'] === '1';
    }
    $email = $_GET['email'] ?? '';
    if ($email !== '') {
        $filter['email'] = new MongoDB\BSON\Regex($email, 'i');
    }
    $fromDate = $_GET['from_date'] ?? '';
    $toDate = $_GET['to_date'] ?? '';
    if (!empty($fromDate)) {
        $filter['attempt_time'] = ['$gte' => phpDateToMongo($fromDate . ' 00:00:00')];
    }
    if (!empty($toDate)) {
        $toFilter = ['$lte' => phpDateToMongo($toDate . ' 23:59:59')];
        if (isset($filter['attempt_time'])) {
            $filter['attempt_time'] += $toFilter;
        } else {
            $filter['attempt_time'] = $toFilter;
        }
    }
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort' => ['attempt_time' => -1],
        'skip' => $skip,
        'limit' => $limit
    ]);
    $list = [];
    foreach ($cursor as $log) {
        $list[] = [
            'log_id' => (string)$log['_id'],
            'user_id' => isset($log['user_id']) ? (string)$log['user_id'] : null,
            'email' => $log['email'] ?? '',
            'role' => $log['role'] ?? '',
            'ip_address' => $log['ip_address'] ?? '',
            'browser' => $log['browser'] ?? '',
            'os' => $log['os'] ?? '',
            'device' => $log['device'] ?? '',
            'user_agent' => $log['user_agent'] ?? '',
            'session_id' => $log['session_id'] ?? '',
            'attempt_time' => mongoDateToPHP($log['attempt_time'] ?? null)->format('Y-m-d H:i:s'),
            'login_time' => isset($log['login_time']) ? mongoDateToPHP($log['login_time'])->format('Y-m-d H:i:s') : null,
            'logout_time' => isset($log['logout_time']) ? mongoDateToPHP($log['logout_time'])->format('Y-m-d H:i:s') : null,
            'success' => (bool)($log['success'] ?? false),
            'failure_reason' => $log['failure_reason'] ?? '',
            'locked' => (bool)($log['locked'] ?? false),
            'blocked' => (bool)($log['blocked'] ?? false)
        ];
    }
    successResponse([
        'logs' => $list,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ], 'Login history retrieved');
}

/**
 * Admin: delete old login logs
 * POST: days (delete logs older than N days)
 */
function deleteOldLoginLogs() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $days = max(1, min(365, (int)($data['days'] ?? 30)));
    $cutoff = new DateTime();
    $cutoff->modify("-{$days} days");
    $collection = getCollection('login_history');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->deleteMany([
        'attempt_time' => ['$lt' => phpDateToMongo($cutoff)]
    ]);
    logActivity('login_history_cleaned', getCurrentUserId(), ['deleted' => $result->getDeletedCount(), 'days' => $days]);
    successResponse(['deleted_count' => $result->getDeletedCount()], 'Old login logs deleted');
}

/**
 * Admin: lock/unlock user account
 * POST: user_id, action (lock/unlock)
 */
function adminLockUnlockUser() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $userId = $data['user_id'] ?? '';
    $action = $data['action'] ?? '';
    if (!isValidObjectId($userId)) {
        errorResponse('Invalid user ID');
    }
    if (!in_array($action, ['lock', 'unlock'], true)) {
        errorResponse('Invalid action');
    }
    $collection = getCollection('users');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $update = ['updated_at' => phpDateToMongo()];
    if ($action === 'lock') {
        $lockedUntil = new DateTime();
        $lockedUntil->modify('+24 hours');
        $update['locked_until'] = phpDateToMongo($lockedUntil);
    } else {
        $update['locked_until'] = null;
        $update['login_attempts'] = 0;
    }
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($userId)], ['$set' => $update]);
    logActivity($action === 'lock' ? 'account_locked_by_admin' : 'account_unlocked_by_admin', getCurrentUserId(), ['target_user_id' => $userId]);
    successResponse(null, $action === 'lock' ? 'Account locked' : 'Account unlocked');
}

/**
 * Admin: reset user password
 * POST: user_id, new_password
 */
function adminResetPassword() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $userId = $data['user_id'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    if (!isValidObjectId($userId)) {
        errorResponse('Invalid user ID');
    }
    $passwordValidation = validatePasswordStrength($newPassword);
    if (!$passwordValidation['valid']) {
        errorResponse(implode(', ', $passwordValidation['errors']));
    }
    $collection = getCollection('users');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($userId)],
        [
            '$set' => [
                'password_hash' => hashPassword($newPassword),
                'login_attempts' => 0,
                'locked_until' => null,
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    logActivity('password_reset_by_admin', getCurrentUserId(), ['target_user_id' => $userId]);
    successResponse(null, 'Password reset successfully');
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
        case 'forgot_password':
            forgotPassword();
            break;
        case 'reset_password':
            resetPassword();
            break;
        case 'login_history':
            getLoginHistory();
            break;
        case 'delete_old_logs':
            deleteOldLoginLogs();
            break;
        case 'lock_user':
        case 'unlock_user':
            adminLockUnlockUser();
            break;
        case 'admin_reset_password':
            adminResetPassword();
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
        case 'login_history':
            getLoginHistory();
            break;
        default:
            errorResponse('Invalid action');
    }
} else {
    errorResponse('Method not allowed');
}
?>