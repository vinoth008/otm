<?php
/**
 * Staff: Complaints management
 * GET: list | POST update_status
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

requireRole(['admin', 'staff']);
$method = $_SERVER['REQUEST_METHOD'];
$input = getRequestData();
$action = $input['action'] ?? '';

$col = getCollection('complaints');
if (!$col) errorResponse('Database connection error');

if ($method === 'GET') {
    $filter = ['deleted_at' => null];
    if (!empty($input['status'])) $filter['status'] = sanitizeInput($input['status']);
    $cursor = $col->find($filter, ['sort' => ['created_at' => -1]]);
    $list = [];
    foreach ($cursor as $doc) {
        $list[] = [
            'id' => (string)$doc['_id'],
            'ticket_no' => $doc['ticket_no'] ?? null,
            'customer_name' => $doc['customer_name'] ?? '',
            'category' => $doc['category'] ?? '',
            'priority' => $doc['priority'] ?? '',
            'subject' => $doc['subject'] ?? '',
            'description' => $doc['description'] ?? '',
            'status' => $doc['status'] ?? 'open',
            'staff_reply' => $doc['staff_reply'] ?? '',
            'created_at' => isset($doc['created_at']) ? mongoDateToPHP($doc['created_at'])->format('Y-m-d H:i:s') : ''
        ];
    }
    successResponse($list, 'Complaints retrieved');
}

if ($method === 'POST' && $action === 'update_status') {
    $id = $input['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid complaint ID');
    $status = sanitizeInput($input['status'] ?? 'open');
    $col->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($id)],
        ['$set' => [
            'status' => $status,
            'staff_reply' => sanitizeInput($input['staff_reply'] ?? ''),
            'updated_at' => phpDateToMongo()
        ]]
    );
    logActivity('complaint_updated', getCurrentUserId());
    successResponse(null, 'Complaint updated');
}

errorResponse('Invalid request', 400);
