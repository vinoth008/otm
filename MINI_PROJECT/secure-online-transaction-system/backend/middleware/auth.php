<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../services/auth_service.php';

function require_auth(): void
{
    start_secure_session();

    if (empty($_SESSION['user_id'])) {
        json_response(false, 'Authentication required', [], 401);
    }

    $user = auth_find_user_by_id((int)$_SESSION['user_id']);
    if (!$user || $user['account_status'] !== 'ACTIVE') {
        auth_destroy_session();
        json_response(false, 'Session invalid or account inactive', [], 401);
    }
}

function current_user(): ?array
{
    start_secure_session();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return auth_find_user_by_id((int)$_SESSION['user_id']);
}

function require_active_session(): void
{
    start_secure_session();

    if (empty($_SESSION['user_id']) || empty($_SESSION['session_token'])) {
        json_response(false, 'Session required', [], 401);
    }
}