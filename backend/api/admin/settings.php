<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireRole(['admin']);

$data = getRequestData();
$action = $data['action'] ?? ($_GET['action'] ?? '');

$collection = getCollection('settings');
if (!$collection) {
    errorResponse('Database connection error');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'get_all') {
    $cursor = $collection->find([], ['sort' => ['created_at' => 1]]);
    $settings = [];
    foreach ($cursor as $s) {
        $settings[$s['key'] ?? ''] = $s['value'] ?? '';
    }
    successResponse(['settings' => $settings], 'Settings retrieved');
}

if ($action === 'update') {
    $settingsInput = $data['settings'] ?? [];
    if (!is_array($settingsInput) || empty($settingsInput)) {
        errorResponse('No settings provided');
    }
    foreach ($settingsInput as $key => $value) {
        $key = sanitizeInput($key);
        if ($key === '') {
            continue;
        }
        $collection->updateOne(
            ['key' => $key],
            [
                '$set' => [
                    'value' => (string)$value,
                    'updated_at' => phpDateToMongo()
                ]
            ],
            ['upsert' => true]
        );
    }
    logActivity('settings_updated', getCurrentUserId(), ['count' => count($settingsInput)]);
    successResponse(null, 'Settings updated successfully');
}

errorResponse('Invalid action', 400);
