<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$data = getRequestData();
if (!$data) {
    errorResponse('Invalid request format');
}

$title = sanitizeInput($data['title'] ?? '');
$category = sanitizeInput($data['category'] ?? '');
$amount = $data['amount'] ?? 0;
$date = $data['date'] ?? date('Y-m-d');
$description = sanitizeInput($data['description'] ?? '');

if (empty($title)) {
    errorResponse('Title is required');
}

if (!validateAmount($amount) || (float)$amount <= 0) {
    errorResponse('Amount must be greater than 0');
}

if (!validateDate($date)) {
    errorResponse('Invalid date format');
}

$col = getCollection('expenses');
if (!$col) {
    errorResponse('Database connection error');
}

$doc = [
    'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
    'title' => $title,
    'category' => $category,
    'amount' => (float)$amount,
    'date' => new MongoDB\BSON\UTCDateTime(strtotime($date) * 1000),
    'description' => $description,
    'created_at' => phpDateToMongo(),
    'deleted_at' => null
];

$result = $col->insertOne($doc);
if (!$result->getInsertedId()) {
    errorResponse('Failed to create expense');
}

logActivity('expense_created', getCurrentUserId(), ['expense_id' => (string)$result->getInsertedId(), 'amount' => (float)$amount]);

successResponse([
    'expense_id' => (string)$result->getInsertedId()
], 'Expense created successfully');
