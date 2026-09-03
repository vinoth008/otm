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
$col = getCollection('transactions');
if (!$col) {
    errorResponse('Database connection error');
}

$baseFilter = ['deleted_at' => null, 'type' => ['$in' => ['income', 'expense']]];

if (!in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $baseFilter['user_id'] = new MongoDB\BSON\ObjectId($userId);
}

$incomeFilter = array_merge($baseFilter, ['type' => 'income']);
$expenseFilter = array_merge($baseFilter, ['type' => 'expense']);

$incomePipeline = [
    ['$match' => $incomeFilter],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
];
$incomeResult = $col->aggregate($incomePipeline)->toArray();
$totalIncome = $incomeResult[0]['total'] ?? 0;
$incomeCount = $incomeResult[0]['count'] ?? 0;

$expensePipeline = [
    ['$match' => $expenseFilter],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
];
$expenseResult = $col->aggregate($expensePipeline)->toArray();
$totalExpense = $expenseResult[0]['total'] ?? 0;
$expenseCount = $expenseResult[0]['count'] ?? 0;

$totalCount = $col->countDocuments($baseFilter);
$net = $totalIncome - $totalExpense;
$savingsRate = $totalIncome > 0 ? round(($net / $totalIncome) * 100, 2) : 0;

successResponse([
    'total_income' => (float)$totalIncome,
    'total_expense' => (float)$totalExpense,
    'net' => (float)$net,
    'transaction_count' => (int)$totalCount,
    'income_count' => (int)$incomeCount,
    'expense_count' => (int)$expenseCount,
    'savings_rate' => (float)$savingsRate
], 'Summary retrieved');
