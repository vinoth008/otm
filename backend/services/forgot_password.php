<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../services/password_reset_service.php';
require_once __DIR__ . '/../services/EmailService.php';

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

if (($user['account_status'] ?? '') !== 'ACTIVE') {
    json_response(false, 'Account is not active', [], 400);
}

$reset = pr_create_reset_token((int)$user['user_id']);

$link = BASE_URL . 'frontend/auth/reset-password.html?uid=' . $user['user_id'] . '&token=' . urlencode($reset['token']);

$mail = new EmailService();
$html = '<p>Hello ' . htmlspecialchars($user['full_name'] ?? 'there', ENT_QUOTES, 'UTF-8') . ',</p>'
    . '<p>You requested a password reset. Click the button below to set a new password (link valid for 15 minutes):</p>'
    . '<p><a href="' . $link . '" style="background:#818cf8;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;">Reset Password</a></p>'
    . '<p>If you did not request this, please ignore this email.</p>';
$mail->send($user['email'], $user['full_name'] ?? '', 'Password Reset - Secure Online Transaction System', $html);

log_event('auth.log', "Password reset requested for user ID {$user['user_id']}");

json_response(true, 'Password reset link generated', [
    'reset_link' => $link,
    'expires_at' => $reset['expires_at']
]);
