<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$col = getCollection('settings');
$settings = [];
if ($col) {
    $items = $col->find(['deleted_at' => null]);
    foreach ($items as $item) {
        $settings[$item['key']] = $item['value'];
    }
}

$userId = getCurrentUserId();
$themePreference = 'light';
$users = getCollection('users');
if ($users) {
    $user = $users->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
    if ($user) {
        $themePreference = $user['theme_preference'] ?? 'light';
    }
}

$settings['theme_preference'] = $themePreference;

successResponse(['settings' => $settings], 'Settings retrieved');
