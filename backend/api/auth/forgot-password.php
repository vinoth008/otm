<?php
/**
 * Forgot password - generate reset token endpoint.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$data = getRequestData();
$email = strtolower(trim(sanitizeInput($data['email'] ?? '')));
if (empty($email) || !validateEmail($email)) {
    errorResponse('Enter a valid email address');
}

$resetToken = null;
$collection = getCollection('users');
if ($collection) {
    $user = $collection->findOne(['email' => $email, 'deleted_at' => null]);
    if ($user) {
        $resetToken = bin2hex(random_bytes(32));
        $expires = new DateTime();
        $expires->modify('+30 minutes');
        $collection->updateOne(
            ['_id' => $user['_id']],
            [
                '$set' => [
                    'reset_token' => $resetToken,
                    'reset_token_expires' => phpDateToMongo($expires),
                    'updated_at' => phpDateToMongo()
                ]
            ]
        );
        logActivity('password_reset_requested', (string)$user['_id'], ['email' => $email]);
    }
}

successResponse(['reset_token' => $resetToken], 'If that email exists, a reset link has been sent');
