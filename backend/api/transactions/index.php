<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$role = getCurrentUserRole();
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$skip = ($page - 1) * $limit;

$filter = ['deleted_at' => null];

if (in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    if (!empty($_GET['user_id']) && isValidObjectId($_GET['user_id'])) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId($_GET['user_id']);
    }
} else {
    $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
}

if (!empty($_GET['type'])) {
    $filter['type'] = sanitizeInput($_GET['type']);
}

if (!empty($_GET['category'])) {
    $filter['category'] = sanitizeInput($_GET['category']);
}

if (!empty($_GET['from'])) {
    $from = sanitizeInput($_GET['from']);
    if (validateDate($from)) {
        $filter['date'] = ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($from . ' 00:00:00') * 1000)];
    }
}

if (!empty($_GET['to'])) {
    $to = sanitizeInput($_GET['to']);
    if (validateDate($to)) {
        $filter['date'] = array_merge(
            $filter['date'] ?? [],
            ['$lte' => new MongoDB\BSON\UTCDateTime(strtotime($to . ' 23:59:59') * 1000)]
        );
    }
}

if (!empty($_GET['search'])) {
    $search = sanitizeInput($_GET['search']);
    $filter['$or'] = [
        ['description' => new MongoDB\BSON\Regex($search, 'i')],
        ['notes' => new MongoDB\BSON\Regex($search, 'i')],
        ['category' => new MongoDB\BSON\Regex($search, 'i')]
    ];
}

$col = getCollection('transactions');
if (!$col) {
    errorResponse('Database connection error');
}

$total = $col->countDocuments($filter);

$cursor = $col->find($filter, [
    'sort' => ['date' => -1, 'created_at' => -1],
    'skip' => $skip,
    'limit' => $limit
]);

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
}, $cursor->toArray());

$summaryFilter = $filter;
unset($summaryFilter['$or']);
$summaryPipeline = [
    ['$match' => $summaryFilter],
    ['$group' => [
        '_id' => '$type',
        'total' => ['$sum' => '$amount'],
        'count' => ['$sum' => 1]
    ]]
];
$summaryResult = $col->aggregate($summaryPipeline)->toArray();
$summary = ['income' => 0, 'expense' => 0, 'transfer' => 0];
foreach ($summaryResult as $item) {
    $t = $item['_id'] ?? 'expense';
    $summary[$t] = ['total' => (float)$item['total'], 'count' => (int)$item['count']];
}

successResponse([
    'transactions' => $formatted,
    'summary' => $summary,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => ceil($total / $limit),
        'total_count' => $total,
        'limit' => $limit
    ]
], 'Transactions retrieved');
