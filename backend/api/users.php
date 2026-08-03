<?php
declare(strict_types=1);
// Users API (Admin)
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all': $method === 'GET' && getAllUsers(); break;
    case 'get': $method === 'GET' && getUser(); break;
    case 'create': $method === 'POST' && createUser(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateUser(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteUser(); break;
    case 'toggle_status': $method === 'POST' && toggleUserStatus(); break;
    default: errorResponse('Invalid action', 404);
}

function getAllUsers() {
    requireRole(['admin', 'staff', 'receptionist']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['deleted_at' => null];
    if (!empty($_GET['role'])) $filter['role'] = sanitizeInput($_GET['role']);
    if (!empty($_GET['status'])) $filter['status'] = sanitizeInput($_GET['status']);
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [
            ['first_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['last_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['email' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $collection = getCollection('users');
    $total = $collection->countDocuments($filter);
    $users = $collection->find($filter, ['sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit])->toArray();
    $formatted = array_map(function($u) {
        return [
            '_id' => (string)$u['_id'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'] ?? '',
            'email' => $u['email'],
            'phone' => $u['phone'] ?? '',
            'role' => $u['role'],
            'status' => $u['status'] ?? 'active',
            'created_at' => mongoDateToPHP($u['created_at'])->format('Y-m-d H:i:s'),
            'last_login' => isset($u['last_login']) ? mongoDateToPHP($u['last_login'])->format('Y-m-d H:i:s') : null
        ];
    }, $users);
    successResponse(['users' => $formatted, 'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]]);
}

function getUser() {
    requireRole(['admin', 'staff', 'receptionist']);
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid user ID');
    $collection = getCollection('users');
    $u = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null]);
    if (!$u) errorResponse('User not found');
    successResponse([
        '_id' => (string)$u['_id'],
        'first_name' => $u['first_name'],
        'last_name' => $u['last_name'] ?? '',
        'email' => $u['email'],
        'phone' => $u['phone'] ?? '',
        'role' => $u['role'],
        'status' => $u['status'] ?? 'active',
        'currency' => $u['currency'] ?? 'INR',
        'theme_preference' => $u['theme_preference'] ?? 'light',
        'created_at' => mongoDateToPHP($u['created_at'])->format('Y-m-d H:i:s'),
        'last_login' => isset($u['last_login']) ? mongoDateToPHP($u['last_login'])->format('Y-m-d H:i:s') : null
    ]);
}

function createUser() {
    requireRole(['admin']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $firstName = sanitizeInput($data['first_name'] ?? '');
    $lastName = sanitizeInput($data['last_name'] ?? '');
    $email = sanitizeInput($data['email'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    $role = $data['role'] ?? 'user';
    $password = $data['password'] ?? '';
    if (empty($firstName) || empty($email)) errorResponse('First name and email are required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) errorResponse('Invalid email address');
    $role = $role === 'customer' ? 'user' : $role;
    if (!in_array($role, ['user', 'admin', 'staff', 'receptionist'])) errorResponse('Invalid role');
    if (strlen($password) < 8) errorResponse('Password must be at least 8 characters');
    $collection = getCollection('users');
    if ($collection->findOne(['email' => $email, 'deleted_at' => null])) errorResponse('Email already registered');
    $doc = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'role' => $role,
        'status' => 'active',
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'currency' => 'INR',
        'theme_preference' => 'light',
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create user');
    logActivity('user_created', (string)$result->getInsertedId(), ['created_by' => getCurrentUserId(), 'role' => $role]);
    successResponse(['user_id' => (string)$result->getInsertedId()], 'User created successfully');
}

function updateUser() {
    requireRole(['admin']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid user ID');
    $collection = getCollection('users');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null]);
    if (!$existing) errorResponse('User not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['first_name'])) $updateData['first_name'] = sanitizeInput($data['first_name']);
    if (isset($data['last_name'])) $updateData['last_name'] = sanitizeInput($data['last_name']);
    if (isset($data['phone'])) $updateData['phone'] = sanitizeInput($data['phone']);
    if (isset($data['role'])) {
        $newRole = $data['role'] === 'customer' ? 'user' : $data['role'];
        if (in_array($newRole, ['user', 'admin', 'staff', 'receptionist'])) $updateData['role'] = $newRole;
    }
    if (isset($data['status']) && in_array($data['status'], ['active', 'suspended', 'inactive'])) $updateData['status'] = $data['status'];
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('user_updated', $id, ['updated_by' => getCurrentUserId()]);
    successResponse(['user_id' => $id, 'updated' => true], 'User updated successfully');
}

function deleteUser() {
    requireRole(['admin']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid user ID');
    if ($id === getCurrentUserId()) errorResponse('You cannot delete your own account');
    $collection = getCollection('users');
    $u = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null]);
    if (!$u) errorResponse('User not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['deleted_at' => phpDateToMongo(), 'status' => 'inactive', 'updated_at' => phpDateToMongo()]]);
    logActivity('user_deleted', $id, ['deleted_by' => getCurrentUserId()]);
    successResponse(null, 'User deleted successfully');
}

function toggleUserStatus() {
    requireRole(['admin']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid user ID');
    $collection = getCollection('users');
    $u = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null]);
    if (!$u) errorResponse('User not found');
    $newStatus = ($u['status'] ?? 'active') === 'active' ? 'suspended' : 'active';
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['status' => $newStatus, 'updated_at' => phpDateToMongo()]]);
    logActivity('user_status_changed', $id, ['new_status' => $newStatus]);
    successResponse(['user_id' => $id, 'status' => $newStatus], 'User status updated');
}