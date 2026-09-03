<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' || ($action !== 'create' && $action !== 'update' && $action !== 'delete')) {
    getWalletSummary();
} else {
    switch ($action) {
        case 'create': createWallet(); break;
        case 'update': updateWallet(); break;
        case 'delete': deleteWallet(); break;
        default: errorResponse('Invalid action');
    }
}

function getWalletSummary() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) errorResponse('Invalid user session', 401);
    $collection = getCollection('wallets');
    if (!$collection) errorResponse('Database connection error');
    $wallets = $collection->find(
        ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null],
        ['sort' => ['created_at' => -1]]
    )->toArray();
    $formatted = [];
    $totalBalance = 0;
    foreach ($wallets as $w) {
        $bal = round((float)($w['balance'] ?? 0), 2);
        $totalBalance += $bal;
        $formatted[] = [
            '_id' => (string)$w['_id'],
            'name' => $w['name'] ?? '',
            'balance' => $bal,
            'currency' => $w['currency'] ?? 'INR',
            'created_at' => mongoDateToPHP($w['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse([
        'wallets' => $formatted,
        'total_balance' => round($totalBalance, 2),
        'count' => count($formatted)
    ], 'Wallets retrieved');
}

function createWallet() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) errorResponse('Invalid user session', 401);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $name = sanitizeInput($data['name'] ?? '');
    $initialBalance = (float)($data['initial_balance'] ?? 0);
    $currency = sanitizeInput($data['currency'] ?? 'INR');
    if (empty($name)) errorResponse('Wallet name is required');
    if ($initialBalance < 0) errorResponse('Initial balance cannot be negative');
    $collection = getCollection('wallets');
    if (!$collection) errorResponse('Database connection error');
    $userObjectId = new MongoDB\BSON\ObjectId($userId);
    $existing = $collection->findOne(['user_id' => $userObjectId, 'name' => $name, 'deleted_at' => null]);
    if ($existing) errorResponse('A wallet with this name already exists');
    $doc = [
        'user_id' => $userObjectId,
        'name' => $name,
        'balance' => $initialBalance,
        'currency' => $currency,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create wallet');
    $walletId = (string)$result->getInsertedId();
    logActivity('wallet_created', $userId, ['wallet_id' => $walletId, 'name' => $name]);
    successResponse(['wallet_id' => $walletId], 'Wallet created successfully');
}

function updateWallet() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) errorResponse('Invalid user session', 401);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $walletId = $data['wallet_id'] ?? '';
    if (!isValidObjectId($walletId)) errorResponse('Invalid wallet ID');
    $collection = getCollection('wallets');
    if (!$collection) errorResponse('Database connection error');
    $userObjectId = new MongoDB\BSON\ObjectId($userId);
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($walletId), 'user_id' => $userObjectId, 'deleted_at' => null]);
    if (!$existing) errorResponse('Wallet not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['name'])) {
        $name = sanitizeInput($data['name']);
        if (empty($name)) errorResponse('Wallet name cannot be empty');
        $dup = $collection->findOne([
            'user_id' => $userObjectId, 'name' => $name,
            '_id' => ['$ne' => new MongoDB\BSON\ObjectId($walletId)], 'deleted_at' => null
        ]);
        if ($dup) errorResponse('A wallet with this name already exists');
        $updateData['name'] = $name;
    }
    if (isset($data['currency'])) $updateData['currency'] = sanitizeInput($data['currency']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($walletId)], ['$set' => $updateData]);
    logActivity('wallet_updated', $userId, ['wallet_id' => $walletId]);
    successResponse(['wallet_id' => $walletId], 'Wallet updated successfully');
}

function deleteWallet() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) errorResponse('Invalid user session', 401);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $walletId = $data['wallet_id'] ?? '';
    if (!isValidObjectId($walletId)) errorResponse('Invalid wallet ID');
    $collection = getCollection('wallets');
    if (!$collection) errorResponse('Database connection error');
    $userObjectId = new MongoDB\BSON\ObjectId($userId);
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($walletId), 'user_id' => $userObjectId, 'deleted_at' => null]);
    if (!$existing) errorResponse('Wallet not found');
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($walletId)],
        ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]
    );
    logActivity('wallet_deleted', $userId, ['wallet_id' => $walletId]);
    successResponse(null, 'Wallet deleted successfully');
}
