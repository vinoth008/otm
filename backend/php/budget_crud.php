<?php
// backend/php/budget_crud.php
/**
 * Budget Management for Smart Transaction Control
 * Handles budget creation, tracking, warnings, and suggestions
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
 * Read request input supporting both JSON and form-encoded bodies,
 * and both the API field names (monthly_limit/warning_threshold) and
 * the frontend form names (limit/alert_threshold).
 */
function getBudgetInput() {
    $data = getJSONRequest();
    if (!$data || !is_array($data)) {
        $data = $_POST;
    }
    if (!isset($data['monthly_limit']) && isset($data['limit'])) {
        $data['monthly_limit'] = $data['limit'];
    }
    if (!isset($data['warning_threshold']) && isset($data['alert_threshold'])) {
        $data['warning_threshold'] = $data['alert_threshold'];
    }
    return $data;
}
/**
 * Create or update budget for a category
 * POST: category, monthly_limit, warning_threshold
 */
function createOrUpdateBudget() {
    requireActiveSession();
    $data = getBudgetInput();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $category = sanitizeInput($data['category'] ?? '');
    $monthlyLimit = $data['monthly_limit'] ?? 0;
    $warningThreshold = $data['warning_threshold'] ?? 80;
    if (empty($category)) {
        errorResponse('Category is required');
    }
    if (!validateAmount($monthlyLimit) || $monthlyLimit <= 0) {
        errorResponse('Monthly limit must be greater than 0');
    }
    if ($warningThreshold < 1 || $warningThreshold > 100) {
        errorResponse('Warning threshold must be between 1 and 100');
    }
    $userId = getCurrentUserId();
    $firstDayOfMonth = date('Y-m-01');
    $lastDayOfMonth = date('Y-m-t');
    $collection = getCollection('budgets');
    // Check if budget exists for this category and month
    $existingBudget = $collection->findOne([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'category' => $category,
        'is_active' => true,
        'period_start' => ['$lte' => new MongoDB\BSON\UTCDateTime(strtotime($firstDayOfMonth . ' 00:00:00') * 1000)],
        'period_end' => ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($lastDayOfMonth . ' 23:59:59') * 1000)],
    ]);
    if ($existingBudget) {
        // Update existing budget
        $collection->updateOne(
            ['_id' => $existingBudget['_id']],
            [
                '$set' => [
                    'monthly_limit' => (float)$monthlyLimit,
                    'warning_threshold' => (int)$warningThreshold,
                    'updated_at' => phpDateToMongo()
                ]
            ]
        );
        $budgetId = (string)$existingBudget['_id'];
        $message = 'Budget updated successfully';
    } else {
        // Create new budget
        $budgetDocument = [
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'category' => $category,
            'monthly_limit' => (float)$monthlyLimit,
            'current_spent' => 0,
            'period_start' => new MongoDB\BSON\UTCDateTime(strtotime($firstDayOfMonth . ' 00:00:00') * 1000),
            'period_end' => new MongoDB\BSON\UTCDateTime(strtotime($lastDayOfMonth) * 1000),
            'warning_threshold' => (int)$warningThreshold,
            'is_active' => true,
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ];
        $result = $collection->insertOne($budgetDocument);
        $budgetId = (string)$result->getInsertedId();
        $message = 'Budget created successfully';
    }
    // Log activity
    logActivity('budget_created_updated', $userId, [
        'category' => $category,
        'monthly_limit' => $monthlyLimit
    ]);
    successResponse([
        'budget_id' => $budgetId,
        'category' => $category,
        'monthly_limit' => $monthlyLimit
    ], $message);
}
/**
 * Get all active budgets for current month
 */
function getBudgets() {
    requireActiveSession();
    $userId = getCurrentUserId();
    $firstDayOfMonth = date('Y-m-01');
    $lastDayOfMonth = date('Y-m-t');
    $collection = getCollection('budgets');
    $budgets = $collection->find([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'is_active' => true,
        'period_start' => ['$lte' => new MongoDB\BSON\UTCDateTime(strtotime($firstDayOfMonth . ' 00:00:00') * 1000)],
        'period_end' => ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($lastDayOfMonth . ' 23:59:59') * 1000)],
    ])->toArray();
    $formattedBudgets = array_map(function($b) {
        $percentage = $b['monthly_limit'] > 0 ?
            round(($b['current_spent'] / $b['monthly_limit']) * 100, 2) : 0;
        return [
            '_id' => (string)$b['_id'],
            'category' => $b['category'],
            'monthly_limit' => $b['monthly_limit'],
            'current_spent' => $b['current_spent'],
            'remaining' => $b['monthly_limit'] - $b['current_spent'],
            'percentage_used' => $percentage,
            'warning_threshold' => $b['warning_threshold'],
            'is_over_budget' => $b['current_spent'] > $b['monthly_limit'],
            'is_warning' => $percentage >= $b['warning_threshold'],
            'period_start' => mongoDateToPHP($b['period_start'])->format('Y-m-d'),
            'period_end' => mongoDateToPHP($b['period_end'])->format('Y-m-d')
        ];
    }, $budgets);
    successResponse(['budgets' => $formattedBudgets]);
}
/**
 * Get budget warnings (categories exceeding threshold)
 */
function getBudgetWarnings() {
    requireActiveSession();
    $userId = getCurrentUserId();
    $firstDayOfMonth = date('Y-m-01');
    $lastDayOfMonth = date('Y-m-t');
    $collection = getCollection('budgets');
    // Get budgets that are over warning threshold or over limit
    $budgets = $collection->find([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'is_active' => true,
        'period_start' => ['$lte' => new MongoDB\BSON\UTCDateTime(strtotime($firstDayOfMonth . ' 00:00:00') * 1000)],
        'period_end' => ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($lastDayOfMonth . ' 23:59:59') * 1000)],
    ])->toArray();
    $warnings = [];
    foreach ($budgets as $budget) {
        $percentage = $budget['monthly_limit'] > 0 ?
            ($budget['current_spent'] / $budget['monthly_limit']) * 100 : 0;
        if ($percentage >= $budget['warning_threshold'] || $budget['current_spent'] > $budget['monthly_limit']) {
            $warnings[] = [
                '_id' => (string)$budget['_id'],
                'category' => $budget['category'],
                'monthly_limit' => $budget['monthly_limit'],
                'current_spent' => $budget['current_spent'],
                'percentage_used' => round($percentage, 2),
                'warning_threshold' => $budget['warning_threshold'],
                'is_over_budget' => $budget['current_spent'] > $budget['monthly_limit'],
                'message' => $budget['current_spent'] > $budget['monthly_limit'] ?
                    'You have exceeded your budget for ' . $budget['category'] :
                    'You have used ' . round($percentage, 2) . '% of your ' . $budget['category'] . ' budget.'
            ];
        }
    }
    successResponse(['warnings' => $warnings]);
}
/**
 * Delete budget
 * DELETE: id
 */
function deleteBudget() {
    requireActiveSession();
    $data = getBudgetInput();
    if (!$data) {
        $data = [];
    }
    if (!isset($data['id'])) {
        $data['id'] = $_GET['id'] ?? '';
    }
    if (!isset($data['csrf_token'])) {
        $data['csrf_token'] = $_GET['csrf_token'] ?? $_COOKIE['csrf_token'] ?? '';
    }
    if (!verifyCSRFToken($data['csrf_token'])) {
        errorResponse('Invalid security token');
    }
    $budgetId = $data['id'] ?? '';
    if (!isValidObjectId($budgetId)) {
        errorResponse('Invalid budget ID');
    }
    $collection = getCollection('budgets');
    $budget = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($budgetId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
    ]);
    if (!$budget) {
        errorResponse('Budget not found');
    }
    // Soft delete by deactivating
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($budgetId)],
        [
            '$set' => [
                'is_active' => false,
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    logActivity('budget_deleted', getCurrentUserId(), [
        'budget_id' => $budgetId,
        'category' => $budget['category']
    ]);
    successResponse(null, 'Budget deleted successfully');
}
/**
 * Get budget suggestions based on spending patterns
 */
function getBudgetSuggestions() {
    requireActiveSession();
    $userId = getCurrentUserId();
    $transactionsCollection = getCollection('transactions');
    $budgetsCollection = getCollection('budgets');
    // Get last 3 months spending by category
    $threeMonthsAgo = date('Y-m-d', strtotime('-3 months'));
    $pipeline = [
        ['$match' => [
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'type' => 'expense',
            'date' => ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($threeMonthsAgo . ' 00:00:00') * 1000)],
            'deleted_at' => null
        ]],
        ['$group' => [
            '_id' => '$category',
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1],
            'average' => ['$avg' => '$amount']
        ]],
        ['$sort' => ['total' => -1]]
    ];
    $spendingData = $transactionsCollection->aggregate($pipeline)->toArray();
    $suggestions = [];
    foreach ($spendingData as $item) {
        $monthlyAverage = $item['total'] / 3;
        // Check if user has existing budget for this category
        $existingBudget = $budgetsCollection->findOne([
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'category' => $item['_id'],
            'is_active' => true
        ]);
        $hasBudget = $existingBudget !== null;
        $currentLimit = $existingBudget['monthly_limit'] ?? 0;
        $suggestions[] = [
            'category' => $item['_id'],
            'three_month_total' => $item['total'],
            'monthly_average' => round($monthlyAverage, 2),
            'transaction_count' => $item['count'],
            'average_per_transaction' => round($item['average'], 2),
            'has_budget' => $hasBudget,
            'current_budget_limit' => $currentLimit,
            'suggested_limit' => round($monthlyAverage * 1.1, 2), // 10% buffer
            'recommendation' => !$hasBudget ?
                'Consider setting a budget of ₹' . round($monthlyAverage * 1.1, 2) . ' for ' . $item['_id'] :
                ($monthlyAverage > $currentLimit ?
                    'Your average spending (₹' . round($monthlyAverage, 2) . ') exceeds your budget limit. Consider increasing it.' :
                    'Your budget seems appropriate for your spending pattern.')
        ];
    }
    successResponse(['suggestions' => $suggestions]);
}
/**
 * Get budget utilization report
 */
function getBudgetUtilization() {
    requireActiveSession();
    $userId = getCurrentUserId();
    $period = $_GET['period'] ?? 'month';
    $dateRange = calculateDateRange($period);
    $dateFrom = $dateRange['from'];
    $dateTo = $dateRange['to'];
    $budgetsCollection = getCollection('budgets');
    $transactionsCollection = getCollection('transactions');
    // Get budgets for the period
    $budgets = $budgetsCollection->find([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'is_active' => true
    ])->toArray();
    $utilization = [];
    foreach ($budgets as $budget) {
        // Calculate actual spending for this category in the period
        $pipeline = [
            ['$match' => [
                'user_id' => new MongoDB\BSON\ObjectId($userId),
                'type' => 'expense',
                'category' => $budget['category'],
                'date' => [
                    '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($dateFrom) * 1000),
                    '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($dateTo . ' 23:59:59') * 1000),
                ],
                'deleted_at' => null
            ]],
            ['$group' => [
                '_id' => null,
                'total' => ['$sum' => '$amount']
            ]]
        ];
        $result = $transactionsCollection->aggregate($pipeline)->toArray();
        $actualSpent = $result[0]['total'] ?? 0;
        $utilization[] = [
            'category' => $budget['category'],
            'budget_limit' => $budget['monthly_limit'],
            'actual_spent' => $actualSpent,
            'remaining' => $budget['monthly_limit'] - $actualSpent,
            'utilization_percentage' => round(($actualSpent / $budget['monthly_limit']) * 100, 2),
            'is_over_budget' => $actualSpent > $budget['monthly_limit'],
            'status' => $actualSpent > $budget['monthly_limit'] ? 'over' :
                (($actualSpent / $budget['monthly_limit']) >= ($budget['warning_threshold'] / 100) ? 'warning' : 'healthy')
        ];
    }
    successResponse([
        'period' => $period,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'utilization' => $utilization
    ]);
}
// Route handling
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create':
    case 'update':
        if ($method === 'POST') createOrUpdateBudget();
        break;
    case 'get_all':
        if ($method === 'GET') getBudgets();
        break;
    case 'get_warnings':
        if ($method === 'GET') getBudgetWarnings();
        break;
    case 'delete':
        if ($method === 'POST' || $method === 'DELETE') deleteBudget();
        break;
    case 'suggestions':
        if ($method === 'GET') getBudgetSuggestions();
        break;
    case 'utilization':
        if ($method === 'GET') getBudgetUtilization();
        break;
    default:
        errorResponse('Invalid action');
}
?>
