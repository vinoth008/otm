<?php
/**
 * Resend / generate OTP endpoint (legacy alias).
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$data = getRequestData();
$userIdInput = sanitizeInput($data['user_id'] ?? '');
$email = strtolower(trim(sanitizeInput($data['email'] ?? '')));

$collection = getCollection('users');
if (!$collection) {
    errorResponse('Database connection error', 500);
}

$filter = [];
if (isValidObjectId($userIdInput)) {
    $filter['_id'] = new MongoDB\BSON\ObjectId($userIdInput);
} elseif (!empty($email) && validateEmail($email)) {
    $filter['email'] = $email;
} else {
    errorResponse('Invalid request');
}
$filter['deleted_at'] = null;

$user = $collection->findOne($filter);
if (!$user) {
    errorResponse('User not found', 404);
}

if (($user['email_verified'] ?? false)) {
    successResponse(null, 'Email already verified');
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

logActivity('email_verification_sent', (string)$user['_id']);
successResponse(['otp' => $otp], 'Verification OTP generated');
