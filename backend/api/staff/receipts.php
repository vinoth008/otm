<?php
/**
 * Staff: Receipts listing
 * GET: list receipts
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

requireRole(['admin', 'staff', 'receptionist']);
$method = $_SERVER['REQUEST_METHOD'];

$col = getCollection('receipts');
if (!$col) errorResponse('Database connection error');

if ($method === 'GET') {
    $input = getRequestData();
    $filter = ['deleted_at' => null];
    if (!empty($input['customer_id']) && isValidObjectId($input['customer_id'])) {
        $filter['customer_id'] = new MongoDB\BSON\ObjectId($input['customer_id']);
    }
    $cursor = $col->find($filter, ['sort' => ['created_at' => -1]]);
    $list = [];
    foreach ($cursor as $doc) {
        $list[] = [
            'id' => (string)$doc['_id'],
            'receipt_no' => $doc['receipt_no'] ?? null,
            'customer_name' => $doc['customer_name'] ?? '',
            'receipt_type' => $doc['receipt_type'] ?? '',
            'amount' => (float)($doc['amount'] ?? 0),
            'description' => $doc['description'] ?? '',
            'created_at' => isset($doc['created_at']) ? mongoDateToPHP($doc['created_at'])->format('Y-m-d H:i:s') : ''
        ];
    }
    successResponse($list, 'Receipts retrieved');
}

errorResponse('Invalid request', 400);
