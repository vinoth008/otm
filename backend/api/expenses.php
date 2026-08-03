<?php
declare(strict_types=1);
// Expenses API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createExpense(); break;
    case 'get_all': $method === 'GET' && getExpenses(); break;
    case 'get': $method === 'GET' && getExpense(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateExpense(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteExpense(); break;
    case 'summary': $method === 'GET' && getExpenseSummary(); break;
    case 'admin_all': $method === 'GET' && getAdminExpenses(); break;
    case 'admin_summary': $method === 'GET' && getAdminExpenseSummary(); break;
    default: errorResponse('Invalid action', 404);
}

function createExpense() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $category = sanitizeInput($data['category'] ?? '');
    $amount = $data['amount'] ?? 0;
    $description = sanitizeInput($data['description'] ?? '');
    $date = $data['date'] ?? date('Y-m-d');
    if (empty($category)) errorResponse('Category is required');
    if (!validateAmount($amount)) errorResponse('Amount must be greater than 0');
    if (!validateDate($date)) errorResponse('Invalid date format');
    $collection = getCollection('expenses');
    if (!$collection) errorResponse('Database connection error');
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'category' => $category,
        'amount' => (float)$amount,
        'description' => $description,
        'date' => phpDateToMongo($date),
        'payment_method' => sanitizeInput($data['payment_method'] ?? 'cash'),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create expense');
    $expId = (string)$result->getInsertedId();
    updateBudgetSpent($category, (float)$amount);
    logActivity('expense_created', getCurrentUserId(), ['expense_id' => $expId, 'amount' => $amount]);
    successResponse(['expense_id' => $expId], 'Expense created successfully');
}

function updateBudgetSpent($category, $amount) {
    $collection = getCollection('budgets');
    if (!$collection) return;
    $firstDay = date('Y-m-01');
    $lastDay = date('Y-m-t');
    $collection->updateOne(
        [
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'category' => $category,
            'is_active' => true,
            'period_start' => ['$lte' => phpDateToMongo($firstDay)],
            'period_end' => ['$gte' => phpDateToMongo($lastDay)]
        ],
        ['$inc' => ['current_spent' => (float)$amount]]
    );
}

function getExpenses() {
    requireActiveSession();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    if (!empty($_GET['category'])) $filter['category'] = sanitizeInput($_GET['category']);
    if (!empty($_GET['date_from']) && validateDate($_GET['date_from'])) $filter['date'] = ['$gte' => phpDateToMongo($_GET['date_from'])];
    if (!empty($_GET['date_to']) && validateDate($_GET['date_to'])) $filter['date'] = array_merge($filter['date'] ?? [], ['$lte' => phpDateToMongo($_GET['date_to'] . ' 23:59:59')]);
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [['description' => new MongoDB\BSON\Regex($search, 'i')], ['category' => new MongoDB\BSON\Regex($search, 'i')]];
    }
    $collection = getCollection('expenses');
    $total = $collection->countDocuments($filter);
    $expenses = $collection->find($filter, ['sort' => ['date' => -1], 'skip' => $skip, 'limit' => $limit])->toArray();
    $formatted = array_map(function($e) {
        return [
            '_id' => (string)$e['_id'],
            'category' => $e['category'],
            'amount' => $e['amount'],
            'description' => $e['description'] ?? '',
            'date' => mongoDateToPHP($e['date'])->format('Y-m-d'),
            'payment_method' => $e['payment_method'] ?? 'cash',
            'created_at' => mongoDateToPHP($e['created_at'])->format('Y-m-d H:i:s')
        ];
    }, $expenses);
    successResponse(['expenses' => $formatted, 'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]]);
}

function getExpense() {
    requireActiveSession();
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid expense ID');
    $collection = getCollection('expenses');
    $e = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$e) errorResponse('Expense not found');
    successResponse(['_id' => (string)$e['_id'], 'category' => $e['category'], 'amount' => $e['amount'], 'description' => $e['description'] ?? '', 'date' => mongoDateToPHP($e['date'])->format('Y-m-d'), 'payment_method' => $e['payment_method'] ?? 'cash']);
}

function updateExpense() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid expense ID');
    $collection = getCollection('expenses');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$existing) errorResponse('Expense not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['category'])) $updateData['category'] = sanitizeInput($data['category']);
    if (isset($data['amount'])) {
        if (!validateAmount($data['amount'])) errorResponse('Amount must be greater than 0');
        $updateData['amount'] = (float)$data['amount'];
    }
    if (isset($data['description'])) $updateData['description'] = sanitizeInput($data['description']);
    if (isset($data['date'])) {
        if (!validateDate($data['date'])) errorResponse('Invalid date format');
        $updateData['date'] = phpDateToMongo($data['date']);
    }
    if (isset($data['payment_method'])) $updateData['payment_method'] = sanitizeInput($data['payment_method']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('expense_updated', getCurrentUserId(), ['expense_id' => $id]);
    successResponse(['expense_id' => $id, 'updated' => true], 'Expense updated successfully');
}

function deleteExpense() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid expense ID');
    $collection = getCollection('expenses');
    $e = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$e) errorResponse('Expense not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]);
    logActivity('expense_deleted', getCurrentUserId(), ['expense_id' => $id]);
    successResponse(null, 'Expense deleted successfully');
}

if (!function_exists('calculateDateRange')) {
    function calculateDateRange($period) {
        switch ($period) {
            case 'today': return ['from' => date('Y-m-d'), 'to' => date('Y-m-d')];
            case 'week': return ['from' => date('Y-m-d', strtotime('monday this week')), 'to' => date('Y-m-d', strtotime('sunday this week'))];
            case 'year': return ['from' => date('Y-01-01'), 'to' => date('Y-12-31')];
            case 'last_month': return ['from' => date('Y-m-01', strtotime('-1 month')), 'to' => date('Y-m-t', strtotime('-1 month'))];
            case 'last_year': return ['from' => date('Y-01-01', strtotime('-1 year')), 'to' => date('Y-12-31', strtotime('-1 year'))];
            case 'custom': return ['from' => $_GET['date_from'] ?? date('Y-m-01'), 'to' => $_GET['date_to'] ?? date('Y-m-t')];
            default: return ['from' => date('Y-m-01'), 'to' => date('Y-m-t')];
        }
    }
}

function getExpenseSummary() {
    requireActiveSession();
    $period = $_GET['period'] ?? 'month';
    $dateRange = calculateDateRange($period);
    $collection = getCollection('expenses');
    $filter = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'date' => ['$gte' => phpDateToMongo($dateRange['from']), '$lte' => phpDateToMongo($dateRange['to'] . ' 23:59:59')],
        'deleted_at' => null
    ];
    $totalResult = $collection->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
    ])->toArray();
    $total = $totalResult[0]['total'] ?? 0;
    $count = $totalResult[0]['count'] ?? 0;
    $categoryResult = $collection->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount']]],
        ['$sort' => ['total' => -1]]
    ])->toArray();
    $categoryBreakdown = [];
    foreach ($categoryResult as $item) {
        $categoryBreakdown[] = ['category' => $item['_id'], 'total' => $item['total'], 'percentage' => $total > 0 ? round(($item['total'] / $total) * 100, 2) : 0];
    }
    successResponse(['period' => $period, 'total' => $total, 'count' => $count, 'category_breakdown' => $categoryBreakdown]);
}

function getAdminExpenses() {
    requireRole(['admin']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['deleted_at' => null];
    if (!empty($_GET['category'])) $filter['category'] = sanitizeInput($_GET['category']);
    if (!empty($_GET['date_from']) && validateDate($_GET['date_from'])) $filter['date'] = ['$gte' => phpDateToMongo($_GET['date_from'])];
    if (!empty($_GET['date_to']) && validateDate($_GET['date_to'])) $filter['date'] = array_merge($filter['date'] ?? [], ['$lte' => phpDateToMongo($_GET['date_to'] . ' 23:59:59')]);
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [['description' => new MongoDB\BSON\Regex($search, 'i')], ['category' => new MongoDB\BSON\Regex($search, 'i')]];
    }
    $collection = getCollection('expenses');
    $users = getCollection('users');
    $total = $collection->countDocuments($filter);
    $expenses = $collection->find($filter, ['sort' => ['date' => -1], 'skip' => $skip, 'limit' => $limit])->toArray();
    $formatted = array_map(function($e) use ($users) {
        $owner = !empty($e['user_id']) ? $users->findOne(['_id' => $e['user_id']]) : null;
        $name = $owner ? $owner['first_name'] . ' ' . ($owner['last_name'] ?? '') : 'Unknown';
        return [
            '_id' => (string)$e['_id'],
            'user_name' => trim($name),
            'category' => $e['category'],
            'amount' => $e['amount'],
            'description' => $e['description'] ?? '',
            'date' => isset($e['date']) ? mongoDateToPHP($e['date'])->format('Y-m-d') : '',
            'payment_method' => $e['payment_method'] ?? 'cash',
            'created_at' => mongoDateToPHP($e['created_at'])->format('Y-m-d H:i:s')
        ];
    }, $expenses);
    successResponse(['expenses' => $formatted, 'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]]);
}

function getAdminExpenseSummary() {
    requireRole(['admin']);
    $period = $_GET['period'] ?? 'month';
    $dateRange = calculateDateRange($period);
    $collection = getCollection('expenses');
    $filter = [
        'date' => ['$gte' => phpDateToMongo($dateRange['from']), '$lte' => phpDateToMongo($dateRange['to'] . ' 23:59:59')],
        'deleted_at' => null
    ];
    $totalResult = $collection->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
    ])->toArray();
    $total = $totalResult[0]['total'] ?? 0;
    $count = $totalResult[0]['count'] ?? 0;
    $categoryResult = $collection->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount']]],
        ['$sort' => ['total' => -1]]
    ])->toArray();
    $categoryBreakdown = [];
    foreach ($categoryResult as $item) {
        $categoryBreakdown[] = ['category' => $item['_id'], 'total' => $item['total'], 'percentage' => $total > 0 ? round(($item['total'] / $total) * 100, 2) : 0];
    }
    successResponse(['period' => $period, 'total' => $total, 'count' => $count, 'category_breakdown' => $categoryBreakdown]);
}