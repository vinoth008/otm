<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

requireRole(['admin', 'staff']);

$users = getCollection('users');
$transactions = getCollection('transactions');
$complaints = getCollection('complaints');

$totalCustomers = 0;
if ($users) {
    $totalCustomers = $users->countDocuments(['role' => 'customer', 'deleted_at' => null]);
}

$totalTransactions = 0;
$totalExpenses = 0;
$todayTxCount = 0;
$recentTransactions = [];
$summaryAggregates = [];

if ($transactions) {
    $txFilter = ['deleted_at' => null];
    $totalTransactions = $transactions->countDocuments($txFilter);

    $todayStart = phpDateToMongo(date('Y-m-d') . ' 00:00:00');
    $todayEnd = phpDateToMongo(date('Y-m-d') . ' 23:59:59');
    $todayTxCount = $transactions->countDocuments([
        'deleted_at' => null,
        'date' => ['$gte' => $todayStart, '$lte' => $todayEnd]
    ]);

    $expResult = $transactions->aggregate([
        ['$match' => ['type' => 'expense', 'deleted_at' => null]],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
    ])->toArray();
    $totalExpenses = round((float)($expResult[0]['total'] ?? 0), 2);

    $summaryAggregates = $transactions->aggregate([
        ['$match' => ['type' => ['$in' => ['income', 'expense']], 'deleted_at' => null]],
        ['$group' => ['_id' => '$type', 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
    ])->toArray();

    $cursor = $transactions->find(['deleted_at' => null], [
        'sort' => ['date' => -1, 'created_at' => -1],
        'limit' => 6
    ]);
    foreach ($cursor as $t) {
        $ownerName = 'Unknown';
        if (isset($t['user_id']) && $users) {
            $owner = $users->findOne(['_id' => $t['user_id']]);
            if ($owner) $ownerName = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
        }
        $recentTransactions[] = [
            '_id' => (string)$t['_id'],
            'user_name' => $ownerName,
            'type' => $t['type'] ?? '',
            'category' => $t['category'] ?? '',
            'amount' => round((float)($t['amount'] ?? 0), 2),
            'description' => $t['description'] ?? '',
            'date' => mongoDateToPHP($t['date'] ?? null)->format('Y-m-d'),
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
}

$recentComplaints = [];
if ($complaints) {
    $cCursor = $complaints->find([], ['sort' => ['created_at' => -1], 'limit' => 5]);
    foreach ($cCursor as $c) {
        $recentComplaints[] = [
            '_id' => (string)$c['_id'],
            'customer_id' => isset($c['customer_id']) ? (string)$c['customer_id'] : '',
            'subject' => $c['subject'] ?? $c['title'] ?? '',
            'status' => $c['status'] ?? 'open',
            'priority' => $c['priority'] ?? 'low',
            'created_at' => mongoDateToPHP($c['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
}

$incomeTotal = 0;
$expenseTotal = 0;
$incomeCount = 0;
$expenseCount = 0;
foreach ($summaryAggregates as $agg) {
    if ($agg['_id'] === 'income') {
        $incomeTotal = round((float)$agg['total'], 2);
        $incomeCount = (int)$agg['count'];
    } elseif ($agg['_id'] === 'expense') {
        $expenseTotal = round((float)$agg['total'], 2);
        $expenseCount = (int)$agg['count'];
    }
}

successResponse([
    'total_customers' => $totalCustomers,
    'total_transactions' => $totalTransactions,
    'total_expenses' => $totalExpenses,
    'today_transactions' => $todayTxCount,
    'recent_transactions' => $recentTransactions,
    'recent_complaints' => $recentComplaints,
    'summary' => [
        'total_income' => $incomeTotal,
        'total_expense' => $expenseTotal,
        'net' => round($incomeTotal - $expenseTotal, 2),
        'income_count' => $incomeCount,
        'expense_count' => $expenseCount
    ]
], 'Dashboard loaded');
