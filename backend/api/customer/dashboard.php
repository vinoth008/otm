<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

requireActiveSession();

$userId = getCurrentUserId();
if (!isValidObjectId($userId)) {
    errorResponse('Invalid user session', 401);
}

$users = getCollection('users');
if (!$users) errorResponse('Database connection error');

$user = $users->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
if (!$user) errorResponse('User not found');

$name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

$wallets = getCollection('wallets');
$walletList = [];
$walletCount = 0;
$walletTotalBalance = 0;
if ($wallets) {
    $cursor = $wallets->find(
        ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null],
        ['sort' => ['created_at' => -1]]
    );
    foreach ($cursor as $w) {
        $bal = round((float)($w['balance'] ?? 0), 2);
        $walletTotalBalance += $bal;
        $walletList[] = [
            '_id' => (string)$w['_id'],
            'name' => $w['name'] ?? '',
            'balance' => $bal,
            'currency' => $w['currency'] ?? 'INR'
        ];
    }
    $walletCount = count($walletList);
}

$txCollection = getCollection('transactions');
$recentTransactions = [];
$totalIncome = 0;
$totalExpense = 0;
$categories = [];

if ($txCollection) {
    $userObjectId = new MongoDB\BSON\ObjectId($userId);
    $txFilter = ['user_id' => $userObjectId, 'deleted_at' => null];

    $recent = $txCollection->find($txFilter, [
        'sort' => ['date' => -1, 'created_at' => -1],
        'limit' => 8
    ]);
    foreach ($recent as $t) {
        $recentTransactions[] = [
            '_id' => (string)$t['_id'],
            'type' => $t['type'] ?? '',
            'category' => $t['category'] ?? '',
            'amount' => round((float)($t['amount'] ?? 0), 2),
            'description' => $t['description'] ?? '',
            'date' => mongoDateToPHP($t['date'] ?? null)->format('Y-m-d'),
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }

    $incomeResult = $txCollection->aggregate([
        ['$match' => array_merge($txFilter, ['type' => 'income'])],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
    ])->toArray();
    $totalIncome = round((float)($incomeResult[0]['total'] ?? 0), 2);

    $expenseResult = $txCollection->aggregate([
        ['$match' => array_merge($txFilter, ['type' => 'expense'])],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
    ])->toArray();
    $totalExpense = round((float)($expenseResult[0]['total'] ?? 0), 2);

    $catResult = $txCollection->aggregate([
        ['$match' => array_merge($txFilter, ['type' => 'expense'])],
        ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount']]],
        ['$sort' => ['total' => -1]]
    ])->toArray();
    foreach ($catResult as $c) {
        $categories[] = [
            'category' => $c['_id'],
            'total' => round((float)$c['total'], 2)
        ];
    }
}

$notifCollection = getCollection('notifications');
$unreadNotifications = [];
if ($notifCollection) {
    $ncursor = $notifCollection->find(
        ['user_id' => new MongoDB\BSON\ObjectId($userId), 'read' => false],
        ['sort' => ['created_at' => -1], 'limit' => 5]
    );
    foreach ($ncursor as $n) {
        $unreadNotifications[] = [
            '_id' => (string)$n['_id'],
            'title' => $n['title'] ?? '',
            'message' => $n['message'] ?? '',
            'type' => $n['type'] ?? '',
            'created_at' => mongoDateToPHP($n['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
}

successResponse([
    'name' => $name,
    'email' => $user['email'] ?? '',
    'role' => $user['role'] ?? 'customer',
    'balance' => round((float)($user['balance'] ?? 0), 2),
    'account_number' => $user['account_number'] ?? '',
    'wallets' => [
        'count' => $walletCount,
        'total_balance' => round($walletTotalBalance, 2),
        'list' => $walletList
    ],
    'recent_transactions' => $recentTransactions,
    'totals' => [
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'net' => round($totalIncome - $totalExpense, 2)
    ],
    'categories' => $categories,
    'notifications' => $unreadNotifications
], 'Dashboard loaded');
