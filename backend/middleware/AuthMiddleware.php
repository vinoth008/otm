<?php
declare(strict_types=1);

/**
 * AuthMiddleware.php — Session-based authentication for MongoDB users
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

class AuthMiddleware
{
    /**
     * Require an active session. Sends 401 JSON and exits if not authenticated.
     */
    public static function require(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
            exit;
        }

        // Check session timeout
        if (!empty($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > SESSION_TIMEOUT) {
            self::destroySession();
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
            exit;
        }

        $_SESSION['last_activity'] = time();
    }

    /**
     * Require a specific role or array of roles.
     */
    public static function requireRole(array $roles): void
    {
        self::require();
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, $roles, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
            exit;
        }
    }

    /**
     * Get the current authenticated user ID.
     */
    public static function getUserId(): ?string
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get the current authenticated user role.
     */
    public static function getRole(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    /**
     * Check if user is authenticated (non-destructive).
     */
    public static function isAuthenticated(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        if (!empty($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > SESSION_TIMEOUT) {
            return false;
        }
        return true;
    }

    /**
     * Destroy the current session cleanly.
     */
    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }
}
