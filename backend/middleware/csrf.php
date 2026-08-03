<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../helpers/response.php';

function csrf_token(): string
{
    start_secure_session();
    return generate_csrf_token();
}

function validate_csrf_request(): void
{
    start_secure_session();

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);

    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        json_response(false, 'Invalid CSRF token', [], 403);
    }
}