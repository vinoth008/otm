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
$content = sanitizeInput($data['content'] ?? '');
$color = sanitizeInput($data['color'] ?? '#ffffff');
$pinned = (bool)($data['pinned'] ?? false);

if (empty($title)) {
    errorResponse('Title is required');
}

$col = getCollection('notes');
if (!$col) {
    errorResponse('Database connection error');
}

$doc = [
    'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
    'title' => $title,
    'content' => $content,
    'color' => $color,
    'pinned' => $pinned,
    'created_at' => phpDateToMongo(),
    'updated_at' => phpDateToMongo(),
    'deleted_at' => null
];

$result = $col->insertOne($doc);
if (!$result->getInsertedId()) {
    errorResponse('Failed to create note');
}

logActivity('note_created', getCurrentUserId(), ['note_id' => (string)$result->getInsertedId()]);

successResponse([
    'note_id' => (string)$result->getInsertedId()
], 'Note created successfully');
