<?php
// backend/php/audit_crud.php
/**
 * Audit Log Management for Smart Transaction Control
 * Handles audit trail viewing (admin only)
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/session_manager.php';
// Prevent direct access
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
/**
 * Admin/Auditor: get audit logs (read-only)
 * GET: user_id (optional), action (optional), from_date, to_date
 */
function getAuditLogs() {
    requireRole(['admin', 'auditor']);
    $collection = getCollection('audit_logs');
    if (!$collection) {
        $collection = getCollection('activity_logs');
    }
    if (!$collection) {
        errorResponse('Database connection error');
    }
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
        $filter['timestamp'] = ['$gte' => phpDateToMongo($fromDate . ' 00:00:00')];
    }
    if (!empty($toDate)) {
        $toFilter = ['$lte' => phpDateToMongo($toDate . ' 23:59:59')];
        if (isset($filter['timestamp'])) {
            $filter['timestamp'] += $toFilter;
        } else {
            $filter['timestamp'] = $toFilter;
        }
    }
    $cursor = $collection->find($filter, ['sort' => ['timestamp' => -1], 'limit' => 200]);
    $list = [];
    foreach ($cursor as $log) {
        $list[] = [
            'log_id' => (string)$log['_id'],
            'user_id' => isset($log['user_id']) ? (string)$log['user_id'] : '',
            'action' => $log['action'] ?? '',
            'details' => $log['details'] ?? [],
            'ip_address' => $log['ip_address'] ?? '',
            'user_agent' => $log['user_agent'] ?? '',
            'timestamp' => mongoDateToPHP($log['timestamp'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['logs' => $list], 'Audit logs retrieved');
}
/**
 * Admin/Auditor: get distinct activity/action types
 * GET
 */
function getAuditActions() {
    requireRole(['admin', 'auditor']);
    $collection = getCollection('audit_logs');
    if (!$collection) {
        $collection = getCollection('activity_logs');
    }
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $actions = $collection->distinct('action');
    successResponse(['actions' => $actions], 'Audit actions retrieved');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_logs':
        if ($method === 'GET') getAuditLogs();
        break;
    case 'get_actions':
        if ($method === 'GET') getAuditActions();
        break;
    default:
        errorResponse('Invalid action');
}
?>
