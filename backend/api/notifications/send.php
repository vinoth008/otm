<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();
requireRole(['admin', 'staff']);

$data = getRequestData();
if (!$data) {
    errorResponse('Invalid request format');
}

$targetUserId = $data['user_id'] ?? '';
$type = sanitizeInput($data['type'] ?? 'info');
$title = sanitizeInput($data['title'] ?? '');
$message = sanitizeInput($data['message'] ?? '');
$link = sanitizeInput($data['link'] ?? '');

if (!isValidObjectId($targetUserId)) {
    errorResponse('Invalid target user ID');
}

if (empty($title) || empty($message)) {
    errorResponse('Title and message are required');
}

$col = getCollection('notifications');
if (!$col) {
    errorResponse('Database connection error');
}

$doc = [
    'user_id' => new MongoDB\BSON\ObjectId($targetUserId),
    'type' => $type,
    'title' => $title,
    'message' => $message,
    'is_read' => false,
    'link' => $link,
    'created_at' => phpDateToMongo(),
    'deleted_at' => null
];

$result = $col->insertOne($doc);
if (!$result->getInsertedId()) {
    errorResponse('Failed to send notification');
}

logActivity('notification_sent', getCurrentUserId(), ['target_user_id' => $targetUserId, 'type' => $type]);

successResponse([
    'notification_id' => (string)$result->getInsertedId()
], 'Notification sent successfully');
