<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$data = getRequestData();
if (!$data) {
    errorResponse('Invalid request format');
}

$fromDate = sanitizeInput($data['from_date'] ?? date('Y-m-01'));
$toDate = sanitizeInput($data['to_date'] ?? date('Y-m-t'));
$type = sanitizeInput($data['type'] ?? '');
$groupBy = sanitizeInput($data['group_by'] ?? 'day');

if (!validateDate($fromDate) || !validateDate($toDate)) {
    errorResponse('Invalid date format');
}

$role = getCurrentUserRole();
$userId = getCurrentUserId();
$col = getCollection('transactions');
if (!$col) {
    errorResponse('Database connection error');
}

$filter = [
    'deleted_at' => null,
    'date' => [
        '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($fromDate . ' 00:00:00') * 1000),
        '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($toDate . ' 23:59:59') * 1000)
    ]
];

if (!in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $filter['user_id'] = new MongoDB\BSON\ObjectId($userId);
}

if (!empty($type) && in_array($type, ['income', 'expense', 'transfer'])) {
    $filter['type'] = $type;
}

$totalPipeline = [
    ['$match' => $filter],
    ['$group' => [
        '_id' => '$type',
        'total' => ['$sum' => '$amount'],
        'count' => ['$sum' => 1]
    ]]
];
$totalResult = $col->aggregate($totalPipeline)->toArray();
$totals = ['income' => 0, 'expense' => 0, 'transfer' => 0];
$counts = ['income' => 0, 'expense' => 0, 'transfer' => 0];
foreach ($totalResult as $item) {
    $t = $item['_id'] ?? 'expense';
    $totals[$t] = (float)$item['total'];
    $counts[$t] = (int)$item['count'];
}

if ($groupBy === 'category') {
    $groupPipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => ['type' => '$type', 'category' => '$category'],
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['total' => -1]]
    ];
    $groupResult = $col->aggregate($groupPipeline)->toArray();
    $series = [];
    foreach ($groupResult as $item) {
        $series[] = [
            'type' => $item['_id']['type'] ?? '',
            'category' => $item['_id']['category'] ?? '',
            'total' => (float)$item['total'],
            'count' => (int)$item['count']
        ];
    }
} elseif ($groupBy === 'month') {
    $groupPipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => [
                'type' => '$type',
                'year' => ['$year' => '$date'],
                'month' => ['$month' => '$date']
            ],
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['_id' => 1]]
    ];
    $groupResult = $col->aggregate($groupPipeline)->toArray();
    $series = [];
    foreach ($groupResult as $item) {
        $series[] = [
            'type' => $item['_id']['type'] ?? '',
            'period' => sprintf('%04d-%02d', $item['_id']['year'], $item['_id']['month']),
            'total' => (float)$item['total'],
            'count' => (int)$item['count']
        ];
    }
} else {
    $groupPipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => [
                'type' => '$type',
                'year' => ['$year' => '$date'],
                'month' => ['$month' => '$date'],
                'day' => ['$dayOfMonth' => '$date']
            ],
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['_id' => 1]]
    ];
    $groupResult = $col->aggregate($groupPipeline)->toArray();
    $series = [];
    foreach ($groupResult as $item) {
        $series[] = [
            'type' => $item['_id']['type'] ?? '',
            'date' => sprintf('%04d-%02d-%02d', $item['_id']['year'], $item['_id']['month'], $item['_id']['day']),
            'total' => (float)$item['total'],
            'count' => (int)$item['count']
        ];
    }
}

successResponse([
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'totals' => $totals,
    'counts' => $counts,
    'net' => (float)($totals['income'] - $totals['expense']),
    'group_by' => $groupBy,
    'series' => $series
], 'Report generated');
