<?php
declare(strict_types=1);
// Wallets API - Multiple wallets, balance management, transfers
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createWallet(); break;
    case 'get_all': $method === 'GET' && getWallets(); break;
    case 'get': $method === 'GET' && getWallet(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateWallet(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteWallet(); break;
    case 'transfer': $method === 'POST' && transferBetweenWallets(); break;
    case 'history': $method === 'GET' && getWalletHistory(); break;
    default: errorResponse('Invalid action', 404);
}

/**
 * Create a new wallet for the current user.
 * Fields: name, balance (initial), currency, icon, color, description, is_default
 */
function createWallet() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $name = sanitizeInput($data['name'] ?? '');
    $balance = (float)($data['balance'] ?? 0);
    $currency = sanitizeInput($data['currency'] ?? 'INR');
    $icon = sanitizeInput($data['icon'] ?? 'fa-wallet');
    $color = sanitizeInput($data['color'] ?? '#6c5ce7');
    $description = sanitizeInput($data['description'] ?? '');
    $isDefault = !empty($data['is_default']);
    if (empty($name)) errorResponse('Wallet name is required');
    if ($balance < 0) errorResponse('Initial balance cannot be negative');
    $collection = getCollection('wallets');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'name' => $name,
        'deleted_at' => null
    ]);
    if ($existing) errorResponse('A wallet with this name already exists');
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    // If this is the first wallet, make it default
    $walletCount = $collection->countDocuments(['user_id' => $userId, 'deleted_at' => null]);
    if ($walletCount === 0) $isDefault = true;
    // If setting as default, unset existing default
    if ($isDefault) {
        $collection->updateMany(
            ['user_id' => $userId, 'is_default' => true],
            ['$set' => ['is_default' => false, 'updated_at' => phpDateToMongo()]]
        );
    }
    $doc = [
        'user_id' => $userId,
        'name' => $name,
        'balance' => $balance,
        'currency' => $currency,
        'icon' => $icon,
        'color' => $color,
        'description' => $description,
        'is_default' => $isDefault,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create wallet');
    $walletId = (string)$result->getInsertedId();
    // Record initial deposit as a transaction if balance > 0
    if ($balance > 0) {
        $txCollection = getCollection('transactions');
        if ($txCollection) {
            $txCollection->insertOne([
                'user_id' => $userId,
                'type' => 'income',
                'category' => 'Opening Balance',
                'amount' => $balance,
                'currency' => $currency,
                'description' => 'Opening balance for ' . $name,
                'date' => phpDateToMongo(),
                'payment_method' => 'wallet',
                'wallet_id' => $result->getInsertedId(),
                'created_at' => phpDateToMongo(),
                'updated_at' => phpDateToMongo(),
                'deleted_at' => null
            ]);
        }
    }
    logActivity('wallet_created', getCurrentUserId(), ['wallet_id' => $walletId, 'name' => $name]);
    successResponse(['wallet_id' => $walletId], 'Wallet created successfully');
}

/**
 * Get all wallets for the current user, with total balance.
 */
function getWallets() {
    requireActiveSession();
    $collection = getCollection('wallets');
    if (!$collection) errorResponse('Database connection error');
    $wallets = $collection->find(
        ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null],
        ['sort' => ['is_default' => -1, 'created_at' => 1]]
    )->toArray();
    $formatted = array_map(function($w) {
        return [
            '_id' => (string)$w['_id'],
            'name' => $w['name'],
            'balance' => round((float)($w['balance'] ?? 0), 2),
            'currency' => $w['currency'] ?? 'INR',
            'icon' => $w['icon'] ?? 'fa-wallet',
            'color' => $w['color'] ?? '#6c5ce7',
            'description' => $w['description'] ?? '',
            'is_default' => !empty($w['is_default']),
            'created_at' => mongoDateToPHP($w['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }, $wallets);
    $totalBalance = array_sum(array_column($formatted, 'balance'));
    successResponse(['wallets' => $formatted, 'total_balance' => round($totalBalance, 2)]);
}

/**
 * Get a single wallet by ID.
 */
function getWallet() {
    requireActiveSession();
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid wallet ID');
    $collection = getCollection('wallets');
    $w = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$w) errorResponse('Wallet not found');
    successResponse([
        '_id' => (string)$w['_id'],
        'name' => $w['name'],
        'balance' => round((float)($w['balance'] ?? 0), 2),
        'currency' => $w['currency'] ?? 'INR',
        'icon' => $w['icon'] ?? 'fa-wallet',
        'color' => $w['color'] ?? '#6c5ce7',
        'description' => $w['description'] ?? '',
        'is_default' => !empty($w['is_default']),
        'created_at' => mongoDateToPHP($w['created_at'] ?? null)->format('Y-m-d H:i:s')
    ]);
}

/**
 * Update wallet details (name, icon, color, description, is_default).
 */
function updateWallet() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid wallet ID');
    $collection = getCollection('wallets');
    $existing = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$existing) errorResponse('Wallet not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['name'])) {
        $name = sanitizeInput($data['name']);
        if (empty($name)) errorResponse('Wallet name is required');
        $dup = $collection->findOne([
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'name' => $name,
            '_id' => ['$ne' => new MongoDB\BSON\ObjectId($id)],
            'deleted_at' => null
        ]);
        if ($dup) errorResponse('A wallet with this name already exists');
        $updateData['name'] = $name;
    }
    if (isset($data['icon'])) $updateData['icon'] = sanitizeInput($data['icon']);
    if (isset($data['color'])) $updateData['color'] = sanitizeInput($data['color']);
    if (isset($data['description'])) $updateData['description'] = sanitizeInput($data['description']);
    if (isset($data['is_default'])) {
        $isDefault = !empty($data['is_default']);
        if ($isDefault) {
            // Unset any existing default for this user
            $collection->updateMany(
                ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'is_default' => true],
                ['$set' => ['is_default' => false, 'updated_at' => phpDateToMongo()]]
            );
        }
        $updateData['is_default'] = $isDefault;
    }
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('wallet_updated', getCurrentUserId(), ['wallet_id' => $id]);
    successResponse(['wallet_id' => $id, 'updated' => true], 'Wallet updated successfully');
}

/**
 * Delete a wallet (soft delete).
 * Optionally transfer balance to another wallet first.
 */
function deleteWallet() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid wallet ID');
    $collection = getCollection('wallets');
    $w = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$w) errorResponse('Wallet not found');
    // If balance exists, try to transfer to another wallet or block deletion
    $balance = (float)($w['balance'] ?? 0);
    if ($balance > 0) {
        $transferTo = $data['transfer_to'] ?? null;
        if (!empty($transferTo) && isValidObjectId($transferTo) && $transferTo !== $id) {
            $target = $collection->findOne([
                '_id' => new MongoDB\BSON\ObjectId($transferTo),
                'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
                'deleted_at' => null
            ]);
            if (!$target) errorResponse('Target wallet not found');
            $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($transferTo)],
                ['$inc' => ['balance' => $balance], '$set' => ['updated_at' => phpDateToMongo()]]
            );
        } else {
            errorResponse('Cannot delete wallet with balance. Transfer balance to another wallet first.');
        }
    }
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], [
        '$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]
    ]);
    logActivity('wallet_deleted', getCurrentUserId(), ['wallet_id' => $id]);
    successResponse(null, 'Wallet deleted successfully');
}

/**
 * Transfer money between two wallets.
 * Fields: from_wallet_id, to_wallet_id, amount, description
 */
function transferBetweenWallets() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $fromId = $data['from_wallet_id'] ?? '';
    $toId = $data['to_wallet_id'] ?? '';
    $amount = (float)($data['amount'] ?? 0);
    $description = sanitizeInput($data['description'] ?? 'Wallet transfer');
    if (!isValidObjectId($fromId)) errorResponse('Invalid source wallet ID');
    if (!isValidObjectId($toId)) errorResponse('Invalid destination wallet ID');
    if ($fromId === $toId) errorResponse('Source and destination wallets must be different');
    if ($amount <= 0) errorResponse('Transfer amount must be greater than 0');
    $collection = getCollection('wallets');
    if (!$collection) errorResponse('Database connection error');
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $fromWallet = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($fromId), 'user_id' => $userId, 'deleted_at' => null]);
    if (!$fromWallet) errorResponse('Source wallet not found');
    $toWallet = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($toId), 'user_id' => $userId, 'deleted_at' => null]);
    if (!$toWallet) errorResponse('Destination wallet not found');
    if ((float)($fromWallet['balance'] ?? 0) < $amount) errorResponse('Insufficient balance in source wallet');
    // Atomic balance updates
    $collection->updateOne(['_id' => $fromWallet['_id']], ['$inc' => ['balance' => -$amount], '$set' => ['updated_at' => phpDateToMongo()]]);
    $collection->updateOne(['_id' => $toWallet['_id']], ['$inc' => ['balance' => $amount], '$set' => ['updated_at' => phpDateToMongo()]]);
    // Record transfer transactions
    $txCollection = getCollection('transactions');
    if ($txCollection) {
        $now = phpDateToMongo();
        $txCollection->insertMany([
            [
                'user_id' => $userId,
                'type' => 'transfer',
                'category' => 'Wallet Transfer',
                'amount' => $amount,
                'currency' => $fromWallet['currency'] ?? 'INR',
                'description' => $description . ' (to ' . ($toWallet['name'] ?? '') . ')',
                'date' => $now,
                'payment_method' => 'wallet',
                'wallet_id' => $fromWallet['_id'],
                'transfer_to_wallet_id' => $toWallet['_id'],
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null
            ],
            [
                'user_id' => $userId,
                'type' => 'transfer',
                'category' => 'Wallet Transfer',
                'amount' => $amount,
                'currency' => $toWallet['currency'] ?? 'INR',
                'description' => $description . ' (from ' . ($fromWallet['name'] ?? '') . ')',
                'date' => $now,
                'payment_method' => 'wallet',
                'wallet_id' => $toWallet['_id'],
                'transfer_from_wallet_id' => $fromWallet['_id'],
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null
            ]
        ]);
    }
    // Record a transfer record in wallet_transfers collection for history
    $transfersCollection = getCollection('wallet_transfers');
    if ($transfersCollection) {
        $transfersCollection->insertOne([
            'user_id' => $userId,
            'from_wallet_id' => $fromWallet['_id'],
            'to_wallet_id' => $toWallet['_id'],
            'from_wallet_name' => $fromWallet['name'],
            'to_wallet_name' => $toWallet['name'],
            'amount' => $amount,
            'description' => $description,
            'created_at' => $now ?? phpDateToMongo(),
            'deleted_at' => null
        ]);
    }
    logActivity('wallet_transfer', getCurrentUserId(), ['from' => $fromId, 'to' => $toId, 'amount' => $amount]);
    successResponse(null, 'Transfer completed successfully');
}

/**
 * Get wallet transfer history.
 */
function getWalletHistory() {
    requireActiveSession();
    $walletId = $_GET['wallet_id'] ?? null;
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $transfersCollection = getCollection('wallet_transfers');
    if (!$transfersCollection) errorResponse('Database connection error');
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    if ($walletId && isValidObjectId($walletId)) {
        $filter['$or'] = [
            ['from_wallet_id' => new MongoDB\BSON\ObjectId($walletId)],
            ['to_wallet_id' => new MongoDB\BSON\ObjectId($walletId)]
        ];
    }
    $transfers = $transfersCollection->find($filter, ['sort' => ['created_at' => -1], 'limit' => $limit])->toArray();
    $formatted = array_map(function($t) {
        return [
            '_id' => (string)$t['_id'],
            'from_wallet_name' => $t['from_wallet_name'] ?? '',
            'to_wallet_name' => $t['to_wallet_name'] ?? '',
            'amount' => round((float)($t['amount'] ?? 0), 2),
            'description' => $t['description'] ?? '',
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }, $transfers);
    successResponse(['transfers' => $formatted]);
}