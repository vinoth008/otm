<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$role = getCurrentUserRole();
$col = getCollection('expenses');
if (!$col) {
    errorResponse('Database connection error');
}

$baseFilter = ['deleted_at' => null];

if (!in_array($role, ['admin', 'staff'], true)) {
    $baseFilter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
}

$allFilter = $baseFilter;

$totalPipeline = [
    ['$match' => $allFilter],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
];
$totalResult = $col->aggregate($totalPipeline)->toArray();
$totalExpenses = $totalResult[0]['total'] ?? 0;
$totalCount = $totalResult[0]['count'] ?? 0;

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$monthFilter = array_merge($baseFilter, [
    'date' => [
        '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($monthStart . ' 00:00:00') * 1000),
        '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($monthEnd . ' 23:59:59') * 1000)
    ]
]);
$monthPipeline = [
    ['$match' => $monthFilter],
    ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
];
$monthResult = $col->aggregate($monthPipeline)->toArray();
$monthTotal = $monthResult[0]['total'] ?? 0;
$monthCount = $monthResult[0]['count'] ?? 0;

$categoryPipeline = [
    ['$match' => $allFilter],
    ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]],
    ['$sort' => ['total' => -1]]
];
$categoryResult = $col->aggregate($categoryPipeline)->toArray();
$categoryBreakdown = [];
foreach ($categoryResult as $item) {
    $categoryBreakdown[] = [
        'category' => $item['_id'] ?? 'Uncategorized',
        'total' => (float)$item['total'],
        'count' => (int)$item['count']
    ];
}

$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
$seriesFilter = array_merge($baseFilter, [
    'date' => [
        '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($thirtyDaysAgo . ' 00:00:00') * 1000),
        '$lte' => new MongoDB\BSON\UTCDateTime(strtotime(date('Y-m-d') . ' 23:59:59') * 1000)
    ]
]);
$seriesPipeline = [
    ['$match' => $seriesFilter],
    ['$group' => [
        '_id' => [
            'year' => ['$year' => '$date'],
            'month' => ['$month' => '$date'],
            'day' => ['$dayOfMonth' => '$date']
        ],
        'total' => ['$sum' => '$amount']
    ]],
    ['$sort' => ['_id' => 1]]
];
$seriesResult = $col->aggregate($seriesPipeline)->toArray();
$last30Days = [];
foreach ($seriesResult as $item) {
    $last30Days[] = [
        'date' => sprintf('%04d-%02d-%02d', $item['_id']['year'], $item['_id']['month'], $item['_id']['day']),
        'total' => (float)$item['total']
    ];
}

successResponse([
    'total_expenses' => (float)$totalExpenses,
    'total_count' => (int)$totalCount,
    'this_month_total' => (float)$monthTotal,
    'this_month_count' => (int)$monthCount,
    'category_breakdown' => $categoryBreakdown,
    'last_30_days' => $last30Days
], 'Expense summary retrieved');
