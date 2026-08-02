<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../services/password_reset_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed', [], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$identifier = clean_string($input['identifier'] ?? '');

if ($identifier === '') {
    json_response(false, 'Email or mobile is required', [], 400);
}

$user = pr_find_user_by_email_or_mobile($identifier);
if (!$user) {
    json_response(true, 'If the account exists, a reset link has been generated');
}

if ($user['account_status'] !== 'ACTIVE') {
    json_response(false, 'Account is not active', [], 400);
}

$reset = pr_create_reset_token((int)$user['id']);

$link = 'http://localhost/secure-online-transaction-system/frontend/auth/reset-password.html?uid=' . $user['id'] . '&token=' . urlencode($reset['token']);

log_event('auth.log', "Password reset requested for user ID {$user['id']}");

json_response(true, 'Password reset link generated', [
    'reset_link' => $link,
    'expires_at' => $reset['expires_at']
]);