<?php
// backend/php/session_manager.php
/**
 * Session Manager for Smart Transaction Control
 * Handles user sessions, authentication state, and session security.
 * Roles: admin, manager, user (employee), auditor
 */
// Prevent direct access
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
/**
 * Normalize a role to one of the 4 canonical roles
 * Legacy roles are mapped: staff->manager, receptionist->user, customer->user, employee->user
 * @param string|null $role
 * @return string
 */
function normalizeRole($role) {
    $map = [
        'admin' => 'admin',
        'administrator' => 'admin',
        'manager' => 'manager',
        'staff' => 'manager',
        'user' => 'user',
        'employee' => 'user',
        'customer' => 'user',
        'receptionist' => 'user',
        'auditor' => 'auditor',
        'audit' => 'auditor'
    ];
    $key = strtolower((string)$role);
    return $map[$key] ?? 'user';
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
 * Check if user is manager
 * @return bool
 */
function isManager() {
    return isLoggedIn() &&
        isset($_SESSION['user_role']) &&
        $_SESSION['user_role'] === 'manager';
}
/**
 * Check if user is auditor
 * @return bool
 */
function isAuditor() {
    return isLoggedIn() &&
        isset($_SESSION['user_role']) &&
        $_SESSION['user_role'] === 'auditor';
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
 * Check if user is staff (legacy alias for manager)
 * @return bool
 */
function isStaff() {
    return hasRole('manager');
}
/**
 * Check if user is receptionist (legacy alias for user)
 * @return bool
 */
function isReceptionist() {
    return hasRole('user');
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
 * Create user session
 * @param array $user
 */
function createUserSession($user) {
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    $role = normalizeRole($user['role'] ?? 'user');
    $_SESSION['user_id'] = (string)$user['_id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_theme'] = $user['theme_preference'] ?? 'light';
    $_SESSION['user_currency'] = $user['currency'] ?? 'INR';
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    // Update last login in database
    $collection = getCollection('users');
    if ($collection) {
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($user['_id'])],
            ['$set' => ['last_login' => phpDateToMongo()]]
        );
    }
    // Log activity
    logActivity('login', $user['_id'], ['email' => $user['email'], 'role' => $role]);
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
        'is_manager' => isManager(),
        'is_auditor' => isAuditor(),
        'is_staff' => isStaff(),
        'is_receptionist' => isReceptionist(),
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
        case 'manager':
            return BASE_URL . 'frontend/html/manager/dashboard.html';
        case 'auditor':
            return BASE_URL . 'frontend/html/auditor/dashboard.html';
        default:
            return BASE_URL . 'frontend/html/user/dashboard.html';
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
 */
function recordFailedLogin($email) {
    $collection = getCollection('users');
    if (!$collection) {
        return;
    }
    $user = $collection->findOne(['email' => $email]);
    if (!$user) {
        return;
    }
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
    }
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