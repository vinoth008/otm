<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$data = getRequestData();
$action = $data['action'] ?? ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get_all';
}

requireRole(['admin']);

$collection = getCollection('users');
if (!$collection) {
    errorResponse('Database connection error');
}

switch ($action) {
    case 'get_all':
    case 'get_users':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $skip = ($page - 1) * $limit;
        $filter = ['deleted_at' => null];
        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $regex = new MongoDB\BSON\Regex($search, 'i');
            $filter['$or'] = [
                ['first_name' => $regex],
                ['last_name' => $regex],
                ['email' => $regex],
                ['phone' => $regex],
                ['account_number' => $regex]
            ];
        }
        $role = trim($_GET['role'] ?? '');
        if ($role !== '') {
            $filter['role'] = $role;
        }
        $status = trim($_GET['status'] ?? '');
        if ($status !== '') {
            $filter['status'] = $status;
        }
        $total = $collection->countDocuments($filter);
        $cursor = $collection->find($filter, [
            'sort' => ['created_at' => -1],
            'skip' => $skip,
            'limit' => $limit
        ]);
        $users = [];
        foreach ($cursor as $u) {
            $users[] = [
                'user_id' => (string)$u['_id'],
                'full_name' => ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''),
                'first_name' => $u['first_name'] ?? '',
                'last_name' => $u['last_name'] ?? '',
                'email' => $u['email'] ?? '',
                'phone' => $u['phone'] ?? '',
                'role' => $u['role'] ?? 'customer',
                'status' => $u['status'] ?? 'active',
                'account_number' => $u['account_number'] ?? '',
                'balance' => $u['balance'] ?? 0,
                'created_at' => isset($u['created_at']) ? mongoDateToPHP($u['created_at'])->format('Y-m-d H:i:s') : ''
            ];
        }
        successResponse([
            'users' => $users,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total / $limit),
                'total_count' => $total,
                'limit' => $limit
            ]
        ], 'Users retrieved');
        break;

    case 'create':
        $email = strtolower(trim(sanitizeInput($data['email'] ?? '')));
        $firstName = sanitizeInput($data['first_name'] ?? '');
        $lastName = sanitizeInput($data['last_name'] ?? '');
        $phone = sanitizeInput($data['phone'] ?? '');
        $password = $data['password'] ?? '';
        $role = sanitizeInput($data['role'] ?? 'customer');
        $status = sanitizeInput($data['status'] ?? 'active');

        if (empty($email) || empty($firstName) || empty($lastName) || empty($password)) {
            errorResponse('Email, first name, last name, and password are required');
        }
        if (!validateEmail($email)) {
            errorResponse('Invalid email format');
        }
        $pwValidate = validatePasswordStrength($password);
        if (!$pwValidate['valid']) {
            errorResponse(implode(', ', $pwValidate['errors']));
        }
        if (!empty($phone) && !validatePhone($phone)) {
            errorResponse('Invalid phone number');
        }
        $validRoles = ['admin', 'staff', 'receptionist', 'customer'];
        if (!in_array($role, $validRoles, true)) {
            $role = 'customer';
        }
        if (!in_array($status, ['active', 'suspended'], true)) {
            $status = 'active';
        }
        $existing = $collection->findOne(['email' => $email]);
        if ($existing) {
            errorResponse('Email already registered');
        }
        $accountNumber = '5' . random_int(100000000000000, 999999999999999);
        $result = $collection->insertOne([
            'email' => $email,
            'password_hash' => hashPassword($password),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'role' => $role,
            'status' => $status,
            'account_number' => $accountNumber,
            'account_type' => 'savings',
            'balance' => 0.00,
            'login_attempts' => 0,
            'locked_until' => null,
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo(),
            'deleted_at' => null
        ]);
        $userId = (string)$result->getInsertedId();
        logActivity('admin_user_created', getCurrentUserId(), ['target' => $userId, 'email' => $email]);
        successResponse(['user_id' => $userId, 'account_number' => $accountNumber], 'User created successfully');
        break;

    case 'update':
        $userId = $data['user_id'] ?? '';
        if (!isValidObjectId($userId)) {
            errorResponse('Invalid user ID');
        }
        $user = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null]);
        if (!$user) {
            errorResponse('User not found');
        }
        $update = ['updated_at' => phpDateToMongo()];
        if (isset($data['first_name'])) {
            $update['first_name'] = sanitizeInput($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $update['last_name'] = sanitizeInput($data['last_name']);
        }
        if (isset($data['phone'])) {
            $phone = sanitizeInput($data['phone']);
            if (!validatePhone($phone)) {
                errorResponse('Invalid phone number');
            }
            $update['phone'] = $phone;
        }
        if (isset($data['role'])) {
            $role = sanitizeInput($data['role']);
            if (in_array($role, ['admin', 'staff', 'receptionist', 'customer'], true)) {
                $update['role'] = $role;
            }
        }
        if (isset($data['status'])) {
            $status = sanitizeInput($data['status']);
            if (in_array($status, ['active', 'suspended'], true)) {
                $update['status'] = $status;
            }
        }
        $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($userId)], ['$set' => $update]);
        logActivity('admin_user_updated', getCurrentUserId(), ['target' => $userId]);
        successResponse(null, 'User updated successfully');
        break;

    case 'update_status':
        $userId = $data['user_id'] ?? '';
        $status = sanitizeInput($data['status'] ?? '');
        if (!isValidObjectId($userId)) {
            errorResponse('Invalid user ID');
        }
        if (!in_array($status, ['active', 'suspended'], true)) {
            errorResponse('Invalid status');
        }
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($userId)],
            ['$set' => ['status' => $status, 'updated_at' => phpDateToMongo()]]
        );
        logActivity('admin_user_status_updated', getCurrentUserId(), ['target' => $userId, 'status' => $status]);
        successResponse(null, 'User status updated');
        break;

    case 'reset_password':
        $userId = $data['user_id'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        if (!isValidObjectId($userId)) {
            errorResponse('Invalid user ID');
        }
        $pwValidate = validatePasswordStrength($newPassword);
        if (!$pwValidate['valid']) {
            errorResponse(implode(', ', $pwValidate['errors']));
        }
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($userId)],
            [
                '$set' => [
                    'password_hash' => hashPassword($newPassword),
                    'login_attempts' => 0,
                    'locked_until' => null,
                    'updated_at' => phpDateToMongo()
                ]
            ]
        );
        logActivity('password_reset_by_admin', getCurrentUserId(), ['target' => $userId]);
        successResponse(null, 'Password reset successfully');
        break;

    case 'delete':
        $userId = $data['user_id'] ?? '';
        if (!isValidObjectId($userId)) {
            errorResponse('Invalid user ID');
        }
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($userId)],
            [
                '$set' => [
                    'deleted_at' => phpDateToMongo(),
                    'status' => 'suspended',
                    'updated_at' => phpDateToMongo()
                ]
            ]
        );
        logActivity('admin_user_deleted', getCurrentUserId(), ['target' => $userId]);
        successResponse(null, 'User deleted successfully');
        break;

    default:
        errorResponse('Invalid action', 400);
}
