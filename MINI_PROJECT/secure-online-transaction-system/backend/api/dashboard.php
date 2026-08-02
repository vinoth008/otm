<?php
declare(strict_types=1);
// Dashboard API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'user_stats': $method === 'GET' && getUserDashboardStats(); break;
    case 'admin_stats': $method === 'GET' && getAdminDashboardStats(); break;
    default: errorResponse('Invalid action', 404);
}

function getUserDashboardStats() {
    requireActiveSession();
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $tx = getCollection('transactions');
    $exp = getCollection('expenses');
    $bud = getCollection('budgets');
    $monthStart = phpDateToMongo(date('Y-m-01'));
    $monthEnd = phpDateToMongo(date('Y-m-t') . ' 23:59:59');
    $monthFilter = ['user_id' => $userId, 'date' => ['$gte' => $monthStart, '$lte' => $monthEnd], 'deleted_at' => null];
    $income = $tx->aggregate([['$match' => array_merge($monthFilter, ['type' => 'income'])], ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]])->toArray();
    $expense = $tx->aggregate([['$match' => array_merge($monthFilter, ['type' => 'expense'])], ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]])->toArray();
    $totalIncome = $income[0]['total'] ?? 0;
    $totalExpense = $expense[0]['total'] ?? 0;
    $balance = $totalIncome - $totalExpense;
    $budgets = $bud->find(['user_id' => $userId, 'is_active' => true, 'deleted_at' => null])->toArray();
    $budgetAlerts = 0;
    foreach ($budgets as $b) {
        if (($b['current_spent'] ?? 0) >= ($b['limit'] ?? 0) * 0.8) $budgetAlerts++;
    }
    $recent = $tx->find(['user_id' => $userId, 'deleted_at' => null], ['sort' => ['date' => -1], 'limit' => 5])->toArray();
    $recentTx = array_map(function($t) {
        return ['_id' => (string)$t['_id'], 'type' => $t['type'], 'category' => $t['category'], 'amount' => $t['amount'], 'description' => $t['description'] ?? '', 'date' => mongoDateToPHP($t['date'])->format('Y-m-d')];
    }, $recent);

    // ── Chart data ─────────────────────────────────────────────
    // Monthly revenue/expenses (last 12 months)
    $monthlyRevenue = array_fill(0, 12, 0);
    $monthlyExpenses = array_fill(0, 12, 0);
    $yearStart = phpDateToMongo(date('Y-01-01'));
    $yearEnd = phpDateToMongo(date('Y-12-31 23:59:59'));
    $yearTx = $tx->find(['user_id' => $userId, 'date' => ['$gte' => $yearStart, '$lte' => $yearEnd], 'deleted_at' => null])->toArray();
    foreach ($yearTx as $t) {
        $m = (int)mongoDateToPHP($t['date'])->format('n') - 1;
        if ($t['type'] === 'income') $monthlyRevenue[$m] += $t['amount'];
        elseif ($t['type'] === 'expense') $monthlyExpenses[$m] += $t['amount'];
    }

    // Transaction type breakdown
    $txTypes = ['income' => 0, 'expense' => 0, 'transfer' => 0];
    $typeAgg = $tx->aggregate([
        ['$match' => ['user_id' => $userId, 'deleted_at' => null]],
        ['$group' => ['_id' => '$type', 'count' => ['$sum' => 1]]]
    ])->toArray();
    foreach ($typeAgg as $row) {
        $txTypes[$row['_id']] = $row['count'];
    }

    // Weekly transactions (Mon-Sun)
    $weekly = array_fill(0, 7, 0);
    $weekStart = phpDateToMongo(date('Y-m-d', strtotime('monday this week')));
    $weekEnd = phpDateToMongo(date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59');
    $weekTx = $tx->find(['user_id' => $userId, 'date' => ['$gte' => $weekStart, '$lte' => $weekEnd], 'deleted_at' => null])->toArray();
    foreach ($weekTx as $t) {
        $dow = (int)mongoDateToPHP($t['date'])->format('N') - 1;
        $weekly[$dow]++;
    }

    // Category breakdown
    $categoryBreakdown = [];
    $catAgg = $tx->aggregate([
        ['$match' => ['user_id' => $userId, 'type' => 'expense', 'deleted_at' => null]],
        ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount']]]
    ])->toArray();
    foreach ($catAgg as $c) {
        $categoryBreakdown[$c['_id']] = $c['total'];
    }

    successResponse([
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'balance' => $balance,
        'savings_rate' => $totalIncome > 0 ? round((($totalIncome - $totalExpense) / $totalIncome) * 100, 2) : 0,
        'budget_alerts' => $budgetAlerts,
        'recent_transactions' => $recentTx,
        'monthly_revenue' => $monthlyRevenue,
        'monthly_expenses' => $monthlyExpenses,
        'tx_types' => $txTypes,
        'weekly_transactions' => $weekly,
        'category_breakdown' => $categoryBreakdown
    ]);
}

function getAdminDashboardStats() {
    requireRole(['admin', 'staff', 'receptionist']);
    $users = getCollection('users');
    $tx = getCollection('transactions');
    $totalUsers = $users->countDocuments(['deleted_at' => null]);
    $activeUsers = $users->countDocuments(['status' => 'active', 'deleted_at' => null]);
    $totalTx = $tx->countDocuments(['deleted_at' => null]);
    $totalVolume = $tx->aggregate([['$match' => ['deleted_at' => null]], ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]])->toArray();
    $recentUsers = $users->find(['deleted_at' => null], ['sort' => ['created_at' => -1], 'limit' => 5])->toArray();
    $userList = array_map(function($u) {
        return ['_id' => (string)$u['_id'], 'name' => $u['first_name'] . ' ' . ($u['last_name'] ?? ''), 'email' => $u['email'], 'role' => $u['role'], 'status' => $u['status'] ?? 'active', 'created_at' => mongoDateToPHP($u['created_at'])->format('Y-m-d')];
    }, $recentUsers);

    // Recent transactions (join user name)
    $recentTx = $tx->find(['deleted_at' => null], ['sort' => ['date' => -1], 'limit' => 5])->toArray();
    $recentTxList = array_map(function($t) use ($users) {
        $owner = $users->findOne(['_id' => $t['user_id']]);
        $name = $owner ? $owner['first_name'] . ' ' . ($owner['last_name'] ?? '') : 'Unknown';
        return [
            '_id' => (string)$t['_id'],
            'user_name' => trim($name),
            'type' => $t['type'],
            'amount' => $t['amount'],
            'status' => $t['status'] ?? 'success',
            'date' => mongoDateToPHP($t['date'])->format('Y-m-d H:i')
        ];
    }, $recentTx);

    // Monthly revenue/expenses (last 12 months) for admin
    $monthlyRevenue = array_fill(0, 12, 0);
    $monthlyExpenses = array_fill(0, 12, 0);
    $yearStart = phpDateToMongo(date('Y-01-01'));
    $yearEnd = phpDateToMongo(date('Y-12-31 23:59:59'));
    $yearTx = $tx->find(['date' => ['$gte' => $yearStart, '$lte' => $yearEnd], 'deleted_at' => null])->toArray();
    foreach ($yearTx as $t) {
        $m = (int)mongoDateToPHP($t['date'])->format('n') - 1;
        if ($t['type'] === 'income') $monthlyRevenue[$m] += $t['amount'];
        elseif ($t['type'] === 'expense') $monthlyExpenses[$m] += $t['amount'];
    }

    // Transaction type breakdown
    $txTypes = ['income' => 0, 'expense' => 0, 'transfer' => 0];
    $typeAgg = $tx->aggregate([
        ['$match' => ['deleted_at' => null]],
        ['$group' => ['_id' => '$type', 'count' => ['$sum' => 1]]]
    ])->toArray();
    foreach ($typeAgg as $row) {
        if (isset($txTypes[$row['_id']])) $txTypes[$row['_id']] = $row['count'];
    }

    // Weekly transactions (Mon-Sun)
    $weekly = array_fill(0, 7, 0);
    $weekStart = phpDateToMongo(date('Y-m-d', strtotime('monday this week')));
    $weekEnd = phpDateToMongo(date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59');
    $weekTx = $tx->find(['date' => ['$gte' => $weekStart, '$lte' => $weekEnd], 'deleted_at' => null])->toArray();
    foreach ($weekTx as $t) {
        $dow = (int)mongoDateToPHP($t['date'])->format('N') - 1;
        $weekly[$dow]++;
    }

    successResponse([
        'total_users' => $totalUsers,
        'active_users' => $activeUsers,
        'total_transactions' => $totalTx,
        'total_volume' => $totalVolume[0]['total'] ?? 0,
        'recent_users' => $userList,
        'recent_transactions' => $recentTxList,
        'monthly_revenue' => $monthlyRevenue,
        'monthly_expenses' => $monthlyExpenses,
        'tx_types' => $txTypes,
        'weekly_transactions' => $weekly
    ]);
}