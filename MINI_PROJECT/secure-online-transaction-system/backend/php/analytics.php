<?php
// backend/php/analytics.php
/**
 * Analytics Engine for Smart Transaction Control
 * Aggregates transaction data into insights, trends and category breakdowns
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
 * Analytics overview for the dashboard and analytics page
 * GET: action=overview&period=today|week|month|last_month|year|all
 */
function getAnalyticsOverview() {
    requireActiveSession();
    $period = sanitizeInput($_GET['period'] ?? 'month');
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $collection = getCollection('transactions');
    // Base filter for the selected period
    $baseFilter = [
        'user_id' => $userId,
        'type' => ['$in' => ['income', 'expense']],
        'deleted_at' => null
    ];
    // Apply period filter (all time for trend computations)
    if ($period !== 'all') {
        $dateRange = getAnalyticsDateRange($period);
        $baseFilter['date'] = [
            '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($dateRange['from']) * 1000),
            '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($dateRange['to'] . ' 23:59:59') * 1000),
        ];
    }
    // Total income and expense
    $totalsPipeline = [
        ['$match' => $baseFilter],
        ['$group' => [
            '_id' => '$type',
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]]
    ];
    $totalsResult = $collection->aggregate($totalsPipeline)->toArray();
    $totalIncome = 0;
    $totalExpense = 0;
    $totalTransactions = 0;
    foreach ($totalsResult as $item) {
        $totalTransactions += $item['count'];
        if ($item['_id'] === 'income') {
            $totalIncome = $item['total'];
        } elseif ($item['_id'] === 'expense') {
            $totalExpense = $item['total'];
        }
    }
    // Daily spending (for avg daily & highest spending day)
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
    $dayCount = count($dailyResult);
    $totalDailySpending = 0;
    $highestSpendingDay = 0;
    foreach ($dailyResult as $item) {
        $totalDailySpending += $item['total'];
        if ($item['total'] > $highestSpendingDay) {
            $highestSpendingDay = $item['total'];
        }
    }
    $avgDailySpending = $dayCount > 0 ? round($totalDailySpending / $dayCount, 2) : 0;
    // Monthly trend (last 6 months)
    $trendPipeline = [
        ['$match' => ['user_id' => $userId, 'deleted_at' => null, 'type' => ['$in' => ['income', 'expense']]]],
        ['$group' => [
            '_id' => [
                'year' => ['$year' => '$date'],
                'month' => ['$month' => '$date'],
            ],
            'income' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'income']], '$amount', 0]]],
            'expense' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'expense']], '$amount', 0]]]
        ]],
        ['$sort' => ['_id' => 1]]
    ];
    $trendResult = $collection->aggregate($trendPipeline)->toArray();
    $monthlyTrend = array_slice($trendResult, -6);
    $formattedTrend = [];
    foreach ($monthlyTrend as $item) {
        $formattedTrend[] = [
            'month' => date('M Y', strtotime(sprintf('%04d-%02d-01', $item['_id']['year'], $item['_id']['month']))),
            'income' => $item['income'],
            'expense' => $item['expense']
        ];
    }
    // Category breakdown (expenses this period)
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
            'count' => $item['count']
        ];
    }
    // Category trends (this month vs last month)
    $categoryTrends = getCategoryTrends($userId);
    // Average monthly spending (last 6 months total / months)
    $avgMonthlySpending = count($formattedTrend) > 0
        ? round(array_sum(array_column($formattedTrend, 'expense')) / count($formattedTrend), 2)
        : 0;
    successResponse([
        'period' => $period,
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'balance' => $totalIncome - $totalExpense,
        'total_transactions' => $totalTransactions,
        'avg_daily_spending' => $avgDailySpending,
        'avg_monthly_spending' => $avgMonthlySpending,
        'highest_spending_day' => $highestSpendingDay,
        'monthly_trend' => $formattedTrend,
        'category_breakdown' => $categoryBreakdown,
        'category_trends' => $categoryTrends
    ]);
}
/**
 * Compare category spending between current month and previous month
 * @param MongoDB\BSON\ObjectId $userId
 * @return array
 */
/**
 * Compare category spending between current month and previous month
 * @param MongoDB\BSON\ObjectId $userId
 * @return array
 */
function getAnalyticsDateRange($period) {
    switch ($period) {
        case 'today':
            return ['from' => date('Y-m-d'), 'to' => date('Y-m-d')];
        case 'week':
            return [
                'from' => date('Y-m-d', strtotime('monday this week')),
                'to' => date('Y-m-d', strtotime('sunday this week'))
            ];
        case 'month':
            return ['from' => date('Y-m-01'), 'to' => date('Y-m-t')];
        case 'last_month':
            return [
                'from' => date('Y-m-01', strtotime('-1 month')),
                'to' => date('Y-m-t', strtotime('-1 month'))
            ];
        case 'year':
            return ['from' => date('Y-01-01'), 'to' => date('Y-12-31')];
        default:
            return ['from' => date('Y-m-01'), 'to' => date('Y-m-t')];
    }
}
function getCategoryTrends($userId) {
    $collection = getCollection('transactions');
    $thisMonthRange = getAnalyticsDateRange('month');
    $lastMonthRange = getAnalyticsDateRange('last_month');
    $aggregateMonth = function($from, $to) use ($collection, $userId) {
        $pipeline = [
            ['$match' => [
                'user_id' => $userId,
                'type' => 'expense',
                'deleted_at' => null,
                'date' => [
                    '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($from) * 1000),
                    '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($to . ' 23:59:59') * 1000),
                ]
            ]],
            ['$group' => [
                '_id' => '$category',
                'total' => ['$sum' => '$amount']
            ]]
        ];
        $result = $collection->aggregate($pipeline)->toArray();
        $map = [];
        foreach ($result as $item) {
            $map[$item['_id']] = $item['total'];
        }
        return $map;
    };
    $thisMonth = $aggregateMonth($thisMonthRange['from'], $thisMonthRange['to']);
    $lastMonth = $aggregateMonth($lastMonthRange['from'], $lastMonthRange['to']);
    $categories = array_unique(array_merge(array_keys($thisMonth), array_keys($lastMonth)));
    $trends = [];
    foreach ($categories as $category) {
        $trends[] = [
            'category' => $category,
            'this_month' => $thisMonth[$category] ?? 0,
            'last_month' => $lastMonth[$category] ?? 0
        ];
    }
    // Sort by this month spending descending
    usort($trends, function($a, $b) {
        return $b['this_month'] <=> $a['this_month'];
    });
    return $trends;
}
/**
 * Get payment method breakdown
 * GET: action=payment_methods&period=month
 */
function getPaymentMethodBreakdown() {
    requireActiveSession();
    $period = sanitizeInput($_GET['period'] ?? 'month');
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $collection = getCollection('transactions');
    $filter = [
        'user_id' => $userId,
        'type' => 'expense',
        'deleted_at' => null
    ];
    if ($period !== 'all') {
        $dateRange = getAnalyticsDateRange($period);
        $filter['date'] = [
            '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($dateRange['from']) * 1000),
            '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($dateRange['to'] . ' 23:59:59') * 1000),
        ];
    }
    $pipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => '$payment_method',
            'total' => ['$sum' => '$amount'],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['total' => -1]]
    ];
    $result = $collection->aggregate($pipeline)->toArray();
    $breakdown = [];
    foreach ($result as $item) {
        $breakdown[] = [
            'payment_method' => $item['_id'],
            'total' => $item['total'],
            'count' => $item['count']
        ];
    }
    successResponse(['payment_methods' => $breakdown]);
}
/**
 * Get weekly spending pattern
 * GET: action=weekly_pattern&period=month
 */
function getWeeklyPattern() {
    requireActiveSession();
    $period = sanitizeInput($_GET['period'] ?? 'month');
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $collection = getCollection('transactions');
    $filter = [
        'user_id' => $userId,
        'type' => 'expense',
        'deleted_at' => null
    ];
    if ($period !== 'all') {
        $dateRange = getAnalyticsDateRange($period);
        $filter['date'] = [
            '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($dateRange['from']) * 1000),
            '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($dateRange['to'] . ' 23:59:59') * 1000),
        ];
    }
    $pipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => ['$dayOfWeek' => '$date'],
            'total' => ['$sum' => '$amount']
        ]]
    ];
    $result = $collection->aggregate($pipeline)->toArray();
    // Mongo dayOfWeek: 1=Sunday ... 7=Saturday. Map to Mon..Sun
    $dayMap = [1 => 7, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6];
    $days = [0, 0, 0, 0, 0, 0, 0];
    foreach ($result as $item) {
        $index = $dayMap[$item['_id']] ?? 7;
        $days[$index - 1] = $item['total'];
    }
    successResponse([
        'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        'data' => $days
    ]);
}
// Route handling
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'overview':
        if ($method === 'GET') getAnalyticsOverview();
        break;
    case 'payment_methods':
        if ($method === 'GET') getPaymentMethodBreakdown();
        break;
    case 'weekly_pattern':
        if ($method === 'GET') getWeeklyPattern();
        break;
    default:
        errorResponse('Invalid action');
}
?>
