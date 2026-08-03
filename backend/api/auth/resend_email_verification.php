<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../services/email_verification_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed', [], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = (int)($input['user_id'] ?? 0);

if ($userId <= 0) {
    json_response(false, 'Invalid request', [], 400);
}

$user = ev_find_user_by_id($userId);
if (!$user) {
    json_response(false, 'User not found', [], 404);
}

if ((int)$user['email_verified'] === 1) {
    json_response(true, 'Email already verified');
}

$token = ev_create_email_token($userId);
$verifyLink = 'http://localhost/secure-online-transaction-system/frontend/auth/verify-email.html?uid=' . $userId . '&token=' . urlencode($token);

log_event('auth.log', "Email verification requested for user ID {$userId}");

json_response(true, 'Verification email generated', [
    'verify_link' => $verifyLink
]);