<?php
declare(strict_types=1);
// Transactions API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createTransaction(); break;
    case 'get_all': $method === 'GET' && getTransactions(); break;
    case 'get': $method === 'GET' && getTransaction(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateTransaction(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteTransaction(); break;
    case 'summary': $method === 'GET' && getTransactionsSummary(); break;
    case 'report': $method === 'GET' && getTransactionsForReport(); break;
    case 'admin_all': $method === 'GET' && getAllTransactionsAdmin(); break;
    default: errorResponse('Invalid action', 404);
}

function createTransaction() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $type = sanitizeInput($data['type'] ?? '');
    $category = sanitizeInput($data['category'] ?? '');
    $amount = $data['amount'] ?? 0;
    $description = sanitizeInput($data['description'] ?? '');
    $date = $data['date'] ?? date('Y-m-d');
    $paymentMethod = sanitizeInput($data['payment_method'] ?? 'cash');
    $validTypes = ['income', 'expense', 'transfer'];
    if (!in_array($type, $validTypes)) errorResponse('Invalid transaction type');
    if (empty($category)) errorResponse('Category is required');
    if (!validateAmount($amount)) errorResponse('Amount must be greater than 0');
    if (!validateDate($date)) errorResponse('Invalid date format');
    $collection = getCollection('transactions');
    if (!$collection) errorResponse('Database connection error');
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'type' => $type,
        'category' => $category,
        'amount' => (float)$amount,
        'currency' => 'INR',
        'description' => $description,
        'date' => phpDateToMongo($date),
        'payment_method' => $paymentMethod,
        'recipient_payer' => sanitizeInput($data['recipient_payer'] ?? ''),
        'notes' => sanitizeInput($data['notes'] ?? ''),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create transaction');
    $txId = (string)$result->getInsertedId();
    if ($type === 'expense') updateBudgetSpent($category, (float)$amount);
    logActivity('transaction_created', getCurrentUserId(), ['transaction_id' => $txId, 'type' => $type, 'amount' => $amount]);
    // Evaluate + unlock achievements (first/5 transactions, first income, savings).
    require_once __DIR__ . '/../services/AchievementService.php';
    AchievementService::checkAndUnlock(getCurrentUserId(), [
        'transaction_count' => (int)$collection->countDocuments([
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'deleted_at' => null
        ]),
        'has_income' => $type === 'income',
    ]);
    successResponse(['transaction_id' => $txId], 'Transaction created successfully');
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

function getTransactions() {
    requireActiveSession();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $role = getCurrentUserRole();
    if (in_array($role, ['admin', 'staff', 'receptionist'], true)) {
        $filter = ['deleted_at' => null];
        if (!empty($_GET['user_id']) && isValidObjectId($_GET['user_id'])) {
            $filter['user_id'] = new MongoDB\BSON\ObjectId($_GET['user_id']);
        }
    } else {
        $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    }
    if (!empty($_GET['type'])) $filter['type'] = sanitizeInput($_GET['type']);
    if (!empty($_GET['category'])) $filter['category'] = sanitizeInput($_GET['category']);
    if (!empty($_GET['date_from']) && validateDate($_GET['date_from'])) {
        $filter['date'] = ['$gte' => phpDateToMongo($_GET['date_from'])];
    }
    if (!empty($_GET['date_to']) && validateDate($_GET['date_to'])) {
        $filter['date'] = array_merge($filter['date'] ?? [], ['$lte' => phpDateToMongo($_GET['date_to'] . ' 23:59:59')]);
    }
    if (!empty($_GET['min_amount'])) $filter['amount'] = ['$gte' => (float)$_GET['min_amount']];
    if (!empty($_GET['max_amount'])) $filter['amount'] = array_merge($filter['amount'] ?? [], ['$lte' => (float)$_GET['max_amount']]);
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [
            ['description' => new MongoDB\BSON\Regex($search, 'i')],
            ['notes' => new MongoDB\BSON\Regex($search, 'i')],
            ['recipient_payer' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $sortOptions = [
        'date_desc' => ['date' => -1, 'created_at' => -1],
        'date_asc' => ['date' => 1, 'created_at' => 1],
        'amount_desc' => ['amount' => -1],
        'amount_asc' => ['amount' => 1],
        'category' => ['category' => 1]
    ];
    $sort = $sortOptions[$_GET['sort'] ?? 'date_desc'];
    $collection = getCollection('transactions');
    if (!$collection) errorResponse('Database connection error');
    $total = $collection->countDocuments($filter);
    $transactions = $collection->find($filter, ['sort' => $sort, 'skip' => $skip, 'limit' => $limit])->toArray();
    $formatted = array_map(function($t) {
        return [
            '_id' => (string)$t['_id'],
            'type' => $t['type'],
            'category' => $t['category'],
            'amount' => $t['amount'],
            'currency' => $t['currency'] ?? 'INR',
            'description' => $t['description'] ?? '',
            'date' => mongoDateToPHP($t['date'])->format('Y-m-d'),
            'payment_method' => $t['payment_method'] ?? 'cash',
            'recipient_payer' => $t['recipient_payer'] ?? null,
            'notes' => $t['notes'] ?? null,
            'created_at' => mongoDateToPHP($t['created_at'])->format('Y-m-d H:i:s')
        ];
    }, $transactions);
    successResponse([
        'transactions' => $formatted,
        'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]
    ]);
}

function getTransaction() {
    requireActiveSession();
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid transaction ID');
    $collection = getCollection('transactions');
    $t = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$t) errorResponse('Transaction not found');
    successResponse([
        '_id' => (string)$t['_id'],
        'type' => $t['type'],
        'category' => $t['category'],
        'amount' => $t['amount'],
        'currency' => $t['currency'] ?? 'INR',
        'description' => $t['description'] ?? '',
        'date' => mongoDateToPHP($t['date'])->format('Y-m-d'),
        'payment_method' => $t['payment_method'] ?? 'cash',
        'recipient_payer' => $t['recipient_payer'] ?? null,
        'notes' => $t['notes'] ?? null
    ]);
}

function updateTransaction() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid transaction ID');
    $collection = getCollection('transactions');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$existing) errorResponse('Transaction not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['type'])) {
        if (!in_array($data['type'], ['income', 'expense', 'transfer'])) errorResponse('Invalid transaction type');
        $updateData['type'] = $data['type'];
    }
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
    if (isset($data['recipient_payer'])) $updateData['recipient_payer'] = sanitizeInput($data['recipient_payer']);
    if (isset($data['notes'])) $updateData['notes'] = sanitizeInput($data['notes']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('transaction_updated', getCurrentUserId(), ['transaction_id' => $id]);
    successResponse(['transaction_id' => $id, 'updated' => true], 'Transaction updated successfully');
}

function deleteTransaction() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid transaction ID');
    $collection = getCollection('transactions');
    $t = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$t) errorResponse('Transaction not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]);
    logActivity('transaction_deleted', getCurrentUserId(), ['transaction_id' => $id]);
    successResponse(null, 'Transaction deleted successfully');
}

function getTransactionsSummary() {
    requireActiveSession();
    $period = $_GET['period'] ?? 'month';
    $dateRange = calculateDateRange($period);
    $collection = getCollection('transactions');
    $baseFilter = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'type' => ['$in' => ['income', 'expense']],
        'date' => ['$gte' => phpDateToMongo($dateRange['from']), '$lte' => phpDateToMongo($dateRange['to'] . ' 23:59:59')],
        'deleted_at' => null
    ];
    $incomeResult = $collection->aggregate([
        ['$match' => array_merge($baseFilter, ['type' => 'income'])],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
    ])->toArray();
    $totalIncome = $incomeResult[0]['total'] ?? 0;
    $incomeCount = $incomeResult[0]['count'] ?? 0;
    $expenseResult = $collection->aggregate([
        ['$match' => array_merge($baseFilter, ['type' => 'expense'])],
        ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]]
    ])->toArray();
    $totalExpense = $expenseResult[0]['total'] ?? 0;
    $expenseCount = $expenseResult[0]['count'] ?? 0;
    $categoryResult = $collection->aggregate([
        ['$match' => array_merge($baseFilter, ['type' => 'expense'])],
        ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]],
        ['$sort' => ['total' => -1]]
    ])->toArray();
    $categoryBreakdown = [];
    foreach ($categoryResult as $item) {
        $categoryBreakdown[] = [
            'category' => $item['_id'],
            'total' => $item['total'],
            'count' => $item['count'],
            'percentage' => $totalExpense > 0 ? round(($item['total'] / $totalExpense) * 100, 2) : 0
        ];
    }
    $dailyResult = $collection->aggregate([
        ['$match' => array_merge($baseFilter, ['type' => 'expense'])],
        ['$group' => ['_id' => ['year' => ['$year' => '$date'], 'month' => ['$month' => '$date'], 'day' => ['$dayOfMonth' => '$date']], 'total' => ['$sum' => '$amount']]],
        ['$sort' => ['_id' => 1]]
    ])->toArray();
    $dailyTrend = [];
    foreach ($dailyResult as $item) {
        $dailyTrend[] = ['date' => sprintf('%04d-%02d-%02d', $item['_id']['year'], $item['_id']['month'], $item['_id']['day']), 'total' => $item['total']];
    }
    $recent = $collection->find($baseFilter, ['sort' => ['date' => -1], 'limit' => 5])->toArray();
    $recentFormatted = array_map(function($t) {
        return ['_id' => (string)$t['_id'], 'type' => $t['type'], 'category' => $t['category'], 'amount' => $t['amount'], 'description' => $t['description'] ?? '', 'date' => mongoDateToPHP($t['date'])->format('Y-m-d')];
    }, $recent);
    successResponse([
        'period' => $period,
        'date_from' => $dateRange['from'],
        'date_to' => $dateRange['to'],
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'balance' => $totalIncome - $totalExpense,
        'income_count' => $incomeCount,
        'expense_count' => $expenseCount,
        'category_breakdown' => $categoryBreakdown,
        'daily_trend' => $dailyTrend,
        'recent_transactions' => $recentFormatted,
        'savings_rate' => $totalIncome > 0 ? round((($totalIncome - $totalExpense) / $totalIncome) * 100, 2) : 0
    ]);
}

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

function getTransactionsForReport() {
    requireActiveSession();
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-t');
    $type = $_GET['type'] ?? null;
    if (!validateDate($dateFrom) || !validateDate($dateTo)) errorResponse('Invalid date format');
    $filter = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'date' => ['$gte' => phpDateToMongo($dateFrom), '$lte' => phpDateToMongo($dateTo . ' 23:59:59')],
        'deleted_at' => null
    ];
    if ($type && in_array($type, ['income', 'expense', 'transfer'])) $filter['type'] = $type;
    $collection = getCollection('transactions');
    $transactions = $collection->find($filter, ['sort' => ['date' => -1]])->toArray();
    $formatted = array_map(function($t) {
        return ['_id' => (string)$t['_id'], 'type' => $t['type'], 'category' => $t['category'], 'amount' => $t['amount'], 'currency' => $t['currency'] ?? 'INR', 'description' => $t['description'] ?? '', 'date' => mongoDateToPHP($t['date'])->format('Y-m-d'), 'payment_method' => $t['payment_method'] ?? 'cash'];
    }, $transactions);
    successResponse(['date_from' => $dateFrom, 'date_to' => $dateTo, 'transactions' => $formatted, 'total_count' => count($formatted)]);
}

function getAllTransactionsAdmin() {
    requireRole(['admin', 'staff', 'receptionist']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['deleted_at' => null];
    if (!empty($_GET['type']) && in_array($_GET['type'], ['income', 'expense', 'transfer'])) $filter['type'] = sanitizeInput($_GET['type']);
    if (!empty($_GET['date_from']) && validateDate($_GET['date_from'])) {
        $filter['date'] = ['$gte' => phpDateToMongo($_GET['date_from'])];
    }
    if (!empty($_GET['date_to']) && validateDate($_GET['date_to'])) {
        $filter['date'] = array_merge($filter['date'] ?? [], ['$lte' => phpDateToMongo($_GET['date_to'] . ' 23:59:59')]);
    }
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [['description' => new MongoDB\BSON\Regex($search, 'i')], ['category' => new MongoDB\BSON\Regex($search, 'i')]];
    }
    $collection = getCollection('transactions');
    $users = getCollection('users');
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, ['sort' => ['date' => -1], 'skip' => $skip, 'limit' => $limit]);
    $list = [];
    foreach ($cursor as $t) {
        $userName = 'Unknown';
        if (isset($t['user_id'])) {
            $owner = $users->findOne(['_id' => $t['user_id']]);
            if ($owner) $userName = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
        }
        $list[] = [
            'transaction_id' => (string)$t['_id'],
            'user_id' => isset($t['user_id']) ? (string)$t['user_id'] : '',
            'user_name' => $userName,
            'type' => $t['type'] ?? 'expense',
            'category' => $t['category'] ?? 'Other',
            'amount' => round((float)($t['amount'] ?? 0), 2),
            'description' => $t['description'] ?? '',
            'date' => mongoDateToPHP($t['date'] ?? null)->format('Y-m-d'),
            'payment_method' => $t['payment_method'] ?? '',
            'status' => $t['status'] ?? 'success',
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['transactions' => $list, 'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]]);
}
