<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../helpers/response.php';

function rbac_get_user_permissions(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT p.permission_code
        FROM users u
        INNER JOIN role_permissions rp ON u.role_id = rp.role_id
        INNER JOIN permissions p ON rp.permission_id = p.id
        WHERE u.id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function has_permission(int $userId, string $permissionCode): bool
{
    $permissions = rbac_get_user_permissions($userId);
    return in_array($permissionCode, $permissions, true);
}

function require_permission(string $permissionCode): void
{
    start_secure_session();

    if (empty($_SESSION['user_id'])) {
        json_response(false, 'Authentication required', [], 401);
    }

    if (!has_permission((int)$_SESSION['user_id'], $permissionCode)) {
        json_response(false, 'Access denied', [], 403);
    }
}

function require_role(array $allowedRoles): void
{
    start_secure_session();

    if (empty($_SESSION['role_code'])) {
        json_response(false, 'Role not found', [], 403);
    }

    if (!in_array($_SESSION['role_code'], $allowedRoles, true)) {
        json_response(false, 'Access denied', [], 403);
    }
}

function redirect_dashboard_by_role(string $roleCode): string
{
    return match ($roleCode) {
        'ADMIN' => '/secure-online-transaction-system/frontend/admin/dashboard.html',
        'STAFF' => '/secure-online-transaction-system/frontend/staff/dashboard.html',
        'RECEPTIONIST' => '/secure-online-transaction-system/frontend/receptionist/dashboard.html',
        'CUSTOMER' => '/secure-online-transaction-system/frontend/customer/dashboard.html',
        default => '/secure-online-transaction-system/frontend/auth/login.html',
    };
}