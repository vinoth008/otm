<?php
// backend/php/transaction_crud.php
/**
 * Transaction CRUD Operations for Smart Transaction Control
 * Handles all transaction-related operations: create, read, update, delete, filter
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
 * Create new transaction
 * POST: type, category, amount, description, date, payment_method, etc.
 */
function createTransaction() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    // Verify CSRF token
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    // Extract and validate inputs
    $type = sanitizeInput($data['type'] ?? '');
    $category = sanitizeInput($data['category'] ?? '');
    $amount = $data['amount'] ?? 0;
    $description = sanitizeInput($data['description'] ?? '');
    $date = $data['date'] ?? date('Y-m-d');
    $paymentMethod = sanitizeInput($data['payment_method'] ?? 'cash');
    $currency = sanitizeInput($data['currency'] ?? $_SESSION['user_currency'] ?? 'INR');
    $recipientPayer = sanitizeInput($data['recipient_payer'] ?? '');
    $tags = $data['tags'] ?? [];
    $notes = sanitizeInput($data['notes'] ?? '');
    $isRecurring = $data['is_recurring'] ?? false;
    $recurringFrequency = sanitizeInput($data['recurring_frequency'] ?? '');
    $isInstallment = $data['is_installment'] ?? false;
    $installmentTotal = $data['installment_total'] ?? 1;
    $isSplit = $data['is_split'] ?? false;
    $splitWith = $data['split_with'] ?? [];
    $splitAmount = $data['split_amount'] ?? 0;
    // Validation
    $validTypes = ['income', 'expense', 'transfer', 'loan', 'borrow', 'lend', 'investment'];
    if (!in_array($type, $validTypes)) {
        errorResponse('Invalid transaction type');
    }
    if (empty($category)) {
        errorResponse('Category is required');
    }
    if (!validateAmount($amount) || $amount <= 0) {
        errorResponse('Amount must be greater than 0');
    }
    if (!validateDate($date)) {
        errorResponse('Invalid date format');
    }
    $validPaymentMethods = ['cash', 'card', 'upi', 'bank_transfer', 'wallet', 'other'];
    if (!in_array($paymentMethod, $validPaymentMethods)) {
        errorResponse('Invalid payment method');
    }
    // Prepare transaction document
    $transactionDocument = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'type' => $type,
        'category' => $category,
        'subcategory' => $data['subcategory'] ?? null,
        'amount' => (float)$amount,
        'currency' => $currency,
        'description' => $description,
        'date' => new MongoDB\BSON\UTCDateTime(strtotime($date) * 1000),
        'payment_method' => $paymentMethod,
        'recipient_payer' => $recipientPayer,
        'tags' => is_array($tags) ? $tags : [],
        'receipt_url' => null,
        'is_recurring' => (bool)$isRecurring,
        'recurring_frequency' => $isRecurring ? $recurringFrequency : null,
        'next_recurring_date' => $isRecurring ? new MongoDB\BSON\UTCDateTime(strtotime($data['next_recurring_date'] ?? date('Y-m-d')) * 1000) : null,
        'installment_total' => $isInstallment ? (int)$installmentTotal : null,
        'installment_paid' => $isInstallment ? 1 : null,
        'is_split' => (bool)$isSplit,
        'split_with' => is_array($splitWith) ? $splitWith : [],
        'split_amount' => $isSplit ? (float)$splitAmount : null,
        'notes' => $notes,
        'is_template' => false,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    // Insert transaction
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->insertOne($transactionDocument);
    if (!$result->getInsertedId()) {
        errorResponse('Failed to create transaction');
    }
    $transactionId = (string)$result->getInsertedId();
    // If receipt was uploaded, handle it
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $receiptResult = handleReceiptUpload($_FILES['receipt'], $transactionId);
        if ($receiptResult['success']) {
            $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
                ['$set' => ['receipt_url' => $receiptResult['url']]]
            );
        }
    }
    // Update budget spent if expense
    if ($type === 'expense') {
        updateBudgetSpent($category, $amount);
    }
    // Log activity
    logActivity('transaction_created', getCurrentUserId(), [
        'transaction_id' => $transactionId,
        'type' => $type,
        'amount' => $amount,
        'category' => $category
    ]);
    successResponse([
        'transaction_id' => $transactionId,
        'transaction' => $transactionDocument
    ], 'Transaction created successfully');
}
/**
 * Handle receipt upload for transaction
 * @param array $file
 * @param string $transactionId
 * @return array
 */
function handleReceiptUpload($file, $transactionId) {
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
 * Update budget spent amount
 * @param string $category
 * @param float $amount
 */
function updateBudgetSpent($category, $amount) {
    $collection = getCollection('budgets');
    if (!$collection) {
        return;
    }
    $firstDayOfMonth = date('Y-m-01');
    $lastDayOfMonth = date('Y-m-t');
    $collection->updateOne(
        [
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'category' => $category,
            'is_active' => true,
            'period_start' => ['$lte' => new MongoDB\BSON\UTCDateTime(strtotime($firstDayOfMonth . ' 00:00:00') * 1000)],
            'period_end' => ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($lastDayOfMonth . ' 23:59:59') * 1000)],
        ],
        ['$inc' => ['current_spent' => (float)$amount]]
    );
}
/**
 * Get all transactions with filters
 * GET: page, limit, type, category, date_from, date_to, min_amount, max_amount, search, sort, period, currency
 */
function getTransactions() {
    requireActiveSession();
    // Pagination
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    // Build filter - role based: admin/auditor see all, manager sees all, user sees own
    $role = getCurrentUserRole();
    if (in_array($role, ['admin', 'auditor', 'manager', 'staff'], true)) {
        $filter = ['deleted_at' => null];
        // Optional user filter for admin/auditor/manager views
        if (!empty($_GET['user_id']) && isValidObjectId($_GET['user_id'])) {
            $filter['user_id'] = new MongoDB\BSON\ObjectId($_GET['user_id']);
        }
    } else {
        $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    }
    // Type filter
    if (!empty($_GET['type'])) {
        $filter['type'] = sanitizeInput($_GET['type']);
    }
    // Category filter
    if (!empty($_GET['category'])) {
        $filter['category'] = sanitizeInput($_GET['category']);
    }
    // Date range filter
    if (!empty($_GET['date_from'])) {
        $dateFrom = sanitizeInput($_GET['date_from']);
        if (validateDate($dateFrom)) {
            $filter['date'] = ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($dateFrom . ' 00:00:00') * 1000)];
        }
    }
    if (!empty($_GET['date_to'])) {
        $dateTo = sanitizeInput($_GET['date_to']);
        if (validateDate($dateTo)) {
            $filter['date'] = array_merge(
                $filter['date'] ?? [],
                ['$lte' => new MongoDB\BSON\UTCDateTime(strtotime($dateTo . ' 23:59:59') * 1000)]
            );
        }
    }
    // Amount filter
    if (!empty($_GET['min_amount'])) {
        $filter['amount'] = ['$gte' => (float)$_GET['min_amount']];
    }
    if (!empty($_GET['max_amount'])) {
        $filter['amount'] = array_merge(
            $filter['amount'] ?? [],
            ['$lte' => (float)$_GET['max_amount']]
        );
    }
    // Search filter (description, notes, recipient)
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [
            ['description' => new MongoDB\BSON\Regex($search, 'i')],
            ['notes' => new MongoDB\BSON\Regex($search, 'i')],
            ['recipient_payer' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    // Exclude deleted transactions
    $filter['deleted_at'] = null;
    // Sort options
    $sortOptions = [
        'date_desc' => ['date' => -1, 'created_at' => -1],
        'date_asc' => ['date' => 1, 'created_at' => 1],
        'amount_desc' => ['amount' => -1],
        'amount_asc' => ['amount' => 1],
        'category' => ['category' => 1]
    ];
    $sort = $sortOptions[$_GET['sort'] ?? 'date_desc'];
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    // Get total count
    $total = $collection->countDocuments($filter);
    // Get transactions
    $transactions = $collection->find(
        $filter,
        [
            'sort' => $sort,
            'skip' => $skip,
            'limit' => $limit
        ]
    )->toArray();
    // Convert MongoDB objects to arrays and format dates
    $formattedTransactions = array_map(function($t) {
        return [
            '_id' => (string)$t['_id'],
            'type' => $t['type'],
            'category' => $t['category'],
            'subcategory' => $t['subcategory'] ?? null,
            'amount' => $t['amount'],
            'currency' => $t['currency'],
            'description' => $t['description'],
            'date' => mongoDateToPHP($t['date'])->format('Y-m-d'),
            'payment_method' => $t['payment_method'],
            'recipient_payer' => $t['recipient_payer'] ?? null,
            'tags' => $t['tags'] ?? [],
            'receipt_url' => $t['receipt_url'] ?? null,
            'is_recurring' => $t['is_recurring'] ?? false,
            'recurring_frequency' => $t['recurring_frequency'] ?? null,
            'is_split' => $t['is_split'] ?? false,
            'split_with' => $t['split_with'] ?? [],
            'notes' => $t['notes'] ?? null,
            'created_at' => mongoDateToPHP($t['created_at'])->format('Y-m-d H:i:s'),
            'updated_at' => mongoDateToPHP($t['updated_at'])->format('Y-m-d H:i:s')
        ];
    }, $transactions);
    successResponse([
        'transactions' => $formattedTransactions,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ]);
}
/**
 * Get single transaction by ID
 * GET: id
 */
function getTransaction() {
    requireActiveSession();
    $transactionId = $_GET['id'] ?? '';
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    $collection = getCollection('transactions');
    $transaction = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transactionId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$transaction) {
        errorResponse('Transaction not found');
    }
    $formattedTransaction = [
        '_id' => (string)$transaction['_id'],
        'type' => $transaction['type'],
        'category' => $transaction['category'],
        'subcategory' => $transaction['subcategory'] ?? null,
        'amount' => $transaction['amount'],
        'currency' => $transaction['currency'],
        'description' => $transaction['description'],
        'date' => mongoDateToPHP($transaction['date'])->format('Y-m-d'),
        'payment_method' => $transaction['payment_method'],
        'recipient_payer' => $transaction['recipient_payer'] ?? null,
        'tags' => $transaction['tags'] ?? [],
        'receipt_url' => $transaction['receipt_url'] ?? null,
        'is_recurring' => $transaction['is_recurring'] ?? false,
        'recurring_frequency' => $transaction['recurring_frequency'] ?? null,
        'next_recurring_date' => isset($transaction['next_recurring_date']) ?
            mongoDateToPHP($transaction['next_recurring_date'])->format('Y-m-d') : null,
        'installment_total' => $transaction['installment_total'] ?? null,
        'installment_paid' => $transaction['installment_paid'] ?? null,
        'is_split' => $transaction['is_split'] ?? false,
        'split_with' => $transaction['split_with'] ?? [],
        'split_amount' => $transaction['split_amount'] ?? null,
        'notes' => $transaction['notes'] ?? null,
        'is_template' => $transaction['is_template'] ?? false,
        'created_at' => mongoDateToPHP($transaction['created_at'])->format('Y-m-d H:i:s'),
        'updated_at' => mongoDateToPHP($transaction['updated_at'])->format('Y-m-d H:i:s')
    ];
    successResponse($formattedTransaction);
}
/**
 * Update transaction
 * PUT/POST: id, type, category, amount, description, date, etc.
 */
function updateTransaction() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transactionId = $data['id'] ?? '';
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    // Check if transaction exists and belongs to user
    $collection = getCollection('transactions');
    $existingTransaction = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transactionId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$existingTransaction) {
        errorResponse('Transaction not found');
    }
    // Build update data
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['type'])) {
        $validTypes = ['income', 'expense', 'transfer', 'loan', 'borrow', 'lend', 'investment'];
        if (!in_array($data['type'], $validTypes)) {
            errorResponse('Invalid transaction type');
        }
        $updateData['type'] = $data['type'];
    }
    if (isset($data['category'])) {
        $updateData['category'] = sanitizeInput($data['category']);
    }
    if (isset($data['subcategory'])) {
        $updateData['subcategory'] = sanitizeInput($data['subcategory']);
    }
    if (isset($data['amount'])) {
        if (!validateAmount($data['amount']) || $data['amount'] <= 0) {
            errorResponse('Amount must be greater than 0');
        }
        $updateData['amount'] = (float)$data['amount'];
    }
    if (isset($data['description'])) {
        $updateData['description'] = sanitizeInput($data['description']);
    }
    if (isset($data['date'])) {
        if (!validateDate($data['date'])) {
            errorResponse('Invalid date format');
        }
        $updateData['date'] = new MongoDB\BSON\UTCDateTime(strtotime($data['date']) * 1000);
    }
    if (isset($data['payment_method'])) {
        $validPaymentMethods = ['cash', 'card', 'upi', 'bank_transfer', 'wallet', 'other'];
        if (!in_array($data['payment_method'], $validPaymentMethods)) {
            errorResponse('Invalid payment method');
        }
        $updateData['payment_method'] = $data['payment_method'];
    }
    if (isset($data['recipient_payer'])) {
        $updateData['recipient_payer'] = sanitizeInput($data['recipient_payer']);
    }
    if (isset($data['tags'])) {
        $updateData['tags'] = is_array($data['tags']) ? $data['tags'] : [];
    }
    if (isset($data['notes'])) {
        $updateData['notes'] = sanitizeInput($data['notes']);
    }
    if (isset($data['is_recurring'])) {
        $updateData['is_recurring'] = (bool)$data['is_recurring'];
        if ($updateData['is_recurring'] && isset($data['recurring_frequency'])) {
            $updateData['recurring_frequency'] = sanitizeInput($data['recurring_frequency']);
        }
    }
    // Handle receipt upload if new file provided
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        // Delete old receipt if exists
        if ($existingTransaction['receipt_url']) {
            $oldReceiptPath = str_replace(BASE_URL, UPLOAD_DIR, $existingTransaction['receipt_url']);
            if (file_exists($oldReceiptPath)) {
                unlink($oldReceiptPath);
            }
        }
        $receiptResult = handleReceiptUpload($_FILES['receipt'], $transactionId);
        if ($receiptResult['success']) {
            $updateData['receipt_url'] = $receiptResult['url'];
        }
    }
    // Update transaction
    $result = $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
        ['$set' => $updateData]
    );
    if ($result->getModifiedCount() === 0 && empty($updateData)) {
        errorResponse('No changes made');
    }
    // Log activity
    logActivity('transaction_updated', getCurrentUserId(), [
        'transaction_id' => $transactionId,
        'changes' => array_keys($updateData)
    ]);
    successResponse([
        'transaction_id' => $transactionId,
        'updated' => true
    ], 'Transaction updated successfully');
}
/**
 * Delete transaction (soft delete)
 * DELETE: id
 */
function deleteTransaction() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transactionId = $data['id'] ?? '';
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    $collection = getCollection('transactions');
    $transaction = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transactionId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$transaction) {
        errorResponse('Transaction not found');
    }
    // Soft delete
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
        [
            '$set' => [
                'deleted_at' => phpDateToMongo(),
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Delete receipt file if exists
    if ($transaction['receipt_url']) {
        $receiptPath = str_replace(BASE_URL, UPLOAD_DIR, $transaction['receipt_url']);
        if (file_exists($receiptPath)) {
            unlink($receiptPath);
        }
    }
    // Log activity
    logActivity('transaction_deleted', getCurrentUserId(), [
        'transaction_id' => $transactionId,
        'type' => $transaction['type'],
        'amount' => $transaction['amount']
    ]);
    successResponse(null, 'Transaction deleted successfully');
}
/**
 * Get transactions summary for dashboard
 * GET: period (today, week, month, year, custom), date_from, date_to
 */
function getTransactionsSummary() {
    requireActiveSession();
    $period = $_GET['period'] ?? 'month';
    $userId = getCurrentUserId();
    // Calculate date range
    $dateRange = calculateDateRange($period);
    $dateFrom = $dateRange['from'];
    $dateTo = $dateRange['to'];
    $collection = getCollection('transactions');
    // Base filter
    $baseFilter = [
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'type' => ['$in' => ['income', 'expense']],
        'date' => [
            '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($dateFrom) * 1000),
            '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($dateTo . ' 23:59:59') * 1000),
        ],
        'deleted_at' => null
    ];
    // Total income
    $incomePipeline = [
        ['$match' => array_merge($baseFilter, ['type' => 'income'])],
        ['$group' => [
            '_id' => null,
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]]
    ];
    $incomeResult = $collection->aggregate($incomePipeline)->toArray();
    $totalIncome = $incomeResult[0]['total'] ?? 0;
    $incomeCount = $incomeResult[0]['count'] ?? 0;
    // Total expense
    $expensePipeline = [
        ['$match' => array_merge($baseFilter, ['type' => 'expense'])],
        ['$group' => [
            '_id' => null,
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]]
    ];
    $expenseResult = $collection->aggregate($expensePipeline)->toArray();
    $totalExpense = $expenseResult[0]['total'] ?? 0;
    $expenseCount = $expenseResult[0]['count'] ?? 0;
    // Category breakdown
    $categoryPipeline = [
        ['$match' => array_merge($baseFilter, ['type' => 'expense'])],
        ['$group' => [
            '_id' => '$category',
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['total' => -1]]
    ];
    $categoryResult = $collection->aggregate($categoryPipeline)->toArray();
    $categoryBreakdown = [];
    foreach ($categoryResult as $item) {
        $categoryBreakdown[] = [
            'category' => $item['_id'],
            'total' => $item['total'],
            'count' => $item['count'],
            'percentage' => $totalExpense > 0 ? round(($item['total'] / $totalExpense) * 100, 2) : 0,
        ];
    }
    // Daily trend
    $dailyPipeline = [
        ['$match' => array_merge($baseFilter, ['type' => 'expense'])],
        ['$group' => [
            '_id' => [
                'year' => ['$year' => '$date'],
                'month' => ['$month' => '$date'],
                'day' => ['$dayOfMonth' => '$date'],
            ],
            'total' => ['$sum' => '$amount']
        ]],
        ['$sort' => ['_id' => 1]]
    ];
    $dailyResult = $collection->aggregate($dailyPipeline)->toArray();
    $dailyTrend = [];
    foreach ($dailyResult as $item) {
        $dailyTrend[] = [
            'date' => sprintf('%04d-%02d-%02d', $item['_id']['year'], $item['_id']['month'], $item['_id']['day']),
            'total' => $item['total']
        ];
    }
    // Recent transactions
    $recentTransactions = $collection->find(
        array_merge($baseFilter),
        [
            'sort' => ['date' => -1, 'created_at' => -1],
            'limit' => 5,
            'projection' => [
                '_id' => 1,
                'type' => 1,
                'category' => 1,
                'amount' => 1,
                'description' => 1,
                'date' => 1,
                'payment_method' => 1
            ]
        ]
    )->toArray();
    $formattedRecent = array_map(function($t) {
        return [
            '_id' => (string)$t['_id'],
            'type' => $t['type'],
            'category' => $t['category'],
            'amount' => $t['amount'],
            'description' => $t['description'],
            'date' => mongoDateToPHP($t['date'])->format('Y-m-d')
        ];
    }, $recentTransactions);
    successResponse([
        'period' => $period,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'balance' => $totalIncome - $totalExpense,
        'income_count' => $incomeCount,
        'expense_count' => $expenseCount,
        'category_breakdown' => $categoryBreakdown,
        'daily_trend' => $dailyTrend,
        'recent_transactions' => $formattedRecent,
        'savings_rate' => $totalIncome > 0 ? round((($totalIncome - $totalExpense) / $totalIncome) * 100, 2) : 0,
    ]);
}
/**
 * Calculate date range based on period
 * @param string $period
 * @param string|null $customFrom
 * @param string|null $customTo
 * @return array ['from' => string, 'to' => string]
 */
function calculateDateRange($period, $customFrom = null, $customTo = null) {
    switch ($period) {
        case 'today':
            return [
                'from' => date('Y-m-d'),
                'to' => date('Y-m-d')
            ];
        case 'yesterday':
            return [
                'from' => date('Y-m-d', strtotime('-1 day')),
                'to' => date('Y-m-d', strtotime('-1 day'))
            ];
        case 'week':
            return [
                'from' => date('Y-m-d', strtotime('monday this week')),
                'to' => date('Y-m-d', strtotime('sunday this week'))
            ];
        case 'month':
            return [
                'from' => date('Y-m-01'),
                'to' => date('Y-m-t')
            ];
        case 'last_month':
            return [
                'from' => date('Y-m-01', strtotime('-1 month')),
                'to' => date('Y-m-t', strtotime('-1 month'))
            ];
        case 'year':
            return [
                'from' => date('Y-01-01'),
                'to' => date('Y-12-31')
            ];
        case 'last_year':
            return [
                'from' => date('Y-01-01', strtotime('-1 year')),
                'to' => date('Y-12-31', strtotime('-1 year'))
            ];
        case 'custom':
            return [
                'from' => $customFrom ?? date('Y-m-01'),
                'to' => $customTo ?? date('Y-m-t')
            ];
        default:
            return [
                'from' => date('Y-m-01'),
                'to' => date('Y-m-t')
            ];
    }
}
/**
 * Get recurring transactions
 */
function getRecurringTransactions() {
    requireActiveSession();
    $collection = getCollection('transactions');
    $transactions = $collection->find([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'is_recurring' => true,
        'deleted_at' => null
    ], [
        'sort' => ['next_recurring_date' => 1]
    ])->toArray();
    $formattedTransactions = array_map(function($t) {
        return [
            '_id' => (string)$t['_id'],
            'type' => $t['type'],
            'category' => $t['category'],
            'amount' => $t['amount'],
            'description' => $t['description'],
            'recurring_frequency' => $t['recurring_frequency'],
            'next_recurring_date' => isset($t['next_recurring_date']) ?
                mongoDateToPHP($t['next_recurring_date'])->format('Y-m-d') : null
        ];
    }, $transactions);
    successResponse(['recurring_transactions' => $formattedTransactions]);
}
/**
 * Create transaction template
 * POST: transaction data
 */
function createTemplate() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $templateName = sanitizeInput($data['template_name'] ?? '');
    if (empty($templateName)) {
        errorResponse('Template name is required');
    }
    // Extract transaction data (excluding ID and user-specific fields)
    $templateData = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'type' => $data['type'] ?? 'expense',
        'category' => $data['category'] ?? '',
        'subcategory' => $data['subcategory'] ?? null,
        'amount' => (float)($data['amount'] ?? 0),
        'payment_method' => $data['payment_method'] ?? 'cash',
        'description' => sanitizeInput($data['description'] ?? ''),
        'tags' => is_array($data['tags']) ? $data['tags'] : [],
        'is_template' => true,
        'template_name' => $templateName,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo()
    ];
    $collection = getCollection('transactions');
    $result = $collection->insertOne($templateData);
    if (!$result->getInsertedId()) {
        errorResponse('Failed to create template');
    }
    successResponse([
        'template_id' => (string)$result->getInsertedId(),
        'template_name' => $templateName
    ], 'Template created successfully');
}
/**
 * Get transaction templates
 */
function getTemplates() {
    requireActiveSession();
    $collection = getCollection('transactions');
    $templates = $collection->find([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'is_template' => true,
        'deleted_at' => null
    ], [
        'sort' => ['template_name' => 1]
    ])->toArray();
    $formattedTemplates = array_map(function($t) {
        return [
            '_id' => (string)$t['_id'],
            'template_name' => $t['template_name'],
            'type' => $t['type'],
            'category' => $t['category'],
            'amount' => $t['amount'],
            'payment_method' => $t['payment_method'],
            'description' => $t['description'],
            'tags' => $t['tags'] ?? []
        ];
    }, $templates);
    successResponse(['templates' => $formattedTemplates]);
}
/**
 * Get transaction by date range for reports
 * GET: date_from, date_to, type
 */
function getTransactionsForReport() {
    requireActiveSession();
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-t');
    $type = $_GET['type'] ?? null;
    if (!validateDate($dateFrom) || !validateDate($dateTo)) {
        errorResponse('Invalid date format');
    }
    $filter = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'date' => [
            '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($dateFrom) * 1000),
            '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($dateTo . ' 23:59:59') * 1000),
        ],
        'deleted_at' => null
    ];
    if ($type && in_array($type, ['income', 'expense', 'transfer', 'loan', 'borrow', 'lend', 'investment'])) {
        $filter['type'] = $type;
    }
    $collection = getCollection('transactions');
    $transactions = $collection->find(
        $filter,
        [
            'sort' => ['date' => -1, 'created_at' => -1]
        ]
    )->toArray();
    $formattedTransactions = array_map(function($t) {
        return [
            '_id' => (string)$t['_id'],
            'type' => $t['type'],
            'category' => $t['category'],
            'amount' => $t['amount'],
            'currency' => $t['currency'],
            'description' => $t['description'],
            'date' => mongoDateToPHP($t['date'])->format('Y-m-d'),
            'payment_method' => $t['payment_method'],
            'recipient_payer' => $t['recipient_payer'] ?? null,
            'notes' => $t['notes'] ?? null
        ];
    }, $transactions);
    successResponse([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'transactions' => $formattedTransactions,
        'total_count' => count($formattedTransactions)
    ]);
}
// Route handling
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create':
        if ($method === 'POST') createTransaction();
        break;
    case 'get_all':
        if ($method === 'GET') getTransactions();
        break;
    case 'get':
        if ($method === 'GET') getTransaction();
        break;
    case 'update':
        if ($method === 'POST' || $method === 'PUT') updateTransaction();
        break;
    case 'delete':
        if ($method === 'POST' || $method === 'DELETE') deleteTransaction();
        break;
    case 'summary':
        if ($method === 'GET') getTransactionsSummary();
        break;
    case 'recurring':
        if ($method === 'GET') getRecurringTransactions();
        break;
    case 'create_template':
        if ($method === 'POST') createTemplate();
        break;
    case 'get_templates':
        if ($method === 'GET') getTemplates();
        break;
    case 'report':
        if ($method === 'GET') getTransactionsForReport();
        break;
    case 'admin_all':
        if ($method === 'GET') getAllTransactionsAdmin();
        break;
    default:
        errorResponse('Invalid action');
}

/**
 * Admin: get all transactions across users
 * GET: page, limit, type, search
 */
function getAllTransactionsAdmin() {
    requireRole(['admin']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['deleted_at' => null];
    if (!empty($_GET['type']) && in_array($_GET['type'], ['income', 'expense', 'transfer'], true)) {
        $filter['type'] = sanitizeInput($_GET['type']);
    }
    $search = $_GET['search'] ?? '';
    if ($search !== '') {
        $filter['$or'] = [
            ['description' => new MongoDB\BSON\Regex($search, 'i')],
            ['category' => new MongoDB\BSON\Regex($search, 'i')],
            ['user_id' => new MongoDB\BSON\ObjectId($search)]
        ];
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find(
        $filter,
        ['sort' => ['date' => -1, 'created_at' => -1], 'skip' => $skip, 'limit' => $limit]
    );
    $list = [];
    foreach ($cursor as $t) {
        $list[] = [
            'transaction_id' => (string)$t['_id'],
            'user_id' => isset($t['user_id']) ? (string)$t['user_id'] : '',
            'type' => $t['type'] ?? 'expense',
            'category' => $t['category'] ?? 'Other',
            'amount' => round((float)($t['amount'] ?? 0), 2),
            'description' => $t['description'] ?? '',
            'date' => mongoDateToPHP($t['date'] ?? null)->format('Y-m-d'),
            'payment_method' => $t['payment_method'] ?? '',
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse([
        'transactions' => $list,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ]);
}
?>
