<?php
/**
 * Verify OTP endpoint (legacy alias).
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
$otp = sanitizeInput($data['otp'] ?? '');
$purpose = sanitizeInput($data['purpose'] ?? 'verify_email');

if (!preg_match('/^\d{6}$/', $otp) || empty($purpose)) {
    errorResponse('Invalid request');
}

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

$otpCollection = getCollection('otp_verifications');
if (!$otpCollection) {
    errorResponse('Database connection error', 500);
}

$otpRecord = $otpCollection->findOne(
    [
        'user_id' => $user['_id'],
        'otp_purpose' => $purpose,
        'is_used' => false
    ],
    ['sort' => ['expires_at' => -1]]
);
if (!$otpRecord) {
    errorResponse('Invalid or expired OTP');
}

$expires = mongoDateToPHP($otpRecord['expires_at']);
if (new DateTime() > $expires) {
    errorResponse('OTP has expired');
}
if (($otpRecord['otp_code'] ?? '') !== $otp) {
    errorResponse('Invalid OTP');
}

$otpCollection->updateOne(
    ['_id' => $otpRecord['_id']],
    ['$set' => ['is_used' => true]]
);

if ($purpose === 'verify_email') {
    $collection->updateOne(
        ['_id' => $user['_id']],
        ['$set' => ['email_verified' => true, 'updated_at' => phpDateToMongo()]]
    );
}

logActivity('otp_verified', (string)$user['_id'], ['purpose' => $purpose]);
successResponse(null, 'OTP verified successfully');
