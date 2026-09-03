<?php
/**
 * Verify email endpoint (legacy alias).
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$data = getRequestData();
$userIdInput = sanitizeInput($data['user_id'] ?? '');
$token = sanitizeInput($data['token'] ?? '');

if (!isValidObjectId($userIdInput) || empty($token)) {
    errorResponse('Invalid request');
}

$collection = getCollection('users');
if (!$collection) {
    errorResponse('Database connection error', 500);
}

$user = $collection->findOne([
    '_id' => new MongoDB\BSON\ObjectId($userIdInput),
    'deleted_at' => null
]);
if (!$user) {
    errorResponse('User not found', 404);
}

if (($user['reset_token'] ?? null) !== $token) {
    errorResponse('Invalid verification token');
}
if (isset($user['reset_token_expires'])) {
    $expires = mongoDateToPHP($user['reset_token_expires']);
    if (new DateTime() > $expires) {
        errorResponse('Verification token has expired');
    }
}

$collection->updateOne(
    ['_id' => $user['_id']],
    [
        '$set' => [
            'email_verified' => true,
            'reset_token' => null,
            'reset_token_expires' => null,
            'updated_at' => phpDateToMongo()
        ]
    ]
);

logActivity('email_verified', (string)$user['_id']);
successResponse(null, 'Email verified successfully');
