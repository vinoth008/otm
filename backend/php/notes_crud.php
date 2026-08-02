<?php
// backend/php/notes_crud.php
/**
 * Notes Management for Smart Transaction Control
 * Handles personal and task notes
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
 * Get all notes for current user
 * GET
 */
function getNotes() {
    requireActiveSession();
    $collection = getCollection('notes');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $cursor = $collection->find(
        ['user_id' => $userId, 'deleted_at' => null],
        ['sort' => ['created_at' => -1]]
    );
    $list = [];
    foreach ($cursor as $n) {
        $list[] = [
            'note_id' => (string)$n['_id'],
            'title' => $n['title'] ?? '',
            'content' => $n['content'] ?? '',
            'category' => $n['category'] ?? 'general',
            'color' => $n['color'] ?? 'default',
            'pinned' => (bool)($n['pinned'] ?? false),
            'created_at' => mongoDateToPHP($n['created_at'] ?? null)->format('Y-m-d H:i:s'),
            'updated_at' => mongoDateToPHP($n['updated_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['notes' => $list], 'Notes retrieved');
}
/**
 * Create a note
 * POST: title, content, category, color, pinned
 */
function createNote() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $title = sanitizeInput($data['title'] ?? '');
    $content = sanitizeInput($data['content'] ?? '');
    $category = sanitizeInput($data['category'] ?? 'general');
    $color = sanitizeInput($data['color'] ?? 'default');
    $pinned = !empty($data['pinned']);
    if (empty($title) && empty($content)) {
        errorResponse('Note title or content is required');
    }
    $collection = getCollection('notes');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->insertOne([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'title' => $title,
        'content' => $content,
        'category' => $category,
        'color' => $color,
        'pinned' => $pinned,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('note_created', getCurrentUserId(), ['note_id' => (string)$result->getInsertedId()]);
    successResponse(['note_id' => (string)$result->getInsertedId()], 'Note saved successfully');
}
/**
 * Update a note
 * POST: note_id, title, content, category, color, pinned
 */
function updateNote() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $noteId = $data['note_id'] ?? '';
    if (!isValidObjectId($noteId)) {
        errorResponse('Invalid note ID');
    }
    $collection = getCollection('notes');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $note = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($noteId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$note) {
        errorResponse('Note not found');
    }
    $update = ['updated_at' => phpDateToMongo()];
    foreach (['title', 'content', 'category', 'color'] as $field) {
        if (array_key_exists($field, $data)) {
            $update[$field] = sanitizeInput($data[$field]);
        }
    }
    if (array_key_exists('pinned', $data)) {
        $update['pinned'] = !empty($data['pinned']);
    }
    $collection->updateOne(['_id' => $note['_id']], ['$set' => $update]);
    logActivity('note_updated', getCurrentUserId(), ['note_id' => $noteId]);
    successResponse(null, 'Note updated successfully');
}
/**
 * Delete a note
 * POST: note_id
 */
function deleteNote() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $noteId = $data['note_id'] ?? '';
    if (!isValidObjectId($noteId)) {
        errorResponse('Invalid note ID');
    }
    $collection = getCollection('notes');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->updateOne(
        [
            '_id' => new MongoDB\BSON\ObjectId($noteId),
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
        ],
        ['$set' => ['deleted_at' => phpDateToMongo()]]
    );
    if ($result->getModifiedCount() === 0 && $result->getMatchedCount() === 0) {
        errorResponse('Note not found');
    }
    logActivity('note_deleted', getCurrentUserId(), ['note_id' => $noteId]);
    successResponse(null, 'Note deleted successfully');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getNotes();
        break;
    case 'create':
        if ($method === 'POST') createNote();
        break;
    case 'update':
        if ($method === 'POST') updateNote();
        break;
    case 'delete':
        if ($method === 'POST') deleteNote();
        break;
    default:
        errorResponse('Invalid action');
}
?>
