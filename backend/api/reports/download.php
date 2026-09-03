<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$fromDate = sanitizeInput($_GET['from_date'] ?? date('Y-m-01'));
$toDate = sanitizeInput($_GET['to_date'] ?? date('Y-m-t'));
$format = sanitizeInput($_GET['format'] ?? 'json');

if (!validateDate($fromDate) || !validateDate($toDate)) {
    errorResponse('Invalid date format');
}

$role = getCurrentUserRole();
$userId = getCurrentUserId();
$col = getCollection('transactions');
if (!$col) {
    errorResponse('Database connection error');
}

$filter = [
    'deleted_at' => null,
    'date' => [
        '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($fromDate . ' 00:00:00') * 1000),
        '$lte' => new MongoDB\BSON\UTCDateTime(strtotime($toDate . ' 23:59:59') * 1000)
    ]
];

if (!in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $filter['user_id'] = new MongoDB\BSON\ObjectId($userId);
}

$cursor = $col->find($filter, ['sort' => ['date' => -1]]);

$rows = [];
foreach ($cursor as $t) {
    $rows[] = [
        'id' => (string)$t['_id'],
        'type' => $t['type'] ?? '',
        'category' => $t['category'] ?? '',
        'amount' => (float)($t['amount'] ?? 0),
        'description' => $t['description'] ?? '',
        'date' => isset($t['date']) ? mongoDateToPHP($t['date'])->format('Y-m-d') : '',
        'payment_method' => $t['payment_method'] ?? '',
        'notes' => $t['notes'] ?? '',
        'created_at' => isset($t['created_at']) ? mongoDateToPHP($t['created_at'])->format('Y-m-d H:i:s') : ''
    ];
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transactions_' . $fromDate . '_to_' . $toDate . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Type', 'Category', 'Amount', 'Description', 'Date', 'Payment Method', 'Notes', 'Created At']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'], $row['type'], $row['category'], $row['amount'],
            $row['description'], $row['date'], $row['payment_method'],
            $row['notes'], $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

successResponse([
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'transactions' => $rows,
    'total_count' => count($rows)
], 'Transactions exported');
