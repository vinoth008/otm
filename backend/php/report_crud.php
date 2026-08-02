<?php
// backend/php/report_crud.php
/**
 * Reporting for Smart Transaction Control
 * Generates aggregated reports for admin and staff
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
 * Generate transaction report
 * GET: from_date, to_date, type
 */
function getTransactionReport() {
    requireRole(['staff', 'auditor']);
    $fromDate = $_GET['from_date'] ?? date('Y-m-01');
    $toDate = $_GET['to_date'] ?? date('Y-m-d');
    if (!validateDate($fromDate) || !validateDate($toDate)) {
        errorResponse('Invalid date range');
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $match = [
        'deleted_at' => null,
        'date' => [
            '$gte' => phpDateToMongo($fromDate . ' 00:00:00'),
            '$lte' => phpDateToMongo($toDate . ' 23:59:59')
        ]
    ];
    $type = $_GET['type'] ?? '';
    if (in_array($type, ['income', 'expense'], true)) {
        $match['type'] = $type;
    }
    $pipeline = [
        ['$match' => $match],
        ['$group' => [
            '_id' => null,
            'total_amount' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]]
    ];
    $totals = ['total_amount' => 0, 'count' => 0];
    foreach ($collection->aggregate($pipeline) as $row) {
        $totals = [
            'total_amount' => round((float)($row['total_amount'] ?? 0), 2),
            'count' => (int)($row['count'] ?? 0)
        ];
    }
    // Category breakdown
    $catPipeline = [
        ['$match' => $match],
        ['$group' => [
            '_id' => '$category',
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['total' => -1]]
    ];
    $categories = [];
    foreach ($collection->aggregate($catPipeline) as $row) {
        $categories[] = [
            'category' => $row['_id'] ?? 'Other',
            'total' => round((float)($row['total'] ?? 0), 2),
            'count' => (int)($row['count'] ?? 0)
        ];
    }
    successResponse([
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'totals' => $totals,
        'categories' => $categories
    ], 'Transaction report generated');
}
/**
 * Generate transfer summary report
 * GET: from_date, to_date
 */
function getTransferReport() {
    requireRole(['staff', 'auditor']);
    $fromDate = $_GET['from_date'] ?? date('Y-m-01');
    $toDate = $_GET['to_date'] ?? date('Y-m-d');
    if (!validateDate($fromDate) || !validateDate($toDate)) {
        errorResponse('Invalid date range');
    }
    $collection = getCollection('transfers');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $match = [
        'deleted_at' => null,
        'created_at' => [
            '$gte' => phpDateToMongo($fromDate . ' 00:00:00'),
            '$lte' => phpDateToMongo($toDate . ' 23:59:59')
        ]
    ];
    $pipeline = [
        ['$match' => $match],
        ['$group' => [
            '_id' => '$status',
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]]
    ];
    $statusBreakdown = [];
    foreach ($collection->aggregate($pipeline) as $row) {
        $statusBreakdown[] = [
            'status' => $row['_id'] ?? 'unknown',
            'total' => round((float)($row['total'] ?? 0), 2),
            'count' => (int)($row['count'] ?? 0)
        ];
    }
    successResponse([
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'status_breakdown' => $statusBreakdown
    ], 'Transfer report generated');
}
/**
 * Generate complaint report
 * GET: from_date, to_date
 */
function getComplaintReport() {
    requireRole(['staff', 'auditor']);
    $fromDate = $_GET['from_date'] ?? date('Y-m-01');
    $toDate = $_GET['to_date'] ?? date('Y-m-d');
    if (!validateDate($fromDate) || !validateDate($toDate)) {
        errorResponse('Invalid date range');
    }
    $collection = getCollection('complaints');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $match = [
        'deleted_at' => null,
        'created_at' => [
            '$gte' => phpDateToMongo($fromDate . ' 00:00:00'),
            '$lte' => phpDateToMongo($toDate . ' 23:59:59')
        ]
    ];
    $pipeline = [
        ['$match' => $match],
        ['$group' => [
            '_id' => '$status',
            'count' => ['$sum' => 1]
        ]]
    ];
    $statusBreakdown = [];
    foreach ($collection->aggregate($pipeline) as $row) {
        $statusBreakdown[] = [
            'status' => $row['_id'] ?? 'unknown',
            'count' => (int)($row['count'] ?? 0)
        ];
    }
    successResponse([
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'status_breakdown' => $statusBreakdown
    ], 'Complaint report generated');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'transactions':
        if ($method === 'GET') getTransactionReport();
        break;
    case 'transfers':
        if ($method === 'GET') getTransferReport();
        break;
    case 'complaints':
        if ($method === 'GET') getComplaintReport();
        break;
    default:
        errorResponse('Invalid action');
}
?>
