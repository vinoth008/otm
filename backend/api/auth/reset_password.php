<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../services/password_reset_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed', [], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = (int)($input['user_id'] ?? 0);
$token = clean_string($input['token'] ?? '');
$newPassword = (string)($input['new_password'] ?? '');
$confirmPassword = (string)($input['confirm_password'] ?? '');

if ($userId <= 0 || $token === '' || $newPassword === '' || $confirmPassword === '') {
    json_response(false, 'All fields are required', [], 400);
}

if ($newPassword !== $confirmPassword) {
    json_response(false, 'Passwords do not match', [], 400);
}

if (!valid_password($newPassword)) {
    json_response(false, 'Password must be strong', [], 400);
}

$valid = pr_validate_reset_token($userId, $token);
if (!$valid['ok']) {
    json_response(false, $valid['message'], [], 400);
}

if (!pr_update_password($userId, $newPassword)) {
    json_response(false, 'Unable to update password', [], 500);
}

pr_mark_token_used((int)$valid['row']['id']);
log_event('auth.log', "Password reset completed for user ID {$userId}");

json_response(true, 'Password reset successfully');