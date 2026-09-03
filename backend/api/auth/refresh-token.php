<?php
/**
 * Refresh token / resend OTP endpoint.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$data = getRequestData();
$userIdInput = sanitizeInput($data['user_id'] ?? '');

if (isValidObjectId($userIdInput)) {
    $collection = getCollection('users');
    $user = $collection
        ? $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($userIdInput), 'deleted_at' => null])
        : null;
    if (!$user) {
        errorResponse('User not found', 404);
    }
    $otp = (string)random_int(100000, 999999);
    $expires = new DateTime();
    $expires->modify('+10 minutes');
    $otpCollection = getCollection('otp_verifications');
    if ($otpCollection) {
        $otpCollection->insertOne([
            'user_id' => $user['_id'],
            'otp_code' => $otp,
            'otp_purpose' => 'verify_email',
            'is_used' => false,
            'expires_at' => phpDateToMongo($expires),
            'created_at' => phpDateToMongo()
        ]);
    }
    logActivity('otp_resent', (string)$user['_id']);
    successResponse(['otp' => $otp], 'OTP sent successfully');
}

requireActiveSession();
$userId = getCurrentUserId();
if (!$userId) {
    errorResponse('Not authenticated', 401);
}
$token = bin2hex(random_bytes(32));
logActivity('token_refreshed', $userId);
successResponse(['refresh_token' => $token], 'Token refreshed');
