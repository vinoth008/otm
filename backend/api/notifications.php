<?php
declare(strict_types=1);
// Notifications API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all': $method === 'GET' && getNotifications(); break;
    case 'mark_read': $method === 'POST' && markNotificationRead(); break;
    case 'mark_all_read': $method === 'POST' && markAllNotificationsRead(); break;
    case 'delete': $method === 'POST' && deleteNotification(); break;
    case 'unread_count': $method === 'GET' && getUnreadCount(); break;
    case 'admin_all': $method === 'GET' && getAdminNotifications(); break;
    case 'admin_mark_read': $method === 'POST' && adminMarkNotificationRead(); break;
    case 'admin_mark_all_read': $method === 'POST' && adminMarkAllNotificationsRead(); break;
    case 'admin_delete': $method === 'POST' && adminDeleteNotification(); break;
    case 'admin_unread_count': $method === 'GET' && getAdminUnreadCount(); break;
    default: errorResponse('Invalid action', 404);
}

function getNotifications() {
    requireActiveSession();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    $collection = getCollection('notifications');
    $total = $collection->countDocuments($filter);
    $notifications = $collection->find($filter, ['sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit])->toArray();
    $formatted = array_map(function($n) {
        return [
            '_id' => (string)$n['_id'],
            'type' => $n['type'] ?? 'info',
            'title' => $n['title'] ?? '',
            'message' => $n['message'] ?? '',
            'is_read' => $n['is_read'] ?? false,
            'created_at' => mongoDateToPHP($n['created_at'])->format('Y-m-d H:i:s')
        ];
    }, $notifications);
    successResponse(['notifications' => $formatted, 'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]]);
}

function markNotificationRead() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid notification ID');
    $collection = getCollection('notifications');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['$set' => ['is_read' => true]]);
    successResponse(null, 'Notification marked as read');
}

function markAllNotificationsRead() {
    requireActiveSession();
    $collection = getCollection('notifications');
    $collection->updateMany(['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'is_read' => false], ['$set' => ['is_read' => true]]);
    successResponse(null, 'All notifications marked as read');
}

function deleteNotification() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid notification ID');
    $collection = getCollection('notifications');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['$set' => ['deleted_at' => phpDateToMongo()]]);
    successResponse(null, 'Notification deleted');
}

function getUnreadCount() {
    requireActiveSession();
    $collection = getCollection('notifications');
    $count = $collection->countDocuments(['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'is_read' => false, 'deleted_at' => null]);
    successResponse(['unread_count' => $count]);
}

function getAdminNotifications() {
    requireRole(['admin']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['deleted_at' => null];
    $collection = getCollection('notifications');
    $users = getCollection('users');
    $total = $collection->countDocuments($filter);
    $notifications = $collection->find($filter, ['sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit])->toArray();
    $formatted = array_map(function($n) use ($users) {
        $owner = $users->findOne(['_id' => $n['user_id']]);
        $name = $owner ? $owner['first_name'] . ' ' . ($owner['last_name'] ?? '') : 'System';
        return [
            '_id' => (string)$n['_id'],
            'user_name' => trim($name),
            'type' => $n['type'] ?? 'info',
            'title' => $n['title'] ?? '',
            'message' => $n['message'] ?? '',
            'is_read' => $n['is_read'] ?? false,
            'created_at' => mongoDateToPHP($n['created_at'])->format('Y-m-d H:i:s')
        ];
    }, $notifications);
    successResponse(['notifications' => $formatted, 'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]]);
}

function adminMarkNotificationRead() {
    requireRole(['admin']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid notification ID');
    $collection = getCollection('notifications');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['is_read' => true]]);
    successResponse(null, 'Notification marked as read');
}

function adminMarkAllNotificationsRead() {
    requireRole(['admin']);
    $collection = getCollection('notifications');
    $collection->updateMany(['is_read' => false], ['$set' => ['is_read' => true]]);
    successResponse(null, 'All notifications marked as read');
}

function adminDeleteNotification() {
    requireRole(['admin']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid notification ID');
    $collection = getCollection('notifications');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['deleted_at' => phpDateToMongo()]]);
    successResponse(null, 'Notification deleted');
}

function getAdminUnreadCount() {
    requireRole(['admin']);
    $collection = getCollection('notifications');
    $count = $collection->countDocuments(['is_read' => false, 'deleted_at' => null]);
    successResponse(['unread_count' => $count]);
}
