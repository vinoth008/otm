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

$subject = sanitizeInput($data['subject'] ?? '');
$description = sanitizeInput($data['description'] ?? '');
$category = sanitizeInput($data['category'] ?? 'General');
$priority = sanitizeInput($data['priority'] ?? 'Medium');
$status = sanitizeInput($data['status'] ?? 'open');

if (empty($subject) || empty($description)) {
    errorResponse('Subject and description are required');
}

$validPriorities = ['Low', 'Medium', 'High', 'Critical'];
if (!in_array($priority, $validPriorities, true)) {
    errorResponse('Invalid priority');
}

$validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
if (!in_array($status, $validStatuses, true)) {
    $status = 'open';
}

$col = getCollection('complaints');
if (!$col) {
    errorResponse('Database connection error');
}

$userId = getCurrentUserId();
$user = getCollection('users')->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
$customerName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

$ticketNo = 'CMP' . str_pad((string)($col->countDocuments() + 1), 4, '0', STR_PAD_LEFT);

$doc = [
    'ticket_no' => $ticketNo,
    'user_id' => new MongoDB\BSON\ObjectId($userId),
    'customer_name' => $customerName,
    'subject' => $subject,
    'description' => $description,
    'category' => $category,
    'priority' => $priority,
    'status' => $status,
    'staff_reply' => '',
    'assigned_to' => null,
    'created_at' => phpDateToMongo(),
    'updated_at' => phpDateToMongo(),
    'deleted_at' => null
];

$result = $col->insertOne($doc);
if (!$result->getInsertedId()) {
    errorResponse('Failed to create complaint');
}

logActivity('complaint_created', getCurrentUserId(), ['ticket_no' => $ticketNo]);

successResponse([
    'complaint_id' => (string)$result->getInsertedId(),
    'ticket_no' => $ticketNo
], 'Complaint submitted successfully');
