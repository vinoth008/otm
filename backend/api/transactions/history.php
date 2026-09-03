<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$role = getCurrentUserRole();
$userId = getCurrentUserId();

$targetUserId = $userId;
if (in_array($role, ['admin', 'staff', 'receptionist'], true) && !empty($_GET['user_id']) && isValidObjectId($_GET['user_id'])) {
    $targetUserId = $_GET['user_id'];
}

$col = getCollection('transactions');
if (!$col) {
    errorResponse('Database connection error');
}

$filter = [
    'user_id' => new MongoDB\BSON\ObjectId($targetUserId),
    'deleted_at' => null
];

$items = $col->find($filter, [
    'sort' => ['date' => -1, 'created_at' => -1]
])->toArray();

$formatted = array_map(function ($t) {
    return [
        '_id' => (string)$t['_id'],
        'type' => $t['type'] ?? '',
        'category' => $t['category'] ?? '',
        'amount' => (float)($t['amount'] ?? 0),
        'description' => $t['description'] ?? '',
        'date' => isset($t['date']) ? mongoDateToPHP($t['date'])->format('Y-m-d') : '',
        'payment_method' => $t['payment_method'] ?? '',
        'notes' => $t['notes'] ?? null,
        'created_at' => isset($t['created_at']) ? mongoDateToPHP($t['created_at'])->format('Y-m-d H:i:s') : ''
    ];
}, $items);

successResponse([
    'transactions' => $formatted,
    'total_count' => count($formatted)
], 'Transaction history retrieved');
