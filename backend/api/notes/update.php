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

$noteId = $data['note_id'] ?? '';
if (!isValidObjectId($noteId)) {
    errorResponse('Invalid note ID');
}

$col = getCollection('notes');
if (!$col) {
    errorResponse('Database connection error');
}

$note = $col->findOne([
    '_id' => new MongoDB\BSON\ObjectId($noteId),
    'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
    'deleted_at' => null
]);

if (!$note) {
    errorResponse('Note not found');
}

$updateData = ['updated_at' => phpDateToMongo()];

if (isset($data['title'])) {
    $updateData['title'] = sanitizeInput($data['title']);
}

if (isset($data['content'])) {
    $updateData['content'] = sanitizeInput($data['content']);
}

if (isset($data['color'])) {
    $updateData['color'] = sanitizeInput($data['color']);
}

if (isset($data['pinned'])) {
    $updateData['pinned'] = (bool)$data['pinned'];
}

$col->updateOne(
    ['_id' => new MongoDB\BSON\ObjectId($noteId)],
    ['$set' => $updateData]
);

successResponse(['note_id' => $noteId, 'updated' => true], 'Note updated successfully');
