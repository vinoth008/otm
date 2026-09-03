<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

requireRole(['admin']);

$data = getRequestData();
$action = $data['action'] ?? ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get_report';
}

if ($action !== 'get_report' && $action !== 'get_all') {
    errorResponse('Invalid action', 400);
}

$collection = getCollection('transactions');
if (!$collection) {
    errorResponse('Database connection error');
}

$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');
$groupBy = trim($_GET['group_by'] ?? 'day');

$filter = [];
if (!empty($fromDate)) {
    $filter['created_at'] = ['$gte' => phpDateToMongo($fromDate . ' 00:00:00')];
}
if (!empty($toDate)) {
    $toFilter = ['$lte' => phpDateToMongo($toDate . ' 23:59:59')];
    if (isset($filter['created_at'])) {
        $filter['created_at'] += $toFilter;
    } else {
        $filter['created_at'] = $toFilter;
    }
}

$count = $collection->countDocuments($filter);

$incomeAgg = $collection->aggregate([
    ['$match' => array_merge($filter, ['type' => 'income'])],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
])->toArray();
$totalIncome = $incomeAgg[0]['total'] ?? 0;

$expenseAgg = $collection->aggregate([
    ['$match' => array_merge($filter, ['type' => 'expense'])],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
])->toArray();
$totalExpense = $expenseAgg[0]['total'] ?? 0;

$net = $totalIncome - $totalExpense;

$groupKey = '$category';
$dateFormat = '%Y-%m-%d';
switch ($groupBy) {
    case 'month':
        $groupKey = ['$dateToString' => ['format' => '%Y-%m', 'date' => '$created_at']];
        break;
    case 'category':
        $groupKey = '$category';
        break;
    case 'type':
        $groupKey = '$type';
        break;
    case 'day':
    default:
        $groupKey = ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']];
        break;
}

$pipeline = [['$match' => $filter]];
if ($groupBy === 'day' || $groupBy === 'month') {
    $pipeline[] = ['$group' => [
        '_id' => $groupKey,
        'total' => ['$sum' => '$amount'],
        'income' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'income']], '$amount', 0]]],
        'expense' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'expense']], '$amount', 0]]],
        'count' => ['$sum' => 1]
    ]];
} else {
    $pipeline[] = ['$group' => [
        '_id' => $groupKey,
        'total' => ['$sum' => '$amount'],
        'income' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'income']], '$amount', 0]]],
        'expense' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'expense']], '$amount', 0]]],
        'count' => ['$sum' => 1]
    ]];
}
$pipeline[] = ['$sort' => ['_id' => 1]];

$series = [];
$seriesResult = $collection->aggregate($pipeline)->toArray();
foreach ($seriesResult as $row) {
    $series[] = [
        'label' => (string)$row['_id'],
        'total' => $row['total'],
        'income' => $row['income'],
        'expense' => $row['expense'],
        'count' => $row['count']
    ];
}

successResponse([
    'total_income' => $totalIncome,
    'total_expense' => $totalExpense,
    'net' => $net,
    'transaction_count' => $count,
    'group_by' => $groupBy,
    'series' => $series
], 'Report generated');
