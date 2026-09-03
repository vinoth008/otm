<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$data = getRequestData();
if (!$data) {
    errorResponse('Invalid request format');
}

$type = sanitizeInput($data['type'] ?? '');
$category = sanitizeInput($data['category'] ?? '');
$amount = $data['amount'] ?? 0;
$description = sanitizeInput($data['description'] ?? '');
$paymentMethod = sanitizeInput($data['payment_method'] ?? 'cash');
$date = $data['date'] ?? date('Y-m-d');
$notes = sanitizeInput($data['notes'] ?? '');
$walletId = $data['wallet_id'] ?? '';

$validTypes = ['income', 'expense', 'transfer'];
if (!in_array($type, $validTypes, true)) {
    errorResponse('Invalid transaction type');
}

if (empty($category)) {
    errorResponse('Category is required');
}

if (!validateAmount($amount) || (float)$amount <= 0) {
    errorResponse('Amount must be greater than 0');
}

if (!validateDate($date)) {
    errorResponse('Invalid date format');
}

$role = getCurrentUserRole();
$userId = getCurrentUserId();

$targetUserId = $userId;
if (in_array($role, ['admin', 'staff', 'receptionist'], true) && !empty($data['user_id']) && isValidObjectId($data['user_id'])) {
    $targetUserId = $data['user_id'];
}

$col = getCollection('transactions');
if (!$col) {
    errorResponse('Database connection error');
}

$doc = [
    'user_id' => new MongoDB\BSON\ObjectId($targetUserId),
    'type' => $type,
    'category' => $category,
    'amount' => (float)$amount,
    'currency' => sanitizeInput($data['currency'] ?? 'INR'),
    'description' => $description,
    'date' => new MongoDB\BSON\UTCDateTime(strtotime($date) * 1000),
    'payment_method' => $paymentMethod,
    'notes' => $notes,
    'created_at' => phpDateToMongo(),
    'updated_at' => phpDateToMongo(),
    'deleted_at' => null
];

$result = $col->insertOne($doc);
if (!$result->getInsertedId()) {
    errorResponse('Failed to create transaction');
}

$transactionId = (string)$result->getInsertedId();

if ($type === 'expense') {
    $users = getCollection('users');
    if ($users) {
        $users->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($targetUserId)],
            ['$inc' => ['balance' => -(float)$amount], '$set' => ['updated_at' => phpDateToMongo()]]
        );
    }

    if (!empty($walletId) && isValidObjectId($walletId)) {
        $wallets = getCollection('wallets');
        if ($wallets) {
            $wallets->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($walletId), 'user_id' => new MongoDB\BSON\ObjectId($targetUserId)],
                ['$inc' => ['balance' => -(float)$amount], '$set' => ['updated_at' => phpDateToMongo()]]
            );
        }
    }
} elseif ($type === 'income') {
    $users = getCollection('users');
    if ($users) {
        $users->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($targetUserId)],
            ['$inc' => ['balance' => (float)$amount], '$set' => ['updated_at' => phpDateToMongo()]]
        );
    }

    if (!empty($walletId) && isValidObjectId($walletId)) {
        $wallets = getCollection('wallets');
        if ($wallets) {
            $wallets->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($walletId), 'user_id' => new MongoDB\BSON\ObjectId($targetUserId)],
                ['$inc' => ['balance' => (float)$amount], '$set' => ['updated_at' => phpDateToMongo()]]
            );
        }
    }
}

logActivity('transaction_created', getCurrentUserId(), [
    'transaction_id' => $transactionId,
    'type' => $type,
    'amount' => (float)$amount,
    'category' => $category
]);

successResponse([
    'transaction_id' => $transactionId,
    'type' => $type,
    'amount' => (float)$amount
], 'Transaction created successfully');
