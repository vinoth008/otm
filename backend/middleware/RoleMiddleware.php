<?php
declare(strict_types=1);

/**
 * RoleMiddleware.php — Role-Based Access Control (RBAC) for MongoDB sessions
 */

class RoleMiddleware
{
    /** Role hierarchy: higher index = more privileges */
    private const HIERARCHY = ['customer', 'receptionist', 'staff', 'admin'];

    /**
     * Require the user to have one of the specified roles.
     * Exits with 403 if the check fails.
     *
     * @param string|string[] $roles  Single role or array of allowed roles
     */
    public static function require($roles): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }

        $userRole = $_SESSION['user_role'] ?? '';
        $allowed  = is_array($roles) ? $roles : [$roles];

        if (!in_array($userRole, $allowed, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied. Required role: ' . implode(' or ', $allowed)]);
            exit;
        }
    }

    /**
     * Require the user to have AT LEAST the specified role level.
     * E.g., requireMinRole('staff') allows staff AND admin.
     */
    public static function requireMinRole(string $minRole): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userRole = $_SESSION['user_role'] ?? '';
        $minIdx   = array_search($minRole, self::HIERARCHY, true);
        $userIdx  = array_search($userRole, self::HIERARCHY, true);

        if ($minIdx === false || $userIdx === false || $userIdx < $minIdx) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Insufficient role privileges']);
            exit;
        }
    }

    /**
     * Get dashboard redirect URL for the current user's role.
     */
    public static function getDashboardUrl(string $role): string
    {
        return match ($role) {
            'admin'        => '/MPWT/frontend/admin/dashboard.html',
            'staff'        => '/MPWT/frontend/staff/dashboard.html',
            'receptionist' => '/MPWT/frontend/receptionist/dashboard.html',
            'customer'     => '/MPWT/frontend/customer/dashboard.html',
            default        => '/MPWT/frontend/auth/login.html',
        };
    }

    /**
     * Check (non-destructive) whether the current session has the given role.
     */
    public static function hasRole(string $role): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return ($_SESSION['user_role'] ?? '') === $role;
    }

    /**
     * Check whether current user is an admin.
     */
    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }
}
