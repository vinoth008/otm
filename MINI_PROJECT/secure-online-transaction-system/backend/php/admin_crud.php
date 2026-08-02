<?php
// backend/php/admin_crud.php
/**
 * Admin Management for Smart Transaction Control
 * Handles user management, roles, dashboard stats, and system settings (admin only)
 * Roles: admin, manager, user, auditor
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
 * Get list of valid roles
 * @return array
 */
function getValidRoles() {
    return ['admin', 'manager', 'user', 'auditor'];
}
/**
 * Normalize a role input to canonical role
 * @param string $role
 * @return string
 */
function normalizeRoleInput($role) {
    $map = [
        'admin' => 'admin',
        'administrator' => 'admin',
        'manager' => 'manager',
        'staff' => 'manager',
        'user' => 'user',
        'employee' => 'user',
        'customer' => 'user',
        'receptionist' => 'user',
        'auditor' => 'auditor',
        'audit' => 'auditor'
    ];
    $key = strtolower((string)$role);
    return $map[$key] ?? 'user';
}
/**
 * Admin: list all users
 * GET: role (optional), search (optional), page, limit
 */
function adminListUsers() {
    requireRole(['admin', 'manager']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $collection = getCollection('users');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $filter = ['status' => ['$ne' => 'deleted']];
    $roleFilter = $_GET['role'] ?? '';
    if (in_array(normalizeRoleInput($roleFilter), getValidRoles(), true) && $roleFilter !== '') {
        $filter['role'] = normalizeRoleInput($roleFilter);
    }
    $search = $_GET['search'] ?? '';
    if ($search !== '') {
        $filter['$or'] = [
            ['first_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['last_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['email' => new MongoDB\BSON\Regex($search, 'i')],
            ['department' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort' => ['created_at' => -1],
        'skip' => $skip,
        'limit' => $limit,
        'projection' => [
            'password_hash' => 0,
            'login_attempts' => 0,
            'locked_until' => 0
        ]
    ]);
    $list = [];
    foreach ($cursor as $u) {
        $list[] = [
            'user_id' => (string)$u['_id'],
            'first_name' => $u['first_name'] ?? '',
            'last_name' => $u['last_name'] ?? '',
            'name' => ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''),
            'email' => $u['email'] ?? '',
            'phone' => $u['phone'] ?? '',
            'role' => normalizeRoleInput($u['role'] ?? 'user'),
            'department' => $u['department'] ?? 'General',
            'status' => $u['status'] ?? 'active',
            'created_at' => isset($u['created_at'])
                ? mongoDateToPHP($u['created_at'])->format('Y-m-d H:i:s')
                : ''
        ];
    }
    successResponse([
        'users' => $list,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ], 'Users retrieved');
}
/**
 * Admin: create a user
 * POST: first_name, last_name, email, phone, password, role, department
 */
function adminCreateUser() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $firstName = sanitizeInput($data['first_name'] ?? '');
    $lastName = sanitizeInput($data['last_name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $phone = sanitizeInput($data['phone'] ?? '');
    $password = $data['password'] ?? '';
    $role = normalizeRoleInput($data['role'] ?? 'user');
    $department = sanitizeInput($data['department'] ?? 'General');
    if (empty($firstName) || empty($lastName) || empty($email)) {
        errorResponse('Name and email are required');
    }
    if (!validateEmail($email)) {
        errorResponse('Enter a valid email');
    }
    if (!empty($phone) && !validatePhone($phone)) {
        errorResponse('Enter a valid phone number');
    }
    if (!in_array($role, getValidRoles(), true)) {
        errorResponse('Invalid role');
    }
    $passwordCheck = validatePasswordStrength($password);
    if (!$passwordCheck['valid']) {
        errorResponse(implode('; ', $passwordCheck['errors']));
    }
    $collection = getCollection('users');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $exists = $collection->findOne(['email' => $email]);
    if ($exists) {
        errorResponse('Email already registered');
    }
    $result = $collection->insertOne([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'password_hash' => hashPassword($password),
        'role' => $role,
        'department' => $department,
        'status' => 'active',
        'is_verified' => true,
        'theme_preference' => 'light',
        'currency' => 'INR',
        'login_attempts' => 0,
        'locked_until' => null,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('user_created_by_admin', getCurrentUserId(), [
        'user_id' => (string)$result->getInsertedId(),
        'role' => $role,
        'email' => $email
    ]);
    successResponse(['user_id' => (string)$result->getInsertedId()], 'User created successfully');
}
/**
 * Admin: update user role/status/department
 * POST: user_id, role, status, department
 */
function adminUpdateUser() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $userId = $data['user_id'] ?? '';
    if (!isValidObjectId($userId)) {
        errorResponse('Invalid user ID');
    }
    $collection = getCollection('users');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $user = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
    if (!$user) {
        errorResponse('User not found');
    }
    if ((string)$user['_id'] === getCurrentUserId()) {
        errorResponse('You cannot modify your own account here');
    }
    $update = ['updated_at' => phpDateToMongo()];
    $role = isset($data['role']) ? normalizeRoleInput($data['role']) : '';
    if (in_array($role, getValidRoles(), true)) {
        $update['role'] = $role;
    }
    $status = sanitizeInput($data['status'] ?? '');
    if (in_array($status, ['active', 'suspended', 'deleted'], true)) {
        $update['status'] = $status;
    }
    $department = sanitizeInput($data['department'] ?? '');
    if ($department !== '') {
        $update['department'] = $department;
    }
    $collection->updateOne(['_id' => $user['_id']], ['$set' => $update]);
    logActivity('user_updated_by_admin', getCurrentUserId(), [
        'user_id' => $userId,
        'changes' => $update
    ]);
    successResponse(null, 'User updated successfully');
}
/**
 * Admin: list all roles
 * GET
 */
function adminListRoles() {
    requireRole(['admin']);
    // Return the 4 canonical roles
    $roles = [
        [
            'role_id' => 'admin',
            'name' => 'Admin',
            'description' => 'Full system access - manage users, expenses, categories, departments, settings',
            'permissions' => ['*']
        ],
        [
            'role_id' => 'manager',
            'name' => 'Manager',
            'description' => 'Approve/reject expenses, view department reports, manage team',
            'permissions' => ['expense.approve', 'expense.reject', 'expense.view_all', 'report.department', 'dashboard.manager']
        ],
        [
            'role_id' => 'user',
            'name' => 'Employee',
            'description' => 'Add/edit/delete own expenses, upload bills, view own history and reports',
            'permissions' => ['expense.create', 'expense.edit_own', 'expense.delete_own', 'expense.view_own', 'report.own', 'dashboard.user']
        ],
        [
            'role_id' => 'auditor',
            'name' => 'Auditor',
            'description' => 'Read-only access - view transactions, reports, audit logs, analytics',
            'permissions' => ['transaction.view_all', 'report.view', 'audit.view', 'analytics.view', 'readonly']
        ]
    ];
    successResponse(['roles' => $roles], 'Roles retrieved');
}
/**
 * Admin: create or update a role
 * POST: role_id (optional for update), name, description, permissions
 */
function adminSaveRole() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $roleId = $data['role_id'] ?? '';
    $name = strtoupper(sanitizeInput($data['name'] ?? ''));
    $description = sanitizeInput($data['description'] ?? '');
    $permissions = $data['permissions'] ?? [];
    if (empty($name)) {
        errorResponse('Role name is required');
    }
    if (!is_array($permissions)) {
        $permissions = [];
    }
    $collection = getCollection('roles');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    if (!empty($roleId) && $roleId !== 'admin' && $roleId !== 'manager' && $roleId !== 'user' && $roleId !== 'auditor') {
        // Custom roles stored in DB
        $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($roleId)]);
        if ($existing) {
            $collection->updateOne(
                ['_id' => $existing['_id']],
                ['$set' => [
                    'name' => $name,
                    'description' => $description,
                    'permissions' => array_values($permissions),
                    'updated_at' => phpDateToMongo()
                ]]
            );
            successResponse(null, 'Role updated successfully');
        }
        errorResponse('Role not found');
    }
    // Insert custom role
    $result = $collection->insertOne([
        'name' => $name,
        'description' => $description,
        'permissions' => array_values($permissions),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo()
    ]);
    logActivity('role_created', getCurrentUserId(), ['role_id' => (string)$result->getInsertedId(), 'name' => $name]);
    successResponse(['role_id' => (string)$result->getInsertedId()], 'Role created successfully');
}
/**
 * Admin: get dashboard statistics
 * GET
 */
function adminGetStats() {
    requireRole(['admin', 'manager', 'auditor']);
    $stats = [];
    $users = getCollection('users');
    $stats['total_users'] = $users ? $users->countDocuments(['status' => ['$ne' => 'deleted']]) : 0;
    $stats['managers'] = $users ? $users->countDocuments(['role' => 'manager', 'status' => ['$ne' => 'deleted']]) : 0;
    $stats['employees'] = $users ? $users->countDocuments(['role' => 'user', 'status' => ['$ne' => 'deleted']]) : 0;
    $stats['auditors'] = $users ? $users->countDocuments(['role' => 'auditor', 'status' => ['$ne' => 'deleted']]) : 0;
    $stats['staff_users'] = $stats['managers'];
    $stats['receptionist_users'] = 0;
    $stats['customers'] = $stats['employees'];
    $transactions = getCollection('transactions');
    $stats['total_transactions'] = $transactions ? $transactions->countDocuments(['deleted_at' => null]) : 0;
    // Expense stats
    $expenses = getCollection('expenses');
    $stats['total_expenses'] = $expenses ? $expenses->countDocuments(['deleted_at' => null]) : 0;
    $stats['pending_expenses'] = $expenses ? $expenses->countDocuments(['status' => 'pending', 'deleted_at' => null]) : 0;
    $stats['approved_expenses'] = $expenses ? $expenses->countDocuments(['status' => 'approved', 'deleted_at' => null]) : 0;
    $stats['rejected_expenses'] = $expenses ? $expenses->countDocuments(['status' => 'rejected', 'deleted_at' => null]) : 0;
    $incomePipeline = [
        ['$match' => ['type' => 'income', 'deleted_at' => null]],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
    ];
    $income = $transactions ? $transactions->aggregate($incomePipeline)->toArray() : [];
    $stats['total_income'] = round((float)($income[0]['total'] ?? 0), 2);
    $expensePipeline = [
        ['$match' => ['type' => 'expense', 'deleted_at' => null]],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
    ];
    $expense = $transactions ? $transactions->aggregate($expensePipeline)->toArray() : [];
    $stats['total_expense'] = round((float)($expense[0]['total'] ?? 0), 2);
    // Net income
    $stats['net_income'] = round((float)($stats['total_income'] - $stats['total_expense']), 2);
    $wallets = getCollection('wallets');
    $walletPipeline = [
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$balance']]]
    ];
    $walletTotals = $wallets ? $wallets->aggregate($walletPipeline)->toArray() : [];
    $stats['total_wallet_balance'] = round((float)($walletTotals[0]['total'] ?? 0), 2);
    $stats['open_items'] = $stats['pending_expenses'];
    successResponse($stats, 'Dashboard statistics retrieved');
}
/**
 * Admin: get system settings
 * GET
 */
function adminGetSettings() {
    requireRole(['admin']);
    $collection = getCollection('system_settings');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $settings = [];
    $cursor = $collection->find([]);
    foreach ($cursor as $s) {
        $settings[] = [
            'key' => $s['key'] ?? '',
            'value' => $s['value'] ?? '',
            'description' => $s['description'] ?? '',
            'type' => $s['type'] ?? 'text'
        ];
    }
    successResponse(['settings' => $settings], 'Settings retrieved');
}
/**
 * Admin: update system settings
 * POST: settings (array of key => value)
 */
function adminSaveSettings() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $settings = $data['settings'] ?? [];
    if (!is_array($settings) || empty($settings)) {
        errorResponse('No settings provided');
    }
    $collection = getCollection('system_settings');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    foreach ($settings as $key => $value) {
        $collection->updateOne(
            ['key' => $key],
            ['$set' => ['value' => sanitizeInput((string)$value), 'updated_at' => phpDateToMongo()]],
            ['upsert' => true]
        );
    }
    logActivity('settings_updated', getCurrentUserId());
    successResponse(null, 'Settings saved successfully');
}
/**
 * Admin: list departments
 * GET
 */
function adminListDepartments() {
    requireRole(['admin']);
    $collection = getCollection('departments');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $cursor = $collection->find([], ['sort' => ['name' => 1]]);
    $list = [];
    foreach ($cursor as $d) {
        $list[] = [
            'department_id' => (string)$d['_id'],
            'name' => $d['name'] ?? '',
            'code' => $d['code'] ?? '',
            'description' => $d['description'] ?? '',
            'created_at' => isset($d['created_at'])
                ? mongoDateToPHP($d['created_at'])->format('Y-m-d H:i:s')
                : ''
        ];
    }
    successResponse(['departments' => $list], 'Departments retrieved');
}
/**
 * Admin: save department
 * POST: department_id (optional), name, code, description
 */
function adminSaveDepartment() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $deptId = $data['department_id'] ?? '';
    $name = sanitizeInput($data['name'] ?? '');
    $code = sanitizeInput($data['code'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');
    if (empty($name)) {
        errorResponse('Department name is required');
    }
    $collection = getCollection('departments');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    if (!empty($deptId) && isValidObjectId($deptId)) {
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($deptId)],
            ['$set' => [
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'updated_at' => phpDateToMongo()
            ]]
        );
        successResponse(null, 'Department updated successfully');
    }
    $result = $collection->insertOne([
        'name' => $name,
        'code' => $code,
        'description' => $description,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo()
    ]);
    successResponse(['department_id' => (string)$result->getInsertedId()], 'Department created successfully');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_users':
        if ($method === 'GET') adminListUsers();
        break;
    case 'create_user':
        if ($method === 'POST') adminCreateUser();
        break;
    case 'update_user':
        if ($method === 'POST') adminUpdateUser();
        break;
    case 'get_roles':
        if ($method === 'GET') adminListRoles();
        break;
    case 'save_role':
        if ($method === 'POST') adminSaveRole();
        break;
    case 'get_stats':
        if ($method === 'GET') adminGetStats();
        break;
    case 'get_settings':
        if ($method === 'GET') adminGetSettings();
        break;
    case 'save_settings':
        if ($method === 'POST') adminSaveSettings();
        break;
    case 'get_departments':
        if ($method === 'GET') adminListDepartments();
        break;
    case 'save_department':
        if ($method === 'POST') adminSaveDepartment();
        break;
    default:
        errorResponse('Invalid action');
}
?>