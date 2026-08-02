<?php
// backend/php/wallet_crud.php
/**
 * Wallet Management for Smart Transaction Control
 * Handles digital wallet balance, top-ups, and wallet history
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/session_manager.php';
// Prevent direct access
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
/**
 * Get the current user's wallet, creating one if missing
 * @return array|null
 */
function getOrCreateWallet() {
    $collection = getCollection('wallets');
    if (!$collection) {
        return null;
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $wallet = $collection->findOne(['user_id' => $userId]);
    if (!$wallet) {
        $result = $collection->insertOne([
            'user_id' => $userId,
            'balance' => 0,
            'currency' => 'INR',
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]);
        $wallet = $collection->findOne(['_id' => $result->getInsertedId()]);
    }
    return $wallet;
}
/**
 * Get wallet balance and stats
 * GET
 */
function getWalletBalance() {
    requireActiveSession();
    $wallet = getOrCreateWallet();
    if (!$wallet) {
        errorResponse('Database connection error');
    }
    $transfers = getCollection('transfers');
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $incoming = $transfers ? $transfers->countDocuments([
        'to_user_id' => $userId,
        'status' => 'completed'
    ]) : 0;
    $outgoing = $transfers ? $transfers->countDocuments([
        'from_user_id' => $userId,
        'status' => 'completed'
    ]) : 0;
    successResponse([
        'balance' => round((float)($wallet['balance'] ?? 0), 2),
        'currency' => $wallet['currency'] ?? 'INR',
        'incoming_transfers' => $incoming,
        'outgoing_transfers' => $outgoing
    ], 'Wallet balance retrieved');
}
/**
 * Add funds to wallet (top up)
 * POST: amount, description
 */
function topUpWallet() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $amount = (float)($data['amount'] ?? 0);
    if ($amount <= 0 || !validateAmount((string)$amount)) {
        errorResponse('Enter a valid amount');
    }
    $collection = getCollection('wallets');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $wallet = getOrCreateWallet();
    $newBalance = round((float)($wallet['balance'] ?? 0) + $amount, 2);
    $collection->updateOne(
        ['_id' => $wallet['_id']],
        ['$set' => ['balance' => $newBalance, 'updated_at' => phpDateToMongo()]]
    );
    // Log to wallet history via transfers collection (type=topup)
    $transfers = getCollection('transfers');
    if ($transfers) {
        $transfers->insertOne([
            'from_user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'to_user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'amount' => $amount,
            'type' => 'topup',
            'status' => 'completed',
            'description' => sanitizeInput($data['description'] ?? 'Wallet top-up'),
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo(),
            'deleted_at' => null
        ]);
    }
    logActivity('wallet_topup', getCurrentUserId(), ['amount' => $amount]);
    successResponse(['balance' => $newBalance], 'Wallet topped up successfully');
}
/**
 * Get wallet activity history
 * GET
 */
function getWalletHistory() {
    requireActiveSession();
    $transfers = getCollection('transfers');
    if (!$transfers) {
        errorResponse('Database connection error');
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $cursor = $transfers->find(
        [
            '$or' => [
                ['from_user_id' => $userId],
                ['to_user_id' => $userId]
            ],
            'deleted_at' => null
        ],
        ['sort' => ['created_at' => -1], 'limit' => 50]
    );
    $history = [];
    foreach ($cursor as $t) {
        $history[] = [
            'transfer_id' => (string)$t['_id'],
            'type' => $t['type'] ?? 'transfer',
            'status' => $t['status'] ?? 'pending',
            'amount' => round((float)($t['amount'] ?? 0), 2),
            'direction' => isset($t['from_user_id']) && (string)$t['from_user_id'] === getCurrentUserId() ? 'out' : 'in',
            'description' => $t['description'] ?? '',
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['history' => $history], 'Wallet history retrieved');
}
/**
 * Transfer money from wallet to another user
 * POST: recipient_email, amount, description
 */
function transferFromWallet() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $recipientEmail = strtolower(trim($data['recipient_email'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    if (empty($recipientEmail) || !validateEmail($recipientEmail)) {
        errorResponse('Enter a valid recipient email');
    }
    if ($amount <= 0 || !validateAmount((string)$amount)) {
        errorResponse('Enter a valid amount');
    }
    if ($recipientEmail === strtolower(getCurrentUserEmail())) {
        errorResponse('You cannot transfer money to yourself');
    }
    $users = getCollection('users');
    $recipient = $users->findOne(['email' => $recipientEmail, 'deleted_at' => null]);
    if (!$recipient) {
        errorResponse('Recipient not found');
    }
    $wallet = getOrCreateWallet();
    $balance = (float)($wallet['balance'] ?? 0);
    if ($amount > $balance) {
        errorResponse('Insufficient wallet balance');
    }
    $walletCollection = getCollection('wallets');
    $transfers = getCollection('transfers');
    if (!$walletCollection || !$transfers) {
        errorResponse('Database connection error');
    }
    $recipientWallet = $walletCollection->findOne(['user_id' => $recipient['_id']]);
    if (!$recipientWallet) {
        $walletCollection->insertOne([
            'user_id' => $recipient['_id'],
            'balance' => 0,
            'currency' => 'INR',
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]);
        $recipientWallet = $walletCollection->findOne(['user_id' => $recipient['_id']]);
    }
    // Debit sender, credit recipient atomically
    $client = getMongoClient();
    if (!$client) {
        errorResponse('Database connection error');
    }
    $db = $client->selectDatabase(DB_NAME);
    $session = $client->startSession();
    $session->startTransaction();
    try {
        $db->selectCollection('wallets')->updateOne(
            ['_id' => $wallet['_id']],
            ['$inc' => ['balance' => -$amount], '$set' => ['updated_at' => phpDateToMongo()]],
            ['session' => $session]
        );
        $db->selectCollection('wallets')->updateOne(
            ['_id' => $recipientWallet['_id']],
            ['$inc' => ['balance' => $amount], '$set' => ['updated_at' => phpDateToMongo()]],
            ['session' => $session]
        );
        $db->selectCollection('transfers')->insertOne([
            'from_user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'to_user_id' => $recipient['_id'],
            'amount' => $amount,
            'type' => 'transfer',
            'status' => 'completed',
            'description' => sanitizeInput($data['description'] ?? 'Wallet transfer'),
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo(),
            'deleted_at' => null
        ], ['session' => $session]);
        $session->commitTransaction();
    } catch (Exception $e) {
        $session->abortTransaction();
        error_log('Wallet transfer failed: ' . $e->getMessage());
        errorResponse('Transfer failed. Please try again.', 500);
    } finally {
        $session->endSession();
    }
    logActivity('wallet_transfer', getCurrentUserId(), [
        'amount' => $amount,
        'recipient_email' => $recipientEmail
    ]);
    successResponse([
        'balance' => round($balance - $amount, 2),
        'recipient' => $recipient['first_name'] . ' ' . $recipient['last_name']
    ], 'Transfer completed successfully');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_balance':
        if ($method === 'GET') getWalletBalance();
        break;
    case 'topup':
        if ($method === 'POST') topUpWallet();
        break;
    case 'history':
        if ($method === 'GET') getWalletHistory();
        break;
    case 'transfer':
        if ($method === 'POST') transferFromWallet();
        break;
    default:
        errorResponse('Invalid action');
}
?>
