<?php
// backend/php/session_manager.php
/**
 * Session Manager for Smart Transaction Control
 * Handles user sessions, authentication state, and session security.
 * Roles (4 canonical): admin, staff, receptionist, customer
 * Legacy aliases map: manager->staff, employee->staff, auditor->admin(read-only), user->customer
 */
// Prevent direct access
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

/**
 * Normalize a role to one of the 4 canonical roles
 * @param string|null $role
 * @return string
 */
function normalizeRole($role) {
    $map = [
        // Canonical roles
        'admin' => 'admin',
        'staff' => 'staff',
        'receptionist' => 'receptionist',
        'customer' => 'customer',
        // Legacy aliases
        'administrator' => 'admin',
        'manager' => 'staff',
        'employee' => 'staff',
        'user' => 'customer',
        'auditor' => 'admin',
        'audit' => 'admin'
    ];
    $key = strtolower((string)$role);
    return $map[$key] ?? 'customer';
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) &&
        isset($_SESSION['user_email']) &&
        isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) < SESSION_TIMEOUT;
}

/**
 * Check if user is admin
 * @return bool
 */
function isAdmin() {
    return isLoggedIn() &&
        isset($_SESSION['user_role']) &&
        $_SESSION['user_role'] === 'admin';
}

/**
 * Check if user is staff
 * @return bool
 */
function isStaff() {
    return isLoggedIn() &&
        isset($_SESSION['user_role']) &&
        $_SESSION['user_role'] === 'staff';
}

/**
 * Check if user is receptionist
 * @return bool
 */
function isReceptionist() {
    return isLoggedIn() &&
        isset($_SESSION['user_role']) &&
        $_SESSION['user_role'] === 'receptionist';
}

/**
 * Check if user is customer
 * @return bool
 */
function isCustomer() {
    return isLoggedIn() &&
        isset($_SESSION['user_role']) &&
        $_SESSION['user_role'] === 'customer';
}

/**
 * Legacy alias: isManager -> isStaff
 * @return bool
 */
function isManager() {
    return isStaff();
}

/**
 * Legacy alias: isStaffAsLegacy -> staff or receptionist (employee portal)
 * @return bool
 */
function isEmployee() {
    return isStaff() || isReceptionist();
}

/**
 * Legacy: isAuditor -> admin (auditor view)
 * @return bool
 */
function isAuditor() {
    return isAdmin();
}

/**
 * Check if current user has one of the given roles
 * @param string|array $roles
 * @return bool
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    $current = $_SESSION['user_role'] ?? null;
    $currentNorm = normalizeRole($current);
    if (is_array($roles)) {
        foreach ($roles as $r) {
            if (normalizeRole($r) === $currentNorm) {
                return true;
            }
        }
        return false;
    }
    return normalizeRole($roles) === $currentNorm;
}

/**
 * Require one of the given roles (admin always passes)
 * @param string|array $roles
 */
function requireRole($roles) {
    requireActiveSession();
    if (isAdmin() || hasRole($roles)) {
        return;
    }
    if (isAjaxRequest()) {
        errorResponse('Access denied', 403);
    } else {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

/**
 * Require employee portal role (admin, staff, or receptionist)
 */
function requireEmployeeRole() {
    requireActiveSession();
    if (isAdmin() || isStaff() || isReceptionist()) {
        return;
    }
    if (isAjaxRequest()) {
        errorResponse('Employee access required', 403);
    } else {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

/**
 * Get current user ID
 * @return string|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user email
 * @return string|null
 */
function getCurrentUserEmail() {
    return $_SESSION['user_email'] ?? null;
}

/**
 * Get current user role
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get current user full name
 * @return string
 */
function getCurrentUserName() {
    return $_SESSION['user_name'] ?? '';
}

/**
 * Create user session
 * @param array $user
 */
function createUserSession($user) {
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    $role = normalizeRole($user['role'] ?? 'customer');
    $_SESSION['user_id'] = (string)$user['_id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '');
    $_SESSION['user_theme'] = $user['theme_preference'] ?? 'light';
    $_SESSION['user_currency'] = $user['currency'] ?? 'INR';
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['session_id'] = session_id();
    $_SESSION['ip_address'] = getUserIP();
    $_SESSION['user_agent'] = getUserAgent();
    // Update last login in database
    $collection = getCollection('users');
    if ($collection) {
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($user['_id'])],
            ['$set' => ['last_login' => phpDateToMongo()]]
        );
    }
    // Record login history
    recordLoginHistory($user, true);
    // Log activity
    logActivity('login', $user['_id'], ['email' => $user['email'], 'role' => $role]);
}

/**
 * Record a login attempt to the login_history collection
 * @param array|null $user
 * @param bool $success
 * @param string|null $failureReason
 */
function recordLoginHistory($user, $success, $failureReason = null) {
    $collection = getCollection('login_history');
    if (!$collection) {
        return;
    }
    $ip = getUserIP();
    $ua = getUserAgent();
    $browser = detectBrowser($ua);
    $os = detectOS($ua);
    $device = detectDevice($ua);
    $collection->insertOne([
        'user_id' => $user && isset($user['_id']) ? new MongoDB\BSON\ObjectId((string)$user['_id']) : null,
        'email' => $user['email'] ?? '',
        'role' => $user ? normalizeRole($user['role'] ?? 'customer') : '',
        'ip_address' => $ip,
        'browser' => $browser,
        'os' => $os,
        'device' => $device,
        'user_agent' => $ua,
        'session_id' => session_id(),
        'attempt_time' => phpDateToMongo(),
        'login_time' => $success ? phpDateToMongo() : null,
        'logout_time' => null,
        'success' => (bool)$success,
        'failure_reason' => $failureReason,
        'locked' => false,
        'blocked' => false,
        'created_at' => phpDateToMongo()
    ]);
}

/**
 * Record logout time in login_history
 */
function recordLogoutTime() {
    $collection = getCollection('login_history');
    if (!$collection) {
        return;
    }
    $sessionId = $_SESSION['session_id'] ?? session_id();
    $collection->updateOne(
        [
            'session_id' => $sessionId,
            'user_id' => isset($_SESSION['user_id']) ? new MongoDB\BSON\ObjectId(getCurrentUserId()) : null,
            'logout_time' => null
        ],
        ['$set' => ['logout_time' => phpDateToMongo()]]
    );
}

/**
 * Detect browser from user agent
 * @param string $ua
 * @return string
 */
function detectBrowser($ua) {
    if (stripos($ua, 'Edg/') !== false) return 'Microsoft Edge';
    if (stripos($ua, 'Chrome/') !== false) return 'Chrome';
    if (stripos($ua, 'Firefox/') !== false) return 'Firefox';
    if (stripos($ua, 'Safari/') !== false) return 'Safari';
    if (stripos($ua, 'OPR/') !== false) return 'Opera';
    if (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident/') !== false) return 'Internet Explorer';
    return 'Unknown';
}

/**
 * Detect OS from user agent
 * @param string $ua
 * @return string
 */
function detectOS($ua) {
    if (stripos($ua, 'Windows NT 10') !== false) return 'Windows 10/11';
    if (stripos($ua, 'Windows NT 6.3') !== false) return 'Windows 8.1';
    if (stripos($ua, 'Windows NT 6.1') !== false) return 'Windows 7';
    if (stripos($ua, 'Android') !== false) return 'Android';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) return 'iOS';
    if (stripos($ua, 'Mac OS X') !== false) return 'macOS';
    if (stripos($ua, 'Linux') !== false) return 'Linux';
    return 'Unknown';
}

/**
 * Detect device type from user agent
 * @param string $ua
 * @return string
 */
function detectDevice($ua) {
    if (stripos($ua, 'Mobile') !== false || stripos($ua, 'iPhone') !== false || stripos($ua, 'Android') !== false) {
        return 'Mobile';
    }
    if (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) {
        return 'Tablet';
    }
    return 'Desktop';
}

/**
 * Update session activity timestamp
 */
function updateSessionActivity() {
    if (isLoggedIn()) {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Destroy user session
 */
function destroySession() {
    $userId = getCurrentUserId();
    // Record logout time in login history
    recordLogoutTime();
    // Log activity before destroying session
    if ($userId) {
        logActivity('logout', $userId);
    }
    // Clear all session variables
    $_SESSION = [];
    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    // Destroy session
    session_destroy();
}

/**
 * Check session timeout and update activity
 */
function checkSession() {
    if (isLoggedIn()) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            destroySession();
            return false;
        }
        updateSessionActivity();
        return true;
    }
    return false;
}

/**
 * Require active session
 */
function requireActiveSession() {
    if (!checkSession()) {
        if (isAjaxRequest()) {
            errorResponse('Session expired. Please login again.', 401);
        } else {
            header('Location: ' . BASE_URL . 'index.php?session=expired');
            exit;
        }
    }
}

/**
 * Get session data for API
 * @return array
 */
function getSessionData() {
    $role = $_SESSION['user_role'] ?? null;
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_email' => $_SESSION['user_email'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
        'user_role' => $role,
        'user_theme' => $_SESSION['user_theme'] ?? 'light',
        'user_currency' => $_SESSION['user_currency'] ?? 'INR',
        'is_logged_in' => isLoggedIn(),
        'is_admin' => isAdmin(),
        'is_staff' => isStaff(),
        'is_receptionist' => isReceptionist(),
        'is_customer' => isCustomer(),
        'dashboard_url' => getRoleDashboardUrl()
    ];
}

/**
 * Get the dashboard URL for the current user's role
 * @return string
 */
function getRoleDashboardUrl() {
    $role = normalizeRole(getCurrentUserRole());
    switch ($role) {
        case 'admin':
            return BASE_URL . 'frontend/html/admin/dashboard.html';
        case 'staff':
            return BASE_URL . 'frontend/html/staff/dashboard.html';
        case 'receptionist':
            return BASE_URL . 'frontend/html/receptionist/dashboard.html';
        case 'customer':
        default:
            return BASE_URL . 'frontend/html/customer/dashboard.html';
    }
}

/**
 * Change user theme preference
 * @param string $theme
 */
function changeThemePreference($theme) {
    if (in_array($theme, ['dark', 'light'])) {
        $_SESSION['user_theme'] = $theme;
        $userId = getCurrentUserId();
        if ($userId) {
            $collection = getCollection('users');
            if ($collection) {
                $collection->updateOne(
                    ['_id' => new MongoDB\BSON\ObjectId($userId)],
                    ['$set' => ['theme_preference' => $theme]]
                );
            }
        }
    }
}

/**
 * Check brute force protection
 * @param string $email
 * @return bool
 */
function checkBruteForce($email) {
    $collection = getCollection('users');
    if (!$collection) {
        return true;
    }
    $user = $collection->findOne(['email' => $email]);
    if (!$user) {
        return true;
    }
    // Check if account is locked
    if (isset($user['locked_until'])) {
        $lockedUntil = mongoDateToPHP($user['locked_until']);
        if (new DateTime() < $lockedUntil) {
            return false;
        } else {
            // Lockout period expired, reset attempts
            $collection->updateOne(
                ['_id' => $user['_id']],
                [
                    '$set' => [
                        'login_attempts' => 0,
                        'locked_until' => null
                    ]
                ]
            );
            return true;
        }
    }
    return true;
}

/**
 * Record failed login attempt
 * @param string $email
 * @param string|null $reason
 */
function recordFailedLogin($email, $reason = 'invalid_credentials') {
    $collection = getCollection('users');
    if ($collection) {
        $user = $collection->findOne(['email' => $email]);
        if ($user) {
            $attempts = ($user['login_attempts'] ?? 0) + 1;
            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                // Lock account
                $lockedUntil = new DateTime();
                $lockedUntil->modify('+' . LOCKOUT_TIME . ' seconds');
                $collection->updateOne(
                    ['_id' => $user['_id']],
                    [
                        '$set' => [
                            'login_attempts' => $attempts,
                            'locked_until' => phpDateToMongo($lockedUntil)
                        ]
                    ]
                );
                // Record login history for locked account
                recordLoginHistory($user, false, $reason . '_account_locked');
                // Log security event
                logActivity('account_locked_brute_force', $user['_id'], [
                    'email' => $email,
                    'attempts' => $attempts
                ]);
            } else {
                $collection->updateOne(
                    ['_id' => $user['_id']],
                    ['$set' => ['login_attempts' => $attempts]]
                );
                // Record failed login history
                recordLoginHistory($user, false, $reason);
            }
            return;
        }
    }
    // Record failed login for unknown user
    recordLoginHistory(['email' => $email, 'role' => 'customer'], false, 'user_not_found');
}

/**
 * Reset failed login attempts
 * @param string $email
 */
function resetFailedLoginAttempts($email) {
    $collection = getCollection('users');
    if ($collection) {
        $collection->updateOne(
            ['email' => $email],
            [
                '$set' => [
                    'login_attempts' => 0,
                    'locked_until' => null
                ]
            ]
        );
    }
}

/**
 * Verify user has permission for action
 * @param string $action
 * @param string $resourceType
 * @param string $resourceId
 * @return bool
 */
function verifyPermission($action, $resourceType, $resourceId) {
    $userId = getCurrentUserId();
    $userRole = getCurrentUserRole();
    // Admin has all permissions
    if ($userRole === 'admin') {
        return true;
    }
    // For user resources, check ownership
    if ($resourceType === 'user' && $action === 'own') {
        return $userId === $resourceId;
    }
    // Default: deny
    return false;
}

/**
 * Check if session is about to expire
 * @param int $warningThreshold
 * @return bool
 */
function isSessionExpiringSoon($warningThreshold = 300) {
    if (!isLoggedIn()) {
        return false;
    }
    $timeSinceActivity = time() - $_SESSION['last_activity'];
    return $timeSinceActivity > (SESSION_TIMEOUT - $warningThreshold);
}
?>