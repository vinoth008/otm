<?php
// backend/php/notification_crud.php
/**
 * Notification Management for Smart Transaction Control
 * Handles notifications for customers, staff, and admin
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
 * Get notifications for current user
 * GET
 */
function getNotifications() {
    requireActiveSession();
    $collection = getCollection('notifications');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $cursor = $collection->find(
        ['user_id' => $userId],
        ['sort' => ['created_at' => -1], 'limit' => 50]
    );
    $list = [];
    $unread = 0;
    foreach ($cursor as $n) {
        if (!($n['read'] ?? false)) {
            $unread++;
        }
        $list[] = [
            'notification_id' => (string)$n['_id'],
            'title' => $n['title'] ?? '',
            'message' => $n['message'] ?? '',
            'type' => $n['type'] ?? 'general',
            'read' => (bool)($n['read'] ?? false),
            'link' => $n['link'] ?? '',
            'created_at' => mongoDateToPHP($n['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse([
        'notifications' => $list,
        'unread_count' => $unread
    ], 'Notifications retrieved');
}
/**
 * Mark notification(s) as read
 * POST: notification_id (optional - empty marks all as read)
 */
function markNotificationsRead() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $collection = getCollection('notifications');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $notificationId = $data['notification_id'] ?? '';
    if (!empty($notificationId) && isValidObjectId($notificationId)) {
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($notificationId), 'user_id' => $userId],
            ['$set' => ['read' => true]]
        );
    } else {
        $collection->updateMany(
            ['user_id' => $userId, 'read' => false],
            ['$set' => ['read' => true]]
        );
    }
    successResponse(null, 'Notifications marked as read');
}
/**
 * Admin: broadcast notification to all users
 * POST: title, message, type, user_id (optional target)
 */
function sendNotification() {
    requireRole(['admin', 'staff']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $title = sanitizeInput($data['title'] ?? '');
    $message = sanitizeInput($data['message'] ?? '');
    $type = sanitizeInput($data['type'] ?? 'general');
    if (empty($title) || empty($message)) {
        errorResponse('Title and message are required');
    }
    $collection = getCollection('notifications');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $users = getCollection('users');
    if (!$users) {
        errorResponse('Database connection error');
    }
    $targetUserId = $data['user_id'] ?? '';
    $recipientFilter = ['status' => ['$ne' => 'deleted']];
    if (!empty($targetUserId) && isValidObjectId($targetUserId)) {
        $recipientFilter = ['_id' => new MongoDB\BSON\ObjectId($targetUserId)];
    }
    $cursor = $users->find($recipientFilter, ['projection' => ['_id' => 1]]);
    $sent = 0;
    foreach ($cursor as $user) {
        $collection->insertOne([
            'user_id' => $user['_id'],
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'read' => false,
            'link' => sanitizeInput($data['link'] ?? ''),
            'created_at' => phpDateToMongo()
        ]);
        $sent++;
    }
    logActivity('notification_sent', getCurrentUserId(), [
        'title' => $title,
        'recipients' => $sent
    ]);
    successResponse(['recipients' => $sent], 'Notification sent to ' . $sent . ' user(s)');
}
/**
 * Admin: get unread notification stats
 * GET
 */
function getNotificationStats() {
    requireActiveSession();
    $collection = getCollection('notifications');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $unread = $collection->countDocuments(['user_id' => $userId, 'read' => false]);
    successResponse(['unread_count' => $unread], 'Notification stats retrieved');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getNotifications();
        break;
    case 'mark_read':
        if ($method === 'POST') markNotificationsRead();
        break;
    case 'send':
        if ($method === 'POST') sendNotification();
        break;
    case 'stats':
        if ($method === 'GET') getNotificationStats();
        break;
    default:
        errorResponse('Invalid action');
}
?>
