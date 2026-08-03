<?php
declare(strict_types=1);
// Audit Logs API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_logs': $method === 'GET' && getAuditLogs(); break;
    case 'get_actions': $method === 'GET' && getAuditActions(); break;
    default: errorResponse('Invalid action', 404);
}

function getAuditLogs() {
    requireRole(['admin']);
    $collection = getCollection('activity_logs');
    if (!$collection) errorResponse('Database connection error', 500);
    $filter = [];
    $userId = $_GET['user_id'] ?? '';
    if (isValidObjectId($userId)) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId($userId);
    }
    $action = $_GET['action'] ?? '';
    if ($action !== '') {
        $filter['action'] = $action;
    }
    $fromDate = $_GET['from_date'] ?? '';
    $toDate = $_GET['to_date'] ?? '';
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
    $users = getCollection('users');
    $cursor = $collection->find($filter, ['sort' => ['created_at' => -1], 'limit' => 200]);
    $list = [];
    foreach ($cursor as $log) {
        $owner = null;
        if (!empty($log['user_id'])) {
            $owner = $users->findOne(['_id' => $log['user_id']]);
        }
        $list[] = [
            'log_id' => (string)$log['_id'],
            'user_id' => isset($log['user_id']) ? (string)$log['user_id'] : '',
            'user_email' => $owner ? ($owner['email'] ?? '') : 'System',
            'action' => $log['action'] ?? '',
            'details' => $log['details'] ?? [],
            'ip_address' => $log['ip_address'] ?? '',
            'user_agent' => $log['user_agent'] ?? '',
            'created_at' => mongoDateToPHP($log['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['logs' => $list]);
}

function getAuditActions() {
    requireRole(['admin']);
    $collection = getCollection('activity_logs');
    if (!$collection) errorResponse('Database connection error', 500);
    $actions = $collection->distinct('action');
    successResponse(['actions' => $actions]);
}