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

$col = getCollection('settings');
if (!$col) {
    errorResponse('Database connection error');
}

$userId = getCurrentUserId();

if (isset($data['key']) && isset($data['value'])) {
    $key = sanitizeInput($data['key']);
    $value = $data['value'];

    $col->updateOne(
        ['key' => $key],
        [
            '$set' => [
                'key' => $key,
                'value' => $value,
                'updated_at' => phpDateToMongo()
            ]
        ],
        ['upsert' => true]
    );
}

$pairsToSet = [];
foreach ($data as $k => $v) {
    if (in_array($k, ['key', 'value'], true)) {
        continue;
    }
    if ($k === 'theme_preference') {
        $users = getCollection('users');
        if ($users) {
            $users->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$set' => ['theme_preference' => sanitizeInput($v), 'updated_at' => phpDateToMongo()]]
            );
        }
        continue;
    }
    $pairsToSet[sanitizeInput($k)] = $v;
}

foreach ($pairsToSet as $k => $v) {
    $col->updateOne(
        ['key' => $k],
        [
            '$set' => [
                'key' => $k,
                'value' => $v,
                'updated_at' => phpDateToMongo()
            ]
        ],
        ['upsert' => true]
    );
}

logActivity('settings_updated', getCurrentUserId());

successResponse(null, 'Settings updated');
