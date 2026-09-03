<?php
/**
 * Current logged-in user profile endpoint.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();
$userId = getCurrentUserId();
if (!$userId || !isValidObjectId($userId)) {
    errorResponse('Invalid user session', 401);
}

$collection = getCollection('users');
$user = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
if (!$user) {
    errorResponse('User not found', 404);
}

successResponse([
    'user_id' => (string)$user['_id'],
    'first_name' => $user['first_name'] ?? '',
    'last_name' => $user['last_name'] ?? '',
    'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
    'email' => $user['email'] ?? '',
    'phone' => $user['phone'] ?? '',
    'role' => normalizeRole($user['role'] ?? 'customer'),
    'account_number' => $user['account_number'] ?? '',
    'account_type' => $user['account_type'] ?? '',
    'balance' => $user['balance'] ?? 0,
    'status' => $user['status'] ?? 'active',
    'created_at' => isset($user['created_at']) ? mongoDateToPHP($user['created_at'])->format('Y-m-d H:i:s') : null
], 'Profile loaded');
