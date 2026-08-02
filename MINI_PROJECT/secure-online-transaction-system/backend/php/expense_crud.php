<?php
// backend/php/expense_crud.php
/**
 * Expense Management for Smart Transaction Control
 * Employee: create/edit/delete/view own expenses with bill uploads
 * Manager/Admin: approve/reject/view expenses
 * Auditor: read-only access to all expenses
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
 * Build expense filter based on role
 * - user/employee: sees only own expenses
 * - manager: sees expenses for users in same department (or all if none)
 * - admin: sees all
 * - auditor: sees all (read-only)
 * @return array
 */
function getExpenseFilterForRole() {
    $role = getCurrentUserRole();
    $filter = ['deleted_at' => null];
    if ($role === 'user' || $role === 'customer' || $role === 'receptionist' || $role === 'employee') {
        $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
    }
    // Manager: could filter by department - get current user's department
    if ($role === 'manager' || $role === 'staff') {
        $collection = getCollection('users');
        $user = $collection->findOne(
            ['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())],
            ['projection' => ['department' => 1]]
        );
        $department = $user['department'] ?? '';
        if ($department) {
            // Find all users in same department
            $users = $collection->find(
                ['department' => $department, 'status' => ['$ne' => 'deleted']],
                ['projection' => ['_id' => 1]]
            )->toArray();
            $userIds = array_map(function ($u) {
                return $u['_id'];
            }, $users);
            if (!empty($userIds)) {
                $filter['user_id'] = ['$in' => $userIds];
            }
        }
    }
    return $filter;
}
/**
 * Get expenses
 * GET: status, category, search, page, limit, date_from, date_to, user_id (admin/auditor)
 */
function getExpenses() {
    requireActiveSession();
    $role = getCurrentUserRole();
    $collection = getCollection('expenses');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $filter = getExpenseFilterForRole();
    // Status filter
    if (!empty($_GET['status']) && in_array($_GET['status'], ['pending', 'approved', 'rejected', 'completed'], true)) {
        $filter['status'] = sanitizeInput($_GET['status']);
    }
    // Category filter
    if (!empty($_GET['category'])) {
        $filter['category'] = sanitizeInput($_GET['category']);
    }
    // Search (description, category)
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [
            ['description' => new MongoDB\BSON\Regex($search, 'i')],
            ['category' => new MongoDB\BSON\Regex($search, 'i')],
            ['subcategory' => new MongoDB\BSON\Regex($search, 'i')],
            ['notes' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    // Date range
    if (!empty($_GET['date_from']) && validateDate($_GET['date_from'])) {
        $filter['expense_date'] = ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($_GET['date_from'] . ' 00:00:00') * 1000)];
    }
    if (!empty($_GET['date_to']) && validateDate($_GET['date_to'])) {
        $filter['expense_date'] = array_merge(
            $filter['expense_date'] ?? [],
            ['$lte' => new MongoDB\BSON\UTCDateTime(strtotime($_GET['date_to'] . ' 23:59:59') * 1000)]
        );
    }
    // Specific user (admin/auditor/manager)
    if (!empty($_GET['user_id']) && isValidObjectId($_GET['user_id']) && in_array($role, ['admin', 'auditor', 'manager'], true)) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId($_GET['user_id']);
    }
    // Pagination
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort' => ['expense_date' => -1, 'created_at' => -1],
        'skip' => $skip,
        'limit' => $limit
    ]);
    $list = [];
    // Get user name lookup
    $users = getCollection('users');
    $userNames = [];
    if ($users) {
        $userCursor = $users->find(
            ['_id' => ['$in' => array_values(array_unique(array_map(function ($e) {
                return isset($e['user_id']) ? $e['user_id'] : new MongoDB\BSON\ObjectId('000000000000000000000000');
            }, iterator_to_array($cursor, false))))]],
            ['projection' => ['first_name' => 1, 'last_name' => 1]]
        );
        foreach ($userCursor as $u) {
            $userNames[(string)$u['_id']] = ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '');
        }
        // Re-run the main cursor after consuming it for names (iterator is single-use)
        $cursor = $collection->find($filter, [
            'sort' => ['expense_date' => -1, 'created_at' => -1],
            'skip' => $skip,
            'limit' => $limit
        ]);
    }
    foreach ($cursor as $e) {
        $userId = isset($e['user_id']) ? (string)$e['user_id'] : '';
        $list[] = [
            'expense_id' => (string)$e['_id'],
            'category' => $e['category'] ?? '',
            'subcategory' => $e['subcategory'] ?? null,
            'description' => $e['description'] ?? '',
            'amount' => round((float)($e['amount'] ?? 0), 2),
            'expense_date' => mongoDateToPHP($e['expense_date'] ?? null)->format('Y-m-d'),
            'user_id' => $userId,
            'user_name' => $userNames[$userId] ?? '',
            'payment_method' => $e['payment_method'] ?? 'cash',
            'status' => $e['status'] ?? 'pending',
            'receipt_url' => $e['receipt_url'] ?? null,
            'notes' => $e['notes'] ?? null,
            'approved_by' => isset($e['approved_by']) ? (string)$e['approved_by'] : '',
            'approved_at' => isset($e['approved_at']) ? mongoDateToPHP($e['approved_at'])->format('Y-m-d H:i:s') : null,
            'rejection_reason' => $e['rejection_reason'] ?? null,
            'created_at' => mongoDateToPHP($e['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse([
        'expenses' => $list,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ], 'Expenses retrieved');
}
/**
 * Get single expense
 * GET: expense_id
 */
function getExpense() {
    requireActiveSession();
    $expenseId = $_GET['expense_id'] ?? '';
    if (!isValidObjectId($expenseId)) {
        errorResponse('Invalid expense ID');
    }
    $collection = getCollection('expenses');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $expense = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($expenseId),
        'deleted_at' => null
    ]);
    if (!$expense) {
        errorResponse('Expense not found');
    }
    // Check access
    $role = getCurrentUserRole();
    $userId = getCurrentUserId();
    $ownerId = isset($expense['user_id']) ? (string)$expense['user_id'] : '';
    if (!$role || ($role === 'user' && $ownerId !== $userId)) {
        errorResponse('Access denied', 403);
    }
    successResponse([
        'expense_id' => (string)$expense['_id'],
        'category' => $expense['category'] ?? '',
        'subcategory' => $expense['subcategory'] ?? null,
        'description' => $expense['description'] ?? '',
        'amount' => round((float)($expense['amount'] ?? 0), 2),
        'expense_date' => mongoDateToPHP($expense['expense_date'] ?? null)->format('Y-m-d'),
        'user_id' => $ownerId,
        'payment_method' => $expense['payment_method'] ?? 'cash',
        'status' => $expense['status'] ?? 'pending',
        'receipt_url' => $expense['receipt_url'] ?? null,
        'notes' => $expense['notes'] ?? null,
        'rejection_reason' => $expense['rejection_reason'] ?? null,
        'created_at' => mongoDateToPHP($expense['created_at'] ?? null)->format('Y-m-d H:i:s'),
        'updated_at' => mongoDateToPHP($expense['updated_at'] ?? null)->format('Y-m-d H:i:s')
    ], 'Expense retrieved');
}
/**
 * Create an expense (employee/user)
 * POST: category, subcategory, description, amount, expense_date, payment_method, notes, receipt (file)
 */
function createExpense() {
    $role = getCurrentUserRole();
    if (!in_array($role, ['user', 'customer', 'receptionist', 'employee', 'staff', 'manager', 'admin'], true)) {
        errorResponse('Access denied', 403);
    }
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $category = sanitizeInput($data['category'] ?? '');
    $subcategory = sanitizeInput($data['subcategory'] ?? '') ?: null;
    $description = sanitizeInput($data['description'] ?? '');
    $amount = (float)($data['amount'] ?? 0);
    $expenseDate = sanitizeInput($data['expense_date'] ?? date('Y-m-d'));
    $paymentMethod = sanitizeInput($data['payment_method'] ?? 'cash');
    $notes = sanitizeInput($data['notes'] ?? '');
    if (empty($category) || empty($description)) {
        errorResponse('Category and description are required');
    }
    if ($amount <= 0 || !validateAmount((string)$amount)) {
        errorResponse('Enter a valid amount greater than 0');
    }
    if (!validateDate($expenseDate)) {
        errorResponse('Invalid expense date');
    }
    $validPaymentMethods = ['cash', 'card', 'upi', 'bank_transfer', 'wallet', 'credit_card', 'debit_card', 'other'];
    if (!in_array($paymentMethod, $validPaymentMethods, true)) {
        $paymentMethod = 'cash';
    }
    $collection = getCollection('expenses');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'category' => $category,
        'subcategory' => $subcategory,
        'description' => $description,
        'amount' => $amount,
        'expense_date' => phpDateToMongo($expenseDate . ' 00:00:00'),
        'payment_method' => $paymentMethod,
        'notes' => $notes,
        'status' => 'pending',
        'receipt_url' => null,
        'approved_by' => null,
        'approved_at' => null,
        'rejection_reason' => null,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) {
        errorResponse('Failed to create expense');
    }
    $expenseId = (string)$result->getInsertedId();
    // Handle receipt upload
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $receiptResult = handleExpenseReceiptUpload($_FILES['receipt'], $expenseId);
        if ($receiptResult['success']) {
            $collection->updateOne(
                ['_id' => $result->getInsertedId()],
                ['$set' => ['receipt_url' => $receiptResult['url']]]
            );
        }
    }
    // Create notification for manager
    createApprovalNotification($expenseId);
    logActivity('expense_created', getCurrentUserId(), [
        'expense_id' => $expenseId,
        'amount' => $amount,
        'category' => $category
    ]);
    successResponse(['expense_id' => $expenseId], 'Expense submitted for approval');
}
/**
 * Update own expense (employee, status must be pending)
 * POST: expense_id, category, subcategory, description, amount, expense_date, payment_method, notes
 */
function updateExpense() {
    $role = getCurrentUserRole();
    if (!in_array($role, ['user', 'customer', 'receptionist', 'employee', 'admin'], true)) {
        errorResponse('Access denied', 403);
    }
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $expenseId = $data['expense_id'] ?? '';
    if (!isValidObjectId($expenseId)) {
        errorResponse('Invalid expense ID');
    }
    $collection = getCollection('expenses');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $expense = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($expenseId),
        'deleted_at' => null
    ]);
    if (!$expense) {
        errorResponse('Expense not found');
    }
    // Only owner can edit (admin can too)
    $ownerId = isset($expense['user_id']) ? (string)$expense['user_id'] : '';
    if ($ownerId !== getCurrentUserId() && $role !== 'admin') {
        errorResponse('Access denied', 403);
    }
    // Can only edit pending expenses (admin can edit any)
    if ($role !== 'admin' && ($expense['status'] ?? 'pending') !== 'pending') {
        errorResponse('Only pending expenses can be edited');
    }
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['category'])) {
        $updateData['category'] = sanitizeInput($data['category']);
    }
    if (isset($data['subcategory'])) {
        $updateData['subcategory'] = sanitizeInput($data['subcategory']) ?: null;
    }
    if (isset($data['description'])) {
        $updateData['description'] = sanitizeInput($data['description']);
    }
    if (isset($data['amount'])) {
        $amount = (float)$data['amount'];
        if ($amount <= 0 || !validateAmount((string)$amount)) {
            errorResponse('Enter a valid amount greater than 0');
        }
        $updateData['amount'] = $amount;
    }
    if (isset($data['expense_date'])) {
        if (!validateDate($data['expense_date'])) {
            errorResponse('Invalid expense date');
        }
        $updateData['expense_date'] = phpDateToMongo($data['expense_date'] . ' 00:00:00');
    }
    if (isset($data['payment_method'])) {
        $validPaymentMethods = ['cash', 'card', 'upi', 'bank_transfer', 'wallet', 'credit_card', 'debit_card', 'other'];
        if (in_array($data['payment_method'], $validPaymentMethods, true)) {
            $updateData['payment_method'] = $data['payment_method'];
        }
    }
    if (isset($data['notes'])) {
        $updateData['notes'] = sanitizeInput($data['notes']);
    }
    // Handle new receipt
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        if ($expense['receipt_url']) {
            $oldPath = str_replace(BASE_URL, UPLOAD_DIR, $expense['receipt_url']);
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }
        $receiptResult = handleExpenseReceiptUpload($_FILES['receipt'], $expenseId);
        if ($receiptResult['success']) {
            $updateData['receipt_url'] = $receiptResult['url'];
        }
    }
    $collection->updateOne(
        ['_id' => $expense['_id']],
        ['$set' => $updateData]
    );
    logActivity('expense_updated', getCurrentUserId(), [
        'expense_id' => $expenseId,
        'changes' => array_keys($updateData)
    ]);
    successResponse(null, 'Expense updated successfully');
}
/**
 * Delete own expense (employee, status must be pending)
 * POST: expense_id
 */
function deleteExpense() {
    $role = getCurrentUserRole();
    if (!in_array($role, ['user', 'customer', 'receptionist', 'employee', 'admin'], true)) {
        errorResponse('Access denied', 403);
    }
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $expenseId = $data['expense_id'] ?? '';
    if (!isValidObjectId($expenseId)) {
        errorResponse('Invalid expense ID');
    }
    $collection = getCollection('expenses');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $expense = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($expenseId),
        'deleted_at' => null
    ]);
    if (!$expense) {
        errorResponse('Expense not found');
    }
    $ownerId = isset($expense['user_id']) ? (string)$expense['user_id'] : '';
    if ($ownerId !== getCurrentUserId() && $role !== 'admin') {
        errorResponse('Access denied', 403);
    }
    if ($role !== 'admin' && ($expense['status'] ?? 'pending') !== 'pending') {
        errorResponse('Only pending expenses can be deleted');
    }
    // Soft delete
    $collection->updateOne(
        ['_id' => $expense['_id']],
        ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]
    );
    // Delete receipt
    if ($expense['receipt_url']) {
        $receiptPath = str_replace(BASE_URL, UPLOAD_DIR, $expense['receipt_url']);
        if (file_exists($receiptPath)) {
            unlink($receiptPath);
        }
    }
    logActivity('expense_deleted', getCurrentUserId(), [
        'expense_id' => $expenseId,
        'amount' => $expense['amount'] ?? 0
    ]);
    successResponse(null, 'Expense deleted successfully');
}
/**
 * Approve/reject expense (manager/admin)
 * POST: expense_id, status (approved/rejected), rejection_reason (if rejected)
 */
function updateExpenseStatus() {
    $role = getCurrentUserRole();
    if (!in_array($role, ['admin', 'manager', 'staff'], true)) {
        errorResponse('Access denied', 403);
    }
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $expenseId = $data['expense_id'] ?? '';
    $status = sanitizeInput($data['status'] ?? '');
    $rejectionReason = sanitizeInput($data['rejection_reason'] ?? '');
    if (!isValidObjectId($expenseId)) {
        errorResponse('Invalid expense ID');
    }
    if (!in_array($status, ['approved', 'rejected', 'completed'], true)) {
        errorResponse('Invalid status');
    }
    $collection = getCollection('expenses');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $expense = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($expenseId)]);
    if (!$expense) {
        errorResponse('Expense not found');
    }
    // For manager: only allow if expense belongs to same department
    if ($role === 'manager' || $role === 'staff') {
        $expenseOwnerId = isset($expense['user_id']) ? (string)$expense['user_id'] : '';
        $users = getCollection('users');
        $managerUser = $users->findOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['projection' => ['department' => 1]]);
        $ownerUser = $users->findOne(['_id' => new MongoDB\BSON\ObjectId($expenseOwnerId)], ['projection' => ['department' => 1]]);
        $managerDept = $managerUser['department'] ?? '';
        $ownerDept = $ownerUser['department'] ?? '';
        if ($managerDept && $ownerDept && $managerDept !== $ownerDept) {
            errorResponse('You can only manage expenses in your own department', 403);
        }
    }
    $update = [
        'status' => $status,
        'approved_by' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'approved_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo()
    ];
    if ($status === 'rejected') {
        $update['rejection_reason'] = $rejectionReason ?: 'No reason provided';
    }
    $collection->updateOne(['_id' => $expense['_id']], ['$set' => $update]);
    logActivity('expense_status_changed', getCurrentUserId(), [
        'expense_id' => $expenseId,
        'status' => $status
    ]);
    successResponse(null, 'Expense ' . $status);
}
/**
 * Get expense statistics
 * GET: period (today, week, month, year)
 */
function getExpenseStats() {
    requireActiveSession();
    $role = getCurrentUserRole();
    $period = $_GET['period'] ?? 'month';
    $dateRange = calculateDateRange($period);
    $filter = getExpenseFilterForRole();
    $filter['expense_date'] = [
        '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($dateRange['from'] . ' 00:00:00') * 1000),
        '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($dateRange['to'] . ' 23:59:59') * 1000)
    ];
    $collection = getCollection('expenses');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    // Totals by status
    $pipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => '$status',
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]]
    ];
    $results = $collection->aggregate($pipeline)->toArray();
    $stats = [
        'period' => $period,
        'date_from' => $dateRange['from'],
        'date_to' => $dateRange['to'],
        'total_expense' => 0,
        'total_count' => 0,
        'pending' => 0,
        'pending_count' => 0,
        'approved' => 0,
        'approved_count' => 0,
        'rejected' => 0,
        'rejected_count' => 0,
        'completed' => 0,
        'completed_count' => 0
    ];
    foreach ($results as $r) {
        $status = $r['_id'] ?? 'pending';
        $stats[$status] = round((float)($r['total'] ?? 0), 2);
        $stats[$status . '_count'] = $r['count'] ?? 0;
        $stats['total_expense'] += round((float)($r['total'] ?? 0), 2);
        $stats['total_count'] += $r['count'] ?? 0;
    }
    // Category breakdown
    $categoryPipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => '$category',
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['total' => -1]]
    ];
    $categoryResults = $collection->aggregate($categoryPipeline)->toArray();
    $categoryBreakdown = [];
    foreach ($categoryResults as $item) {
        $categoryBreakdown[] = [
            'category' => $item['_id'],
            'total' => round((float)$item['total'], 2),
            'count' => $item['count'],
            'percentage' => $stats['total_expense'] > 0 ? round(($item['total'] / $stats['total_expense']) * 100, 2) : 0
        ];
    }
    $stats['category_breakdown'] = $categoryBreakdown;
    // Daily trend for charts
    $dailyPipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => [
                'year' => ['$year' => '$expense_date'],
                'month' => ['$month' => '$expense_date'],
                'day' => ['$dayOfMonth' => '$expense_date']
            ],
            'total' => ['$sum' => '$amount']
        ]],
        ['$sort' => ['_id' => 1]]
    ];
    $dailyResults = $collection->aggregate($dailyPipeline)->toArray();
    $dailyTrend = [];
    foreach ($dailyResults as $item) {
        $dailyTrend[] = [
            'date' => sprintf('%04d-%02d-%02d', $item['_id']['year'], $item['_id']['month'], $item['_id']['day']),
            'total' => round((float)$item['total'], 2)
        ];
    }
    $stats['daily_trend'] = $dailyTrend;
    successResponse($stats);
}
/**
 * Handle expense receipt upload
 * @param array $file
 * @param string $expenseId
 * @return array
 */
function handleExpenseReceiptUpload($file, $expenseId) {
    $validation = validateFileUpload($file);
    if (!$validation['success']) {
        return $validation;
    }
    $uploadDir = UPLOAD_DIR . 'receipts/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $destination = $uploadDir . $validation['filename'];
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'File upload failed', 'url' => null];
    }
    $url = BASE_URL . 'uploads/receipts/' . $validation['filename'];
    return ['success' => true, 'error' => null, 'url' => $url];
}
/**
 * Create notification for approvers when expense is submitted
 * @param string $expenseId
 */
function createApprovalNotification($expenseId) {
    $collection = getCollection('notifications');
    if (!$collection) {
        return;
    }
    $currentUser = getCollection('users')->findOne(
        ['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())],
        ['projection' => ['first_name' => 1, 'last_name' => 1]]
    );
    $name = ($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '');
    $collection->insertOne([
        'user_id' => null, // broadcast to all
        'title' => 'New Expense Request',
        'message' => $name . ' submitted a new expense for approval.',
        'type' => 'expense',
        'resource_id' => $expenseId,
        'is_read' => false,
        'created_at' => phpDateToMongo()
    ]);
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getExpenses();
        break;
    case 'get':
        if ($method === 'GET') getExpense();
        break;
    case 'create':
        if ($method === 'POST') createExpense();
        break;
    case 'update':
        if ($method === 'POST' || $method === 'PUT') updateExpense();
        break;
    case 'delete':
        if ($method === 'POST' || $method === 'DELETE') deleteExpense();
        break;
    case 'update_status':
        if ($method === 'POST') updateExpenseStatus();
        break;
    case 'stats':
        if ($method === 'GET') getExpenseStats();
        break;
    default:
        errorResponse('Invalid action');
}
?>