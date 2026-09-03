<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

requireRole(['admin']);

$usersCollection = getCollection('users');
$txCollection = getCollection('transactions');
$walletCollection = getCollection('wallets');

if (!$usersCollection || !$txCollection) {
    errorResponse('Database connection error');
}

$totalUsers = $usersCollection->countDocuments(['deleted_at' => null]);
$activeUsers = $usersCollection->countDocuments(['deleted_at' => null, 'status' => 'active']);
$totalTransactions = $txCollection->countDocuments();

$incomeAgg = $txCollection->aggregate([
    ['$match' => ['type' => 'income']],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
])->toArray();
$totalIncome = $incomeAgg[0]['total'] ?? 0;

$expenseAgg = $txCollection->aggregate([
    ['$match' => ['type' => 'expense']],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
])->toArray();
$totalExpense = $expenseAgg[0]['total'] ?? 0;

$totalAmountAgg = $txCollection->aggregate([
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
])->toArray();
$totalAmount = $totalAmountAgg[0]['total'] ?? 0;

$totalWallets = $walletCollection ? $walletCollection->countDocuments() : 0;

$roleDistAgg = $usersCollection->aggregate([
    ['$match' => ['deleted_at' => null]],
    ['$group' => ['_id' => '$role', 'count' => ['$sum' => 1]]]
])->toArray();
$roleDistribution = [];
foreach ($roleDistAgg as $row) {
    $roleDistribution[$row['_id'] ?: 'customer'] = $row['count'];
}

$recentUsers = [];
$recentUserCursor = $usersCollection->find(
    ['deleted_at' => null],
    ['sort' => ['created_at' => -1], 'limit' => 5]
);
foreach ($recentUserCursor as $u) {
    $recentUsers[] = [
        'user_id' => (string)$u['_id'],
        'full_name' => ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''),
        'email' => $u['email'] ?? '',
        'role' => $u['role'] ?? 'customer',
        'status' => $u['status'] ?? 'active',
        'created_at' => isset($u['created_at']) ? mongoDateToPHP($u['created_at'])->format('Y-m-d H:i:s') : ''
    ];
}

$recentTransactions = [];
$recentTxCursor = $txCollection->find(
    [],
    ['sort' => ['created_at' => -1], 'limit' => 8]
);
foreach ($recentTxCursor as $tx) {
    $uid = isset($tx['user_id']) ? (string)$tx['user_id'] : null;
    $userName = '';
    if ($uid) {
        $u = $usersCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($uid)]);
        if ($u) {
            $userName = ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '');
        }
    }
    $recentTransactions[] = [
        'transaction_id' => (string)$tx['_id'],
        'user_id' => $uid,
        'userName' => $userName,
        'type' => $tx['type'] ?? '',
        'amount' => $tx['amount'] ?? 0,
        'category' => $tx['category'] ?? '',
        'created_at' => isset($tx['created_at']) ? mongoDateToPHP($tx['created_at'])->format('Y-m-d H:i:s') : ''
    ];
}

successResponse([
    'total_users' => $totalUsers,
    'active_users' => $activeUsers,
    'total_transactions' => $totalTransactions,
    'total_income' => $totalIncome,
    'total_expenses' => $totalExpense,
    'total_amount' => $totalAmount,
    'total_wallets' => $totalWallets,
    'role_distribution' => $roleDistribution,
    'recent_users' => $recentUsers,
    'recent_transactions' => $recentTransactions
], 'Dashboard stats retrieved');
