<?php
declare(strict_types=1);
// Smart Analytics API - Bridges Python AI modules for predictions and insights
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'spending_prediction': $method === 'GET' && getSpendingPrediction(); break;
    case 'budget_prediction': $method === 'GET' && getBudgetPrediction(); break;
    case 'trend_analysis': $method === 'GET' && getTrendAnalysis(); break;
    case 'monthly_forecast': $method === 'GET' && getMonthlyForecast(); break;
    case 'frequent_merchants': $method === 'GET' && getFrequentMerchants(); break;
    case 'unusual_expenses': $method === 'GET' && getUnusualExpenses(); break;
    case 'saving_suggestions': $method === 'GET' && getSavingSuggestions(); break;
    case 'financial_score': $method === 'GET' && getFinancialScore(); break;
    case 'category_analysis': $method === 'GET' && getCategoryAnalysis(); break;
    case 'weekly_insights': $method === 'GET' && getWeeklyInsights(); break;
    case 'monthly_insights': $method === 'GET' && getMonthlyInsights(); break;
    case 'all_insights': $method === 'GET' && getAllInsights(); break;
    default: errorResponse('Invalid action', 404);
}

/**
 * Get the Python AI script directory.
 */
function getPythonScriptDir() {
    return dirname(__DIR__, 2) . '/python/ai';
}

/**
 * Get the Python executable path.
 */
function getPythonExecutable() {
    return getenv('PYTHON_PATH') ?: 'python';
}

/**
 * Execute a Python AI script with transaction data passed via stdin.
 * @param string $script Script name (without .py)
 * @param array $data Data to pass to the script
 * @return array|null Parsed JSON result or null on failure
 */
function runPythonAI($script, array $data) {
    $scriptPath = getPythonScriptDir() . '/' . $script . '.py';
    if (!file_exists($scriptPath)) {
        error_log("Python script not found: {$scriptPath}");
        return null;
    }
    $jsonInput = json_encode($data);
    $python = getPythonExecutable();
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w']  // stderr
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        error_log("Failed to start Python process for {$script}");
        return null;
    }
    fwrite($pipes[0], $jsonInput);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        error_log("Python script {$script} failed (exit {$exitCode}): {$stderr}");
        return null;
    }
    $result = json_decode($stdout, true);
    if (!is_array($result)) {
        error_log("Python script {$script} returned invalid JSON: " . substr($stdout, 0, 200));
        return null;
    }
    return $result;
}

/**
 * Get user's transaction data for AI analysis.
 * @param int $months Number of months of history to fetch
 * @return array
 */
function getTransactionsForAI($months = 6) {
    $collection = getCollection('transactions');
    if (!$collection) return [];
    $fromDate = date('Y-m-d', strtotime('-' . $months . ' months'));
    $transactions = $collection->find([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'type' => ['$in' => ['income', 'expense']],
        'date' => ['$gte' => phpDateToMongo($fromDate)],
        'deleted_at' => null
    ], ['sort' => ['date' => 1]])->toArray();
    $formatted = array_map(function($t) {
        return [
            'date' => mongoDateToPHP($t['date'])->format('Y-m-d'),
            'type' => $t['type'],
            'category' => $t['category'] ?? 'Other',
            'amount' => (float)($t['amount'] ?? 0),
            'merchant' => $t['merchant'] ?? ($t['recipient_payer'] ?? ''),
            'description' => $t['description'] ?? '',
            'payment_method' => $t['payment_method'] ?? 'cash'
        ];
    }, $transactions);
    return $formatted;
}

/**
 * Get spending prediction for the next month.
 */
function getSpendingPrediction() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    if (empty($transactions)) {
        successResponse(['prediction' => null, 'message' => 'Not enough transaction data for prediction']);
    }
    $result = runPythonAI('expense_prediction', [
        'transactions' => $transactions,
        'months' => $months
    ]);
    if ($result === null) {
        // Fallback: simple average-based prediction
        $expenses = array_filter($transactions, fn($t) => $t['type'] === 'expense');
        $total = array_sum(array_column($expenses, 'amount'));
        $count = count($expenses);
        $result = [
            'predicted_next_month' => $count > 0 ? round($total / max(1, $months) * 1.05, 2) : 0,
            'confidence' => $count > 20 ? 'high' : ($count > 5 ? 'medium' : 'low'),
            'method' => 'fallback_average'
        ];
    }
    successResponse($result);
}

/**
 * Get budget prediction for the current month.
 */
function getBudgetPrediction() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $budgetsCollection = getCollection('budgets');
    $budgets = $budgetsCollection->find([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'is_active' => true,
        'deleted_at' => null
    ])->toArray();
    $budgetData = array_map(function($b) {
        return [
            'category' => $b['category'],
            'limit' => (float)($b['limit'] ?? 0),
            'spent' => (float)($b['current_spent'] ?? 0)
        ];
    }, $budgets);
    $result = runPythonAI('budget_recommendation', [
        'transactions' => $transactions,
        'budgets' => $budgetData
    ]);
    if ($result === null) {
        $result = ['recommendations' => [], 'message' => 'Budget prediction unavailable'];
    }
    successResponse($result);
}

/**
 * Get expense trend analysis.
 */
function getTrendAnalysis() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $result = runPythonAI('spending_analysis', [
        'transactions' => $transactions,
        'months' => $months
    ]);
    if ($result === null) {
        // Fallback: compute monthly totals in PHP
        $monthly = [];
        foreach ($transactions as $t) {
            if ($t['type'] !== 'expense') continue;
            $month = substr($t['date'], 0, 7);
            if (!isset($monthly[$month])) $monthly[$month] = 0;
            $monthly[$month] += $t['amount'];
        }
        ksort($monthly);
        $result = ['monthly_totals' => $monthly, 'trend' => 'stable', 'method' => 'fallback'];
    }
    successResponse($result);
}

/**
 * Get monthly spending forecast.
 */
function getMonthlyForecast() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $forecastMonths = max(1, min(6, (int)($_GET['forecast_months'] ?? 3)));
    $transactions = getTransactionsForAI($months);
    $result = runPythonAI('monthly_forecast', [
        'transactions' => $transactions,
        'months' => $months,
        'forecast_months' => $forecastMonths
    ]);
    if ($result === null) {
        $result = ['forecast' => [], 'message' => 'Forecast unavailable'];
    }
    successResponse($result);
}

/**
 * Get frequent merchant detection.
 */
function getFrequentMerchants() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $result = runPythonAI('fraud_detection', [
        'transactions' => $transactions,
        'months' => $months
    ]);
    if ($result === null) {
        // Fallback: count merchant occurrences
        $merchants = [];
        foreach ($transactions as $t) {
            $merchant = $t['merchant'] ?: 'Unknown';
            if (!isset($merchants[$merchant])) {
                $merchants[$merchant] = ['count' => 0, 'total' => 0];
            }
            $merchants[$merchant]['count']++;
            $merchants[$merchant]['total'] += $t['amount'];
        }
        arsort($merchants);
        $result = ['frequent_merchants' => array_slice($merchants, 0, 10, true)];
    }
    successResponse($result);
}

/**
 * Get unusual expense detection.
 */
function getUnusualExpenses() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $result = runPythonAI('anomaly_detection', [
        'transactions' => $transactions,
        'months' => $months
    ]);
    if ($result === null) {
        // Fallback: flag expenses > 3x average
        $expenses = array_values(array_filter($transactions, fn($t) => $t['type'] === 'expense'));
        $avg = count($expenses) > 0 ? array_sum(array_column($expenses, 'amount')) / count($expenses) : 0;
        $anomalies = array_filter($expenses, fn($t) => $avg > 0 && $t['amount'] > $avg * 3);
        $result = ['anomalies' => array_values($anomalies), 'average' => round($avg, 2)];
    }
    successResponse($result);
}

/**
 * Get saving suggestions.
 */
function getSavingSuggestions() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $result = runPythonAI('budget_recommendation', [
        'transactions' => $transactions,
        'budgets' => []
    ]);
    if ($result === null) {
        // Fallback: simple suggestions based on category spending
        $categorySpending = [];
        foreach ($transactions as $t) {
            if ($t['type'] !== 'expense') continue;
            $cat = $t['category'];
            if (!isset($categorySpending[$cat])) $categorySpending[$cat] = 0;
            $categorySpending[$cat] += $t['amount'];
        }
        arsort($categorySpending);
        $suggestions = [];
        $total = array_sum($categorySpending);
        foreach ($categorySpending as $cat => $amount) {
            if ($total > 0 && $amount / $total > 0.2 && in_array($cat, ['Entertainment', 'Shopping', 'Recharge'])) {
                $suggestions[] = $cat . " spending is " . round(($amount / $total) * 100, 1) . "% of total. Consider reducing by 10%.";
            }
        }
        $result = ['suggestions' => $suggestions];
    }
    successResponse($result);
}

/**
 * Get financial health score.
 */
function getFinancialScore() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $result = runPythonAI('spending_analysis', [
        'transactions' => $transactions,
        'months' => $months
    ]);
    if ($result === null) {
        // Fallback: compute score in PHP
        $income = array_sum(array_column(array_filter($transactions, fn($t) => $t['type'] === 'income'), 'amount'));
        $expense = array_sum(array_column(array_filter($transactions, fn($t) => $t['type'] === 'expense'), 'amount'));
        $score = 0;
        if ($income > 0) {
            $savingsRate = ($income - $expense) / $income;
            $score = (int)round(min(100, max(0, $savingsRate * 200)));
        }
        $result = ['score' => $score, 'income' => $income, 'expense' => $expense];
    }
    successResponse($result);
}

/**
 * Get category spending analysis.
 */
function getCategoryAnalysis() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $result = runPythonAI('spending_analysis', [
        'transactions' => $transactions,
        'months' => $months
    ]);
    if ($result === null) {
        // Fallback: group by category
        $categories = [];
        foreach ($transactions as $t) {
            if ($t['type'] !== 'expense') continue;
            $cat = $t['category'];
            if (!isset($categories[$cat])) $categories[$cat] = 0;
            $categories[$cat] += $t['amount'];
        }
        arsort($categories);
        $result = ['categories' => $categories];
    }
    successResponse($result);
}

/**
 * Get weekly insights.
 */
function getWeeklyInsights() {
    requireActiveSession();
    $weeks = max(1, min(12, (int)($_GET['weeks'] ?? 4)));
    $transactions = getTransactionsForAI($weeks * 4);
    $result = runPythonAI('spending_analysis', [
        'transactions' => $transactions,
        'months' => $weeks * 4
    ]);
    if ($result === null) {
        $result = ['insights' => [], 'message' => 'Weekly insights unavailable'];
    }
    successResponse($result);
}

/**
 * Get monthly insights.
 */
function getMonthlyInsights() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $result = runPythonAI('spending_analysis', [
        'transactions' => $transactions,
        'months' => $months
    ]);
    if ($result === null) {
        $result = ['insights' => [], 'message' => 'Monthly insights unavailable'];
    }
    successResponse($result);
}

/**
 * Get all insights in one call.
 */
function getAllInsights() {
    requireActiveSession();
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $transactions = getTransactionsForAI($months);
    $insights = [];
    // Financial score
    $scoreResult = runPythonAI('spending_analysis', ['transactions' => $transactions, 'months' => $months]);
    $insights['financial_score'] = $scoreResult['score'] ?? null;
    // Spending prediction
    $prediction = runPythonAI('expense_prediction', ['transactions' => $transactions, 'months' => $months]);
    $insights['spending_prediction'] = $prediction['predicted_next_month'] ?? null;
    // Anomalies
    $anomalies = runPythonAI('anomaly_detection', ['transactions' => $transactions, 'months' => $months]);
    $insights['unusual_expenses'] = $anomalies['anomalies'] ?? [];
    // Category analysis
    $categoryAnalysis = runPythonAI('spending_analysis', ['transactions' => $transactions, 'months' => $months]);
    $insights['category_analysis'] = $categoryAnalysis['categories'] ?? [];
    // Saving suggestions
    $suggestions = runPythonAI('budget_recommendation', ['transactions' => $transactions, 'budgets' => []]);
    $insights['saving_suggestions'] = $suggestions['suggestions'] ?? [];
    // Monthly forecast
    $forecast = runPythonAI('monthly_forecast', ['transactions' => $transactions, 'months' => $months, 'forecast_months' => 3]);
    $insights['monthly_forecast'] = $forecast['forecast'] ?? [];
    successResponse($insights);
}