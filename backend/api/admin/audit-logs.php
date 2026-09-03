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
    $action = $_GET['action'] ?? 'get_all';
}

if ($action !== 'get_all') {
    errorResponse('Invalid action', 400);
}

$collection = getCollection('activity_logs');
if (!$collection) {
    errorResponse('Database connection error');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$skip = ($page - 1) * $limit;
$filter = [];
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $regex = new MongoDB\BSON\Regex($search, 'i');
    $filter['$or'] = [
        ['action' => $regex],
        ['ip_address' => $regex]
    ];
}
$actionFilter = trim($_GET['action'] ?? '');
if ($actionFilter !== '') {
    $filter['action'] = $actionFilter;
}
$total = $collection->countDocuments($filter);
$cursor = $collection->find($filter, [
    'sort' => ['timestamp' => -1],
    'skip' => $skip,
    'limit' => $limit
]);
$logs = [];
foreach ($cursor as $log) {
    $logs[] = [
        'log_id' => (string)$log['_id'],
        'action' => $log['action'] ?? '',
        'user_id' => isset($log['user_id']) ? (string)$log['user_id'] : null,
        'ip_address' => $log['ip_address'] ?? '',
        'user_agent' => $log['user_agent'] ?? '',
        'timestamp' => isset($log['timestamp']) ? mongoDateToPHP($log['timestamp'])->format('Y-m-d H:i:s') : '',
        'details' => $log['details'] ?? []
    ];
}
successResponse([
    'logs' => $logs,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => ceil($total / $limit),
        'total_count' => $total,
        'limit' => $limit
    ]
], 'Audit logs retrieved');
