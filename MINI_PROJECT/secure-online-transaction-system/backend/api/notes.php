<?php
declare(strict_types=1);
// Notes API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createNote(); break;
    case 'get_all': $method === 'GET' && getNotes(); break;
    case 'get': $method === 'GET' && getNote(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateNote(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteNote(); break;
    case 'summary': $method === 'GET' && getNotesSummary(); break;
    default: errorResponse('Invalid action', 404);
}

function createNote() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $title = sanitizeInput($data['title'] ?? '');
    $content = sanitizeInput($data['content'] ?? '');
    $visibility = sanitizeInput($data['visibility'] ?? 'PRIVATE');
    $tags = sanitizeInput($data['tags'] ?? '');
    $reminderDate = $data['reminder_date'] ?? null;
    if (empty($title)) errorResponse('Title is required');
    if (empty($content)) errorResponse('Content is required');
    $validVisibility = ['PRIVATE', 'SHARED', 'PINNED'];
    if (!in_array($visibility, $validVisibility, true)) errorResponse('Invalid visibility');
    if ($reminderDate && !validateDate($reminderDate)) errorResponse('Invalid reminder date');
    $collection = getCollection('notes');
    if (!$collection) errorResponse('Database connection error');
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'title' => $title,
        'content' => $content,
        'visibility' => $visibility,
        'tags' => $tags,
        'reminder_date' => $reminderDate ? phpDateToMongo($reminderDate) : null,
        'is_pinned' => $visibility === 'PINNED',
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create note');
    $noteId = (string)$result->getInsertedId();
    logActivity('note_created', getCurrentUserId(), ['note_id' => $noteId, 'title' => $title]);
    successResponse(['note_id' => $noteId], 'Note created successfully');
}

function getNotes() {
    requireActiveSession();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    if (!empty($_GET['visibility'])) $filter['visibility'] = sanitizeInput($_GET['visibility']);
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [
            ['title' => new MongoDB\BSON\Regex($search, 'i')],
            ['content' => new MongoDB\BSON\Regex($search, 'i')],
            ['tags' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $sortOptions = [
        'newest' => ['created_at' => -1],
        'oldest' => ['created_at' => 1],
        'title' => ['title' => 1]
    ];
    $sort = $sortOptions[$_GET['sort'] ?? 'date_desc'] ?? $sortOptions['date_desc'];
    $collection = getCollection('notes');
    if (!$collection) errorResponse('Database connection error');
    $total = $collection->countDocuments($filter);
    $notes = $collection->find($filter, ['sort' => $sort, 'skip' => $skip, 'limit' => $limit])->toArray();
    $formatted = array_map(function($n) {
        return [
            '_id' => (string)$n['_id'],
            'title' => $n['title'],
            'content' => $n['content'],
            'visibility' => $n['visibility'] ?? 'PRIVATE',
            'tags' => $n['tags'] ?? '',
            'reminder_date' => isset($n['reminder_date']) ? mongoDateToPHP($n['reminder_date'])->format('Y-m-d') : null,
            'is_pinned' => (bool)($n['is_pinned'] ?? false),
            'created_at' => mongoDateToPHP($n['created_at'])->format('Y-m-d H:i:s'),
            'updated_at' => mongoDateToPHP($n['updated_at'])->format('Y-m-d H:i:s')
        ];
    }, $notes);
    successResponse([
        'notes' => $formatted,
        'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]
    ]);
}

function getNote() {
    requireActiveSession();
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid note ID');
    $collection = getCollection('notes');
    $n = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$n) errorResponse('Note not found');
    successResponse([
        '_id' => (string)$n['_id'],
        'title' => $n['title'],
        'content' => $n['content'],
        'visibility' => $n['visibility'] ?? 'PRIVATE',
        'tags' => $n['tags'] ?? '',
        'reminder_date' => isset($n['reminder_date']) ? mongoDateToPHP($n['reminder_date'])->format('Y-m-d') : null,
        'is_pinned' => (bool)($n['is_pinned'] ?? false)
    ]);
}

function updateNote() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid note ID');
    $collection = getCollection('notes');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$existing) errorResponse('Note not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['title'])) {
        if (empty($data['title'])) errorResponse('Title is required');
        $updateData['title'] = sanitizeInput($data['title']);
    }
    if (isset($data['content'])) {
        if (empty($data['content'])) errorResponse('Content is required');
        $updateData['content'] = sanitizeInput($data['content']);
    }
    if (isset($data['visibility'])) {
        if (!in_array($data['visibility'], ['PRIVATE', 'SHARED', 'PINNED'], true)) errorResponse('Invalid visibility');
        $updateData['visibility'] = $data['visibility'];
        $updateData['is_pinned'] = $data['visibility'] === 'PINNED';
    }
    if (isset($data['tags'])) $updateData['tags'] = sanitizeInput($data['tags']);
    if (array_key_exists('reminder_date', $data)) {
        if ($data['reminder_date'] && !validateDate($data['reminder_date'])) errorResponse('Invalid reminder date');
        $updateData['reminder_date'] = $data['reminder_date'] ? phpDateToMongo($data['reminder_date']) : null;
    }
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('note_updated', getCurrentUserId(), ['note_id' => $id]);
    successResponse(['note_id' => $id, 'updated' => true], 'Note updated successfully');
}

function deleteNote() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid note ID');
    $collection = getCollection('notes');
    $n = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$n) errorResponse('Note not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]);
    logActivity('note_deleted', getCurrentUserId(), ['note_id' => $id]);
    successResponse(null, 'Note deleted successfully');
}

function getNotesSummary() {
    requireActiveSession();
    $collection = getCollection('notes');
    if (!$collection) errorResponse('Database connection error');
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    $total = $collection->countDocuments($filter);
    $pinned = $collection->countDocuments(array_merge($filter, ['is_pinned' => true]));
    $private = $collection->countDocuments(array_merge($filter, ['visibility' => 'PRIVATE']));
    $shared = $collection->countDocuments(array_merge($filter, ['visibility' => 'SHARED']));
    successResponse([
        'total' => $total,
        'pinned' => $pinned,
        'private' => $private,
        'shared' => $shared
    ]);
}