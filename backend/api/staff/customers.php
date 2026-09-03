<?php
/**
 * Staff: Customers management
 * GET: list | POST create / update
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

requireRole(['admin', 'staff']);
$method = $_SERVER['REQUEST_METHOD'];
$input = getRequestData();
$action = $input['action'] ?? '';

$col = getCollection('users');
if (!$col) errorResponse('Database connection error');

if ($method === 'GET') {
    $filter = ['role' => 'customer', 'deleted_at' => null];
    $search = sanitizeInput($input['search'] ?? '');
    if ($search !== '') {
        $filter['$or'] = [
            ['first_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['last_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['email' => new MongoDB\BSON\Regex($search, 'i')],
            ['mobile' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $cursor = $col->find($filter, ['sort' => ['created_at' => -1]]);
    $list = [];
    foreach ($cursor as $doc) {
        $list[] = [
            'id' => (string)$doc['_id'],
            'full_name' => ($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? ''),
            'email' => $doc['email'] ?? '',
            'mobile' => $doc['mobile'] ?? '',
            'status' => $doc['status'] ?? 'active',
            'account_number' => $doc['account_number'] ?? '',
            'created_at' => isset($doc['created_at']) ? mongoDateToPHP($doc['created_at'])->format('Y-m-d H:i:s') : ''
        ];
    }
    successResponse($list, 'Customers retrieved');
}

if ($method === 'POST' && $action === 'create') {
    $email = strtolower(trim(sanitizeInput($input['email'] ?? '')));
    $firstName = sanitizeInput($input['full_name'] ?? ($input['first_name'] ?? ''));
    $lastName = sanitizeInput($input['last_name'] ?? '');
    $mobile = sanitizeInput($input['mobile'] ?? '');
    $password = $input['password'] ?? '';
    if (empty($email) || !validateEmail($email)) errorResponse('Valid email required');
    if (empty($firstName)) errorResponse('Full name required');
    $validation = validatePasswordStrength($password);
    if (!$validation['valid']) errorResponse(implode(', ', $validation['errors']));
    if ($col->findOne(['email' => $email])) errorResponse('Email already registered');
    $passwordHash = hashPassword($password);
    $result = $col->insertOne([
        'email' => $email,
        'password_hash' => $passwordHash,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'mobile' => $mobile,
        'phone' => $mobile,
        'role' => 'customer',
        'status' => 'active',
        'is_verified' => true,
        'balance' => 0.0,
        'account_number' => '5' . random_int(100000000000000, 999999999999999),
        'account_type' => sanitizeInput($input['account_type'] ?? 'savings'),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('staff_customer_created', getCurrentUserId(), ['target' => (string)$result->getInsertedId()]);
    successResponse(['id' => (string)$result->getInsertedId()], 'Customer created');
}

if ($method === 'POST' && $action === 'update') {
    $id = $input['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid customer ID');
    $col->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($id)],
        ['$set' => [
            'first_name' => sanitizeInput($input['full_name'] ?? ($input['first_name'] ?? '')),
            'last_name' => sanitizeInput($input['last_name'] ?? ''),
            'email' => strtolower(trim(sanitizeInput($input['email'] ?? ''))),
            'mobile' => sanitizeInput($input['mobile'] ?? ''),
            'status' => sanitizeInput($input['status'] ?? 'active'),
            'updated_at' => phpDateToMongo()
        ]]
    );
    logActivity('staff_customer_updated', getCurrentUserId());
    successResponse(null, 'Customer updated');
}

errorResponse('Invalid request', 400);
