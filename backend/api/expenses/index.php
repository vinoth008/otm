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

if (!in_array($role, ['admin', 'staff'], true)) {
    $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
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

$col = getCollection('expenses');
if (!$col) {
    errorResponse('Database connection error');
}

$total = $col->countDocuments($filter);
$items = $col->find($filter, [
    'sort' => ['created_at' => -1],
    'skip' => $skip,
    'limit' => $limit
])->toArray();

$formatted = array_map(function ($e) {
    return [
        '_id' => (string)$e['_id'],
        'user_id' => isset($e['user_id']) ? (string)$e['user_id'] : '',
        'title' => $e['title'] ?? '',
        'category' => $e['category'] ?? '',
        'amount' => (float)($e['amount'] ?? 0),
        'date' => isset($e['date']) ? mongoDateToPHP($e['date'])->format('Y-m-d') : '',
        'description' => $e['description'] ?? '',
        'created_at' => mongoDateToPHP($e['created_at'])->format('Y-m-d H:i:s')
    ];
}, $items);

successResponse([
    'expenses' => $formatted,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => ceil($total / $limit),
        'total_count' => $total,
        'limit' => $limit
    ]
], 'Expenses retrieved');
