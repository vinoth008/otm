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

$col = getCollection('notifications');
if (!$col) {
    errorResponse('Database connection error');
}

$userId = getCurrentUserId();
$filter = [
    'user_id' => new MongoDB\BSON\ObjectId($userId),
    'is_read' => false
];

if (($data['notification_id'] ?? '') === 'all') {
    $col->updateMany($filter, ['$set' => ['is_read' => true]]);
    successResponse(null, 'All notifications marked as read');
}

$notificationId = $data['notification_id'] ?? '';
if (!isValidObjectId($notificationId)) {
    errorResponse('Invalid notification ID');
}

$col->updateOne([
    '_id' => new MongoDB\BSON\ObjectId($notificationId),
    'user_id' => new MongoDB\BSON\ObjectId($userId)
], ['$set' => ['is_read' => true]]);

successResponse(null, 'Notification marked as read');
