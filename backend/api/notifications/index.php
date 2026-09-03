<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$userId = getCurrentUserId();
$col = getCollection('notifications');
if (!$col) {
    errorResponse('Database connection error');
}

$filter = [
    'user_id' => new MongoDB\BSON\ObjectId($userId),
    'deleted_at' => null
];

$unreadCount = $col->countDocuments(array_merge($filter, ['is_read' => false]));

$items = $col->find($filter, [
    'sort' => ['created_at' => -1]
])->toArray();

$formatted = array_map(function ($n) {
    return [
        '_id' => (string)$n['_id'],
        'type' => $n['type'] ?? 'info',
        'title' => $n['title'] ?? '',
        'message' => $n['message'] ?? '',
        'is_read' => (bool)($n['is_read'] ?? false),
        'link' => $n['link'] ?? '',
        'created_at' => mongoDateToPHP($n['created_at'])->format('Y-m-d H:i:s')
    ];
}, $items);

successResponse([
    'notifications' => $formatted,
    'unread_count' => (int)$unreadCount
], 'Notifications retrieved');
