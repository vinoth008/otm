<?php
// backend/php/transfer_crud.php
/**
 * Transfer Management for Smart Transaction Control
 * Handles transfer requests (internal/scheduled/recurring), staff approval, and status
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
 * Get transfers for current user (sent or received)
 * GET
 */
function getTransfers() {
    requireActiveSession();
    $transfers = getCollection('transfers');
    if (!$transfers) {
        errorResponse('Database connection error');
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $cursor = $transfers->find(
        [
            '$and' => [
                [
                    '$or' => [
                        ['from_user_id' => $userId],
                        ['to_user_id' => $userId]
                    ]
                ],
                ['type' => ['$ne' => 'topup']],
                ['deleted_at' => null]
            ]
        ],
        ['sort' => ['created_at' => -1], 'limit' => 100]
    );
    $list = [];
    foreach ($cursor as $t) {
        $fromId = isset($t['from_user_id']) ? (string)$t['from_user_id'] : '';
        $list[] = [
            'transfer_id' => (string)$t['_id'],
            'direction' => $fromId === getCurrentUserId() ? 'out' : 'in',
            'type' => $t['type'] ?? 'internal',
            'status' => $t['status'] ?? 'pending',
            'amount' => round((float)($t['amount'] ?? 0), 2),
            'description' => $t['description'] ?? '',
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['transfers' => $list], 'Transfers retrieved');
}
/**
 * Create a transfer request
 * POST: amount, recipient_email, type, description, schedule_date
 */
function createTransfer() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $amount = (float)($data['amount'] ?? 0);
    $recipientEmail = strtolower(trim($data['recipient_email'] ?? ''));
    $type = sanitizeInput($data['type'] ?? 'internal');
    if ($amount <= 0 || !validateAmount((string)$amount)) {
        errorResponse('Enter a valid amount');
    }
    if (empty($recipientEmail) || !validateEmail($recipientEmail)) {
        errorResponse('Enter a valid recipient email');
    }
    if ($recipientEmail === strtolower(getCurrentUserEmail())) {
        errorResponse('You cannot transfer to yourself');
    }
    $users = getCollection('users');
    $recipient = $users->findOne(['email' => $recipientEmail, 'deleted_at' => null]);
    if (!$recipient) {
        errorResponse('Recipient not found');
    }
    $transfers = getCollection('transfers');
    if (!$transfers) {
        errorResponse('Database connection error');
    }
    // Scheduled transfers are created as pending and auto-completed later
    $status = ($type === 'scheduled') ? 'scheduled' : 'pending';
    $scheduleDate = null;
    if ($type === 'scheduled') {
        $rawDate = $data['schedule_date'] ?? '';
        if (!empty($rawDate)) {
            if (!validateDate($rawDate)) {
                errorResponse('Invalid schedule date');
            }
            $scheduleDate = phpDateToMongo($rawDate);
        }
    }
    $result = $transfers->insertOne([
        'from_user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'to_user_id' => $recipient['_id'],
        'amount' => $amount,
        'type' => $type,
        'status' => $status,
        'description' => sanitizeInput($data['description'] ?? 'Transfer request'),
        'schedule_date' => $scheduleDate,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('transfer_created', getCurrentUserId(), [
        'transfer_id' => (string)$result->getInsertedId(),
        'amount' => $amount,
        'recipient_email' => $recipientEmail
    ]);
    successResponse([
        'transfer_id' => (string)$result->getInsertedId(),
        'status' => $status
    ], 'Transfer request submitted for approval');
}
/**
 * Update transfer status (staff/admin approval or rejection)
 * POST: transfer_id, status (approved/completed/rejected/cancelled), remarks
 */
function updateTransferStatus() {
    requireRole(['staff']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transferId = $data['transfer_id'] ?? '';
    $status = sanitizeInput($data['status'] ?? '');
    if (!isValidObjectId($transferId)) {
        errorResponse('Invalid transfer ID');
    }
    if (!in_array($status, ['approved', 'completed', 'rejected', 'cancelled'], true)) {
        errorResponse('Invalid status');
    }
    $transfers = getCollection('transfers');
    if (!$transfers) {
        errorResponse('Database connection error');
    }
    $transfer = $transfers->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transferId),
        'deleted_at' => null
    ]);
    if (!$transfer) {
        errorResponse('Transfer not found');
    }
    $update = ['status' => $status, 'updated_at' => phpDateToMongo()];
    $remarks = sanitizeInput($data['remarks'] ?? '');
    if ($remarks !== '') {
        $update['staff_remarks'] = $remarks;
    }
    $transfers->updateOne(
        ['_id' => $transfer['_id']],
        ['$set' => $update]
    );
    logActivity('transfer_status_changed', getCurrentUserId(), [
        'transfer_id' => $transferId,
        'status' => $status
    ]);
    successResponse(null, 'Transfer ' . $status . ' successfully');
}
/**
 * Get all transfers (staff/admin view)
 * GET
 */
function getAllTransfers() {
    requireRole(['staff']);
    $transfers = getCollection('transfers');
    if (!$transfers) {
        errorResponse('Database connection error');
    }
    $filter = ['type' => ['$ne' => 'topup'], 'deleted_at' => null];
    $statusFilter = $_GET['status'] ?? '';
    if (in_array($statusFilter, ['pending', 'approved', 'scheduled', 'completed', 'rejected', 'cancelled'], true)) {
        $filter['status'] = $statusFilter;
    }
    $cursor = $transfers->find($filter, ['sort' => ['created_at' => -1], 'limit' => 200]);
    $list = [];
    foreach ($cursor as $t) {
        $list[] = [
            'transfer_id' => (string)$t['_id'],
            'type' => $t['type'] ?? 'internal',
            'status' => $t['status'] ?? 'pending',
            'amount' => round((float)($t['amount'] ?? 0), 2),
            'description' => $t['description'] ?? '',
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['transfers' => $list], 'All transfers retrieved');
}
/**
 * Get transfer summary stats
 * GET
 */
function getTransferSummary() {
    requireActiveSession();
    $transfers = getCollection('transfers');
    if (!$transfers) {
        errorResponse('Database connection error');
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $baseFilter = [
        '$or' => [
            ['from_user_id' => $userId],
            ['to_user_id' => $userId]
        ],
        'type' => ['$ne' => 'topup'],
        'deleted_at' => null
    ];
    $stats = [
        'pending' => $transfers->countDocuments($baseFilter + ['status' => 'pending']),
        'completed' => $transfers->countDocuments($baseFilter + ['status' => 'completed']),
        'scheduled' => $transfers->countDocuments($baseFilter + ['status' => 'scheduled']),
        'total_sent' => 0,
        'total_received' => 0
    ];
    // Sum completed amounts
    $pipeline = [
        ['$match' => $baseFilter + ['status' => 'completed']],
        ['$group' => [
            '_id' => null,
            'sent' => ['$sum' => ['$cond' => [['$eq' => ['$from_user_id', $userId]], '$amount', 0]]],
            'received' => ['$sum' => ['$cond' => [['$eq' => ['$to_user_id', $userId]], '$amount', 0]]]
        ]]
    ];
    foreach ($transfers->aggregate($pipeline) as $row) {
        $stats['total_sent'] = round((float)($row['sent'] ?? 0), 2);
        $stats['total_received'] = round((float)($row['received'] ?? 0), 2);
    }
    successResponse($stats, 'Transfer summary retrieved');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getTransfers();
        break;
    case 'all':
        if ($method === 'GET') getAllTransfers();
        break;
    case 'create':
        if ($method === 'POST') createTransfer();
        break;
    case 'update_status':
        if ($method === 'POST') updateTransferStatus();
        break;
    case 'summary':
        if ($method === 'GET') getTransferSummary();
        break;
    default:
        errorResponse('Invalid action');
}
?>
