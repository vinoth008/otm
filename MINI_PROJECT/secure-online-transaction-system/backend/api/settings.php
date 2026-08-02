<?php
declare(strict_types=1);
// Settings API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get': $method === 'GET' && getSettings(); break;
    case 'update': $method === 'POST' && updateSettings(); break;
    case 'update_theme': $method === 'POST' && updateTheme(); break;
    case 'update_notifications': $method === 'POST' && updateNotificationPrefs(); break;
    default: errorResponse('Invalid action', 404);
}

function getSettings() {
    requireActiveSession();
    $collection = getCollection('users');
    $u = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())]);
    if (!$u) errorResponse('User not found');
    successResponse([
        'currency' => $u['currency'] ?? 'INR',
        'theme_preference' => $u['theme_preference'] ?? 'light',
        'notification_prefs' => $u['notification_prefs'] ?? ['budget_alerts' => true, 'transaction_alerts' => true, 'security_alerts' => true, 'admin_announcements' => true],
        'language' => $u['language'] ?? 'en',
        'timezone' => $u['timezone'] ?? 'Asia/Kolkata'
    ]);
}

function updateSettings() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $collection = getCollection('users');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['currency'])) $updateData['currency'] = sanitizeInput($data['currency']);
    if (isset($data['language'])) $updateData['language'] = sanitizeInput($data['language']);
    if (isset($data['timezone'])) $updateData['timezone'] = sanitizeInput($data['timezone']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['$set' => $updateData]);
    logActivity('settings_updated', getCurrentUserId());
    successResponse(null, 'Settings updated successfully');
}

function updateTheme() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $theme = sanitizeInput($data['theme'] ?? 'light');
    if (!in_array($theme, ['light', 'dark'])) errorResponse('Invalid theme');
    $collection = getCollection('users');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['$set' => ['theme_preference' => $theme, 'updated_at' => phpDateToMongo()]]);
    successResponse(['theme' => $theme], 'Theme updated');
}

function updateNotificationPrefs() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $prefs = [
        'budget_alerts' => (bool)($data['budget_alerts'] ?? true),
        'transaction_alerts' => (bool)($data['transaction_alerts'] ?? true),
        'security_alerts' => (bool)($data['security_alerts'] ?? true),
        'admin_announcements' => (bool)($data['admin_announcements'] ?? true)
    ];
    $collection = getCollection('users');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['$set' => ['notification_prefs' => $prefs, 'updated_at' => phpDateToMongo()]]);
    successResponse(['notification_prefs' => $prefs], 'Notification preferences updated');
}