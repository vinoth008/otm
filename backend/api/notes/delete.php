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

$col->updateOne(
    ['_id' => new MongoDB\BSON\ObjectId($noteId)],
    [
        '$set' => [
            'deleted_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]
    ]
);

successResponse(null, 'Note deleted successfully');
