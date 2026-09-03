<?php
/**
 * Reset password using reset token endpoint.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$data = getRequestData();
$resetToken = sanitizeInput($data['reset_token'] ?? '');
$newPassword = $data['new_password'] ?? '';
$confirmPassword = $data['confirm_password'] ?? '';

if (empty($resetToken) || empty($newPassword)) {
    errorResponse('Reset token and new password are required');
}
if ($newPassword !== $confirmPassword) {
    errorResponse('Passwords do not match');
}
$passwordValidation = validatePasswordStrength($newPassword);
if (!$passwordValidation['valid']) {
    errorResponse(implode(', ', $passwordValidation['errors']));
}

$collection = getCollection('users');
if (!$collection) {
    errorResponse('Database connection error', 500);
}

$user = $collection->findOne(['reset_token' => $resetToken, 'deleted_at' => null]);
if (!$user) {
    errorResponse('Invalid or expired reset token');
}

if (isset($user['reset_token_expires'])) {
    $expires = mongoDateToPHP($user['reset_token_expires']);
    if (new DateTime() > $expires) {
        errorResponse('Reset token has expired');
    }
}

$collection->updateOne(
    ['_id' => $user['_id']],
    [
        '$set' => [
            'password_hash' => hashPassword($newPassword),
            'reset_token' => null,
            'reset_token_expires' => null,
            'login_attempts' => 0,
            'locked_until' => null,
            'updated_at' => phpDateToMongo()
        ]
    ]
);

logActivity('password_reset_completed', (string)$user['_id']);
successResponse(null, 'Password reset successfully');
