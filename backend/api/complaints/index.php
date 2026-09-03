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

if (!in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
}

if (!empty($_GET['status'])) {
    $filter['status'] = sanitizeInput($_GET['status']);
}

$col = getCollection('complaints');
if (!$col) {
    errorResponse('Database connection error');
}

$total = $col->countDocuments($filter);
$items = $col->find($filter, [
    'sort' => ['created_at' => -1],
    'skip' => $skip,
    'limit' => $limit
])->toArray();

$formatted = array_map(function ($c) {
    return [
        '_id' => (string)$c['_id'],
        'ticket_no' => $c['ticket_no'] ?? '',
        'customer_name' => $c['customer_name'] ?? '',
        'user_id' => isset($c['user_id']) ? (string)$c['user_id'] : '',
        'subject' => $c['subject'] ?? '',
        'description' => $c['description'] ?? '',
        'category' => $c['category'] ?? '',
        'priority' => $c['priority'] ?? 'Medium',
        'status' => $c['status'] ?? 'open',
        'staff_reply' => $c['staff_reply'] ?? '',
        'assigned_to' => $c['assigned_to'] ?? null,
        'created_at' => mongoDateToPHP($c['created_at'])->format('Y-m-d H:i:s'),
        'updated_at' => mongoDateToPHP($c['updated_at'])->format('Y-m-d H:i:s')
    ];
}, $items);

successResponse([
    'complaints' => $formatted,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => ceil($total / $limit),
        'total_count' => $total,
        'limit' => $limit
    ]
], 'Complaints retrieved');
