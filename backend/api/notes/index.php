<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$col = getCollection('notes');
if (!$col) {
    errorResponse('Database connection error');
}

$filter = [
    'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
    'deleted_at' => null
];

$items = $col->find($filter, [
    'sort' => ['pinned' => -1, 'created_at' => -1]
])->toArray();

$formatted = array_map(function ($n) {
    return [
        '_id' => (string)$n['_id'],
        'title' => $n['title'] ?? '',
        'content' => $n['content'] ?? '',
        'color' => $n['color'] ?? '#ffffff',
        'pinned' => (bool)($n['pinned'] ?? false),
        'created_at' => mongoDateToPHP($n['created_at'])->format('Y-m-d H:i:s'),
        'updated_at' => isset($n['updated_at']) ? mongoDateToPHP($n['updated_at'])->format('Y-m-d H:i:s') : null
    ];
}, $items);

successResponse(['notes' => $formatted], 'Notes retrieved');
