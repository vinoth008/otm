<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$transactionId = $_GET['transaction_id'] ?? $_GET['id'] ?? '';
if (!isValidObjectId($transactionId)) {
    errorResponse('Invalid transaction ID');
}

$role = getCurrentUserRole();
$userId = getCurrentUserId();

$col = getCollection('transactions');
if (!$col) {
    errorResponse('Database connection error');
}

$filter = [
    '_id' => new MongoDB\BSON\ObjectId($transactionId),
    'deleted_at' => null
];

if (!in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $filter['user_id'] = new MongoDB\BSON\ObjectId($userId);
}

$t = $col->findOne($filter);
if (!$t) {
    errorResponse('Transaction not found');
}

successResponse([
    'receipt' => [
        'id' => (string)$t['_id'],
        'amount' => (float)($t['amount'] ?? 0),
        'category' => $t['category'] ?? '',
        'type' => $t['type'] ?? '',
        'date' => isset($t['date']) ? mongoDateToPHP($t['date'])->format('Y-m-d') : '',
        'payment_method' => $t['payment_method'] ?? '',
        'description' => $t['description'] ?? '',
        'notes' => $t['notes'] ?? null,
        'status' => ($t['deleted_at'] ?? null) !== null ? 'deleted' : 'active',
        'created_at' => isset($t['created_at']) ? mongoDateToPHP($t['created_at'])->format('Y-m-d H:i:s') : ''
    ]
], 'Receipt retrieved');
