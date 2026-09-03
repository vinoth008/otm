<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'get_all') {
    getAllCustomers();
} else {
    switch ($action) {
        case 'create': createCustomer(); break;
        case 'update': updateCustomer(); break;
        case 'delete': deleteCustomer(); break;
        default: errorResponse('Invalid action');
    }
}

function getAllCustomers() {
    requireRole(['admin', 'receptionist', 'staff']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['role' => 'customer', 'deleted_at' => null];
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [
            ['first_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['last_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['email' => new MongoDB\BSON\Regex($search, 'i')],
            ['phone' => new MongoDB\BSON\Regex($search, 'i')],
            ['account_number' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $collection = getCollection('users');
    if (!$collection) errorResponse('Database connection error');
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort' => ['created_at' => -1],
        'skip' => $skip,
        'limit' => $limit,
        'projection' => ['password_hash' => 0]
    ]);
    $list = [];
    foreach ($cursor as $u) {
        $list[] = [
            '_id' => (string)$u['_id'],
            'first_name' => $u['first_name'] ?? '',
            'last_name' => $u['last_name'] ?? '',
            'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
            'email' => $u['email'] ?? '',
            'phone' => $u['phone'] ?? '',
            'account_number' => $u['account_number'] ?? '',
            'account_type' => $u['account_type'] ?? '',
            'balance' => round((float)($u['balance'] ?? 0), 2),
            'status' => $u['status'] ?? 'active',
            'created_at' => mongoDateToPHP($u['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse([
        'customers' => $list,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ], 'Customers retrieved');
}

function createCustomer() {
    requireRole(['admin', 'receptionist', 'staff']);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $email = strtolower(trim(sanitizeInput($data['email'] ?? '')));
    $firstName = sanitizeInput($data['first_name'] ?? '');
    $lastName = sanitizeInput($data['last_name'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    $password = $data['password'] ?? '';
    $accountType = sanitizeInput($data['account_type'] ?? 'savings');
    if (empty($email) || empty($firstName) || empty($lastName)) errorResponse('Email, first name, and last name are required');
    if (!validateEmail($email)) errorResponse('Invalid email format');
    if (!empty($phone) && !validatePhone($phone)) errorResponse('Invalid phone number');
    if (empty($password)) errorResponse('Password is required');
    $passwordValidation = validatePasswordStrength($password);
    if (!$passwordValidation['valid']) errorResponse(implode(', ', $passwordValidation['errors']));
    $validAccountTypes = ['savings', 'current', 'salary', 'fixed'];
    if (!in_array($accountType, $validAccountTypes)) $accountType = 'savings';
    $collection = getCollection('users');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne(['email' => $email]);
    if ($existing) errorResponse('Email already registered');
    $accountNumber = generateReceptionistAccountNumber($collection);
    $doc = [
        'email' => $email,
        'password_hash' => hashPassword($password),
        'first_name' => $firstName,
        'last_name' => $lastName,
        'phone' => $phone,
        'role' => 'customer',
        'status' => 'active',
        'is_verified' => true,
        'account_number' => $accountNumber,
        'account_type' => $accountType,
        'balance' => 0.00,
        'currency' => 'INR',
        'theme_preference' => 'light',
        'department' => 'Customer',
        'login_attempts' => 0,
        'locked_until' => null,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'last_login' => null,
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create customer');
    $newUserId = (string)$result->getInsertedId();
    $wallets = getCollection('wallets');
    if ($wallets) {
        $wallets->insertOne([
            'user_id' => new MongoDB\BSON\ObjectId($newUserId),
            'name' => 'Main Account',
            'account_number' => $accountNumber,
            'balance' => 0.00,
            'currency' => 'INR',
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo(),
            'deleted_at' => null
        ]);
    }
    logActivity('customer_created', getCurrentUserId(), ['new_customer_id' => $newUserId, 'email' => $email]);
    successResponse(['customer_id' => $newUserId, 'account_number' => $accountNumber], 'Customer created successfully');
}

function generateReceptionistAccountNumber($collection) {
    do {
        $number = '5' . random_int(100000000000000, 999999999999999);
        $exists = $collection->findOne(['account_number' => $number]);
    } while ($exists);
    return $number;
}

function updateCustomer() {
    requireRole(['admin', 'receptionist', 'staff']);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $customerId = $data['customer_id'] ?? '';
    if (!isValidObjectId($customerId)) errorResponse('Invalid customer ID');
    $collection = getCollection('users');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($customerId), 'role' => 'customer', 'deleted_at' => null]);
    if (!$existing) errorResponse('Customer not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['first_name'])) $updateData['first_name'] = sanitizeInput($data['first_name']);
    if (isset($data['last_name'])) $updateData['last_name'] = sanitizeInput($data['last_name']);
    if (isset($data['phone'])) {
        $phone = sanitizeInput($data['phone']);
        if (!empty($phone) && !validatePhone($phone)) errorResponse('Invalid phone number');
        $updateData['phone'] = $phone;
    }
    if (isset($data['status']) && in_array($data['status'], ['active', 'suspended'])) {
        $updateData['status'] = $data['status'];
    }
    if (isset($data['account_type'])) {
        $validTypes = ['savings', 'current', 'salary', 'fixed'];
        if (in_array($data['account_type'], $validTypes)) $updateData['account_type'] = $data['account_type'];
    }
    if (isset($data['email'])) {
        $email = strtolower(trim(sanitizeInput($data['email'])));
        if (!validateEmail($email)) errorResponse('Invalid email format');
        if ($email !== ($existing['email'] ?? '')) {
            $dup = $collection->findOne(['email' => $email, '_id' => ['$ne' => new MongoDB\BSON\ObjectId($customerId)]]);
            if ($dup) errorResponse('Email already in use');
            $updateData['email'] = $email;
        }
    }
    if (count($updateData) <= 1) errorResponse('No fields to update');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($customerId)], ['$set' => $updateData]);
    logActivity('customer_updated', getCurrentUserId(), ['customer_id' => $customerId]);
    successResponse(['customer_id' => $customerId], 'Customer updated successfully');
}

function deleteCustomer() {
    requireRole(['admin', 'receptionist', 'staff']);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $customerId = $data['customer_id'] ?? '';
    if (!isValidObjectId($customerId)) errorResponse('Invalid customer ID');
    $collection = getCollection('users');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($customerId), 'role' => 'customer', 'deleted_at' => null]);
    if (!$existing) errorResponse('Customer not found');
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($customerId)],
        ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]
    );
    logActivity('customer_deleted', getCurrentUserId(), ['customer_id' => $customerId]);
    successResponse(null, 'Customer deleted successfully');
}
