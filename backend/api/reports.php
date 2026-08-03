<?php
declare(strict_types=1);
// Reports API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'monthly_summary': $method === 'GET' && getMonthlySummary(); break;
    case 'yearly_summary': $method === 'GET' && getYearlySummary(); break;
    case 'category_breakdown': $method === 'GET' && getCategoryBreakdown(); break;
    case 'export_csv': $method === 'GET' && exportCSV(); break;
    case 'export_pdf': $method === 'GET' && exportPDF(); break;
    case 'admin_monthly_summary': $method === 'GET' && getAdminMonthlySummary(); break;
    case 'admin_yearly_summary': $method === 'GET' && getAdminYearlySummary(); break;
    case 'admin_category_breakdown': $method === 'GET' && getAdminCategoryBreakdown(); break;
    case 'admin_export_csv': $method === 'GET' && adminExportCSV(); break;
    case 'admin_export_pdf': $method === 'GET' && adminExportPDF(); break;
    default: errorResponse('Invalid action', 404);
}

function getMonthlySummary() {
    requireActiveSession();
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('m'));
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $tx = getCollection('transactions');
    $start = phpDateToMongo(sprintf('%04d-%02d-01', $year, $month));
    $end = phpDateToMongo(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year)));
    $filter = ['user_id' => $userId, 'date' => ['$gte' => $start, '$lte' => $end], 'deleted_at' => null];
    $income = $tx->aggregate([['$match' => array_merge($filter, ['type' => 'income'])], ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]])->toArray();
    $expense = $tx->aggregate([['$match' => array_merge($filter, ['type' => 'expense'])], ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]])->toArray();
    $totalIncome = $income[0]['total'] ?? 0;
    $totalExpense = $expense[0]['total'] ?? 0;
    $daily = $tx->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$date']], 'income' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'income']], '$amount', 0]]], 'expense' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'expense']], '$amount', 0]]]]],
        ['$sort' => ['_id' => 1]]
    ])->toArray();
    successResponse([
        'year' => $year,
        'month' => $month,
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'net' => $totalIncome - $totalExpense,
        'daily' => $daily
    ]);
}

function getYearlySummary() {
    requireActiveSession();
    $year = (int)($_GET['year'] ?? date('Y'));
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $tx = getCollection('transactions');
    $start = phpDateToMongo(sprintf('%04d-01-01', $year));
    $end = phpDateToMongo(sprintf('%04d-12-31 23:59:59', $year));
    $filter = ['user_id' => $userId, 'date' => ['$gte' => $start, '$lte' => $end], 'deleted_at' => null];
    $monthly = $tx->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => ['$month' => '$date'], 'income' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'income']], '$amount', 0]]], 'expense' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'expense']], '$amount', 0]]]]],
        ['$sort' => ['_id' => 1]]
    ])->toArray();
    $months = [];
    for ($i = 1; $i <= 12; $i++) {
        $found = null;
        foreach ($monthly as $m) { if ($m['_id'] == $i) { $found = $m; break; } }
        $months[] = ['month' => $i, 'income' => $found['income'] ?? 0, 'expense' => $found['expense'] ?? 0];
    }
    $totalIncome = array_sum(array_column($months, 'income'));
    $totalExpense = array_sum(array_column($months, 'expense'));
    successResponse(['year' => $year, 'months' => $months, 'total_income' => $totalIncome, 'total_expense' => $totalExpense, 'net' => $totalIncome - $totalExpense]);
}

function getCategoryBreakdown() {
    requireActiveSession();
    $type = $_GET['type'] ?? 'expense';
    $period = $_GET['period'] ?? 'month';
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $tx = getCollection('transactions');
    $filter = ['user_id' => $userId, 'type' => $type, 'deleted_at' => null];
    if ($period === 'month') {
        $filter['date'] = ['$gte' => phpDateToMongo(date('Y-m-01')), '$lte' => phpDateToMongo(date('Y-m-t') . ' 23:59:59')];
    } elseif ($period === 'year') {
        $filter['date'] = ['$gte' => phpDateToMongo(date('Y-01-01')), '$lte' => phpDateToMongo(date('Y-12-31') . ' 23:59:59')];
    }
    $result = $tx->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]],
        ['$sort' => ['total' => -1]]
    ])->toArray();
    successResponse(['categories' => $result]);
}

function exportCSV() {
    requireActiveSession();
    $type = $_GET['type'] ?? 'transactions';
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $collection = getCollection($type === 'expenses' ? 'expenses' : 'transactions');
    $filter = ['user_id' => $userId, 'deleted_at' => null];
    if (!empty($_GET['from'])) $filter['date']['$gte'] = phpDateToMongo($_GET['from']);
    if (!empty($_GET['to'])) $filter['date']['$lte'] = phpDateToMongo($_GET['to'] . ' 23:59:59');
    $docs = $collection->find($filter, ['sort' => ['date' => -1]])->toArray();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $type . '_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Type', 'Category', 'Amount', 'Description', 'Status']);
    foreach ($docs as $d) {
        fputcsv($out, [mongoDateToPHP($d['date'])->format('Y-m-d'), $d['type'] ?? '', $d['category'] ?? '', $d['amount'] ?? 0, $d['description'] ?? '', $d['status'] ?? 'completed']);
    }
    fclose($out);
    exit;
}

function exportPDF() {
    requireActiveSession();
    $type = $_GET['type'] ?? 'transactions';
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $collection = getCollection($type === 'expenses' ? 'expenses' : 'transactions');
    $filter = ['user_id' => $userId, 'deleted_at' => null];
    if (!empty($_GET['from'])) $filter['date']['$gte'] = phpDateToMongo($_GET['from']);
    if (!empty($_GET['to'])) $filter['date']['$lte'] = phpDateToMongo($_GET['to'] . ' 23:59:59');
    $docs = $collection->find($filter, ['sort' => ['date' => -1]])->toArray();
    $html = '<html><head><style>body{font-family:Arial,sans-serif}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f4f4f4}</style></head><body>';
    $html .= '<h2>' . ucfirst($type) . ' Report</h2><p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '<table><tr><th>Date</th><th>Type</th><th>Category</th><th>Amount</th><th>Description</th><th>Status</th></tr>';
    foreach ($docs as $d) {
        $html .= '<tr><td>' . mongoDateToPHP($d['date'])->format('Y-m-d') . '</td><td>' . ($d['type'] ?? '') . '</td><td>' . ($d['category'] ?? '') . '</td><td>' . ($d['amount'] ?? 0) . '</td><td>' . ($d['description'] ?? '') . '</td><td>' . ($d['status'] ?? 'completed') . '</td></tr>';
    }
    $html .= '</table></body></html>';
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Y-m-d') . '.html"');
    echo $html;
    exit;
}

function getAdminMonthlySummary() {
    requireRole(['admin']);
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('m'));
    $tx = getCollection('transactions');
    $start = phpDateToMongo(sprintf('%04d-%02d-01', $year, $month));
    $end = phpDateToMongo(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year)));
    $filter = ['date' => ['$gte' => $start, '$lte' => $end], 'deleted_at' => null];
    $income = $tx->aggregate([['$match' => array_merge($filter, ['type' => 'income'])], ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]])->toArray();
    $expense = $tx->aggregate([['$match' => array_merge($filter, ['type' => 'expense'])], ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]])->toArray();
    $totalIncome = $income[0]['total'] ?? 0;
    $totalExpense = $expense[0]['total'] ?? 0;
    $daily = $tx->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$date']], 'income' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'income']], '$amount', 0]]], 'expense' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'expense']], '$amount', 0]]]]],
        ['$sort' => ['_id' => 1]]
    ])->toArray();
    successResponse([
        'year' => $year,
        'month' => $month,
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'net' => $totalIncome - $totalExpense,
        'daily' => $daily
    ]);
}

function getAdminYearlySummary() {
    requireRole(['admin']);
    $year = (int)($_GET['year'] ?? date('Y'));
    $tx = getCollection('transactions');
    $start = phpDateToMongo(sprintf('%04d-01-01', $year));
    $end = phpDateToMongo(sprintf('%04d-12-31 23:59:59', $year));
    $filter = ['date' => ['$gte' => $start, '$lte' => $end], 'deleted_at' => null];
    $monthly = $tx->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => ['$month' => '$date'], 'income' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'income']], '$amount', 0]]], 'expense' => ['$sum' => ['$cond' => [['$eq' => ['$type', 'expense']], '$amount', 0]]]]],
        ['$sort' => ['_id' => 1]]
    ])->toArray();
    $months = [];
    for ($i = 1; $i <= 12; $i++) {
        $found = null;
        foreach ($monthly as $m) { if ($m['_id'] == $i) { $found = $m; break; } }
        $months[] = ['month' => $i, 'income' => $found['income'] ?? 0, 'expense' => $found['expense'] ?? 0];
    }
    $totalIncome = array_sum(array_column($months, 'income'));
    $totalExpense = array_sum(array_column($months, 'expense'));
    successResponse(['year' => $year, 'months' => $months, 'total_income' => $totalIncome, 'total_expense' => $totalExpense, 'net' => $totalIncome - $totalExpense]);
}

function getAdminCategoryBreakdown() {
    requireRole(['admin']);
    $type = $_GET['type'] ?? 'expense';
    $period = $_GET['period'] ?? 'month';
    $tx = getCollection('transactions');
    $filter = ['type' => $type, 'deleted_at' => null];
    if ($period === 'month') {
        $filter['date'] = ['$gte' => phpDateToMongo(date('Y-m-01')), '$lte' => phpDateToMongo(date('Y-m-t') . ' 23:59:59')];
    } elseif ($period === 'year') {
        $filter['date'] = ['$gte' => phpDateToMongo(date('Y-01-01')), '$lte' => phpDateToMongo(date('Y-12-31') . ' 23:59:59')];
    }
    $result = $tx->aggregate([
        ['$match' => $filter],
        ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]],
        ['$sort' => ['total' => -1]]
    ])->toArray();
    successResponse(['categories' => $result]);
}

function adminExportCSV() {
    requireRole(['admin']);
    $type = $_GET['type'] ?? 'transactions';
    $collection = getCollection($type === 'expenses' ? 'expenses' : 'transactions');
    $filter = ['deleted_at' => null];
    if (!empty($_GET['from'])) $filter['date']['$gte'] = phpDateToMongo($_GET['from']);
    if (!empty($_GET['to'])) $filter['date']['$lte'] = phpDateToMongo($_GET['to'] . ' 23:59:59');
    $docs = $collection->find($filter, ['sort' => ['date' => -1]])->toArray();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $type . '_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Type', 'Category', 'Amount', 'Description', 'Status']);
    foreach ($docs as $d) {
        fputcsv($out, [mongoDateToPHP($d['date'])->format('Y-m-d'), $d['type'] ?? '', $d['category'] ?? '', $d['amount'] ?? 0, $d['description'] ?? '', $d['status'] ?? 'completed']);
    }
    fclose($out);
    exit;
}

function adminExportPDF() {
    requireRole(['admin']);
    $type = $_GET['type'] ?? 'transactions';
    $collection = getCollection($type === 'expenses' ? 'expenses' : 'transactions');
    $filter = ['deleted_at' => null];
    if (!empty($_GET['from'])) $filter['date']['$gte'] = phpDateToMongo($_GET['from']);
    if (!empty($_GET['to'])) $filter['date']['$lte'] = phpDateToMongo($_GET['to'] . ' 23:59:59');
    $docs = $collection->find($filter, ['sort' => ['date' => -1]])->toArray();
    $html = '<html><head><style>body{font-family:Arial,sans-serif}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f4f4f4}</style></head><body>';
    $html .= '<h2>' . ucfirst($type) . ' Report</h2><p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '<table><tr><th>Date</th><th>Type</th><th>Category</th><th>Amount</th><th>Description</th><th>Status</th></tr>';
    foreach ($docs as $d) {
        $html .= '<tr><td>' . mongoDateToPHP($d['date'])->format('Y-m-d') . '</td><td>' . ($d['type'] ?? '') . '</td><td>' . ($d['category'] ?? '') . '</td><td>' . ($d['amount'] ?? 0) . '</td><td>' . ($d['description'] ?? '') . '</td><td>' . ($d['status'] ?? 'completed') . '</td></tr>';
    }
    $html .= '</table></body></html>';
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Y-m-d') . '.html"');
    echo $html;
    exit;
}
