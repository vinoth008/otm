<?php
/**
 * Staff: Beneficiaries management
 * GET: list | POST create / update / delete
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

requireRole(['admin', 'staff']);
$method = $_SERVER['REQUEST_METHOD'];
$input = getRequestData();
$action = $input['action'] ?? '';

$col = getCollection('beneficiaries');
if (!$col) errorResponse('Database connection error');

if ($method === 'GET') {
    $cursor = $col->find(['deleted_at' => null], ['sort' => ['created_at' => -1]]);
    $list = [];
    foreach ($cursor as $doc) {
        $list[] = [
            'id' => (string)$doc['_id'],
            'customer_name' => $doc['customer_name'] ?? '',
            'beneficiary_name' => $doc['beneficiary_name'] ?? '',
            'bank_name' => $doc['bank_name'] ?? '',
            'account_no' => $doc['account_no'] ?? '',
            'ifsc' => $doc['ifsc'] ?? ''
        ];
    }
    successResponse($list, 'Beneficiaries retrieved');
}

if ($method === 'POST' && $action === 'create') {
    $col->insertOne([
        'customer_name' => sanitizeInput($input['customer_name'] ?? ''),
        'beneficiary_name' => sanitizeInput($input['beneficiary_name'] ?? ''),
        'bank_name' => sanitizeInput($input['bank_name'] ?? ''),
        'account_no' => sanitizeInput($input['account_no'] ?? ''),
        'ifsc' => sanitizeInput($input['ifsc'] ?? ''),
        'user_id' => getCurrentUserId() ? new MongoDB\BSON\ObjectId(getCurrentUserId()) : null,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('beneficiary_created', getCurrentUserId());
    successResponse(null, 'Beneficiary created');
}

if ($method === 'POST' && $action === 'update') {
    $id = $input['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid beneficiary ID');
    $col->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($id)],
        ['$set' => [
            'customer_name' => sanitizeInput($input['customer_name'] ?? ''),
            'beneficiary_name' => sanitizeInput($input['beneficiary_name'] ?? ''),
            'bank_name' => sanitizeInput($input['bank_name'] ?? ''),
            'account_no' => sanitizeInput($input['account_no'] ?? ''),
            'ifsc' => sanitizeInput($input['ifsc'] ?? ''),
            'updated_at' => phpDateToMongo()
        ]]
    );
    logActivity('beneficiary_updated', getCurrentUserId());
    successResponse(null, 'Beneficiary updated');
}

if ($method === 'POST' && $action === 'delete') {
    $id = $input['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid beneficiary ID');
    $col->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($id)],
        ['$set' => ['deleted_at' => phpDateToMongo()]]
    );
    logActivity('beneficiary_deleted', getCurrentUserId());
    successResponse(null, 'Beneficiary deleted');
}

errorResponse('Invalid request', 400);
