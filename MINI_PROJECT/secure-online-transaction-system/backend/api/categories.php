<?php
declare(strict_types=1);
// Categories API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createCategory(); break;
    case 'get_all': $method === 'GET' && getCategories(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateCategory(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteCategory(); break;
    default: errorResponse('Invalid action', 404);
}

function createCategory() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $name = sanitizeInput($data['name'] ?? '');
    $type = sanitizeInput($data['type'] ?? 'expense');
    $icon = sanitizeInput($data['icon'] ?? 'fa-tag');
    $color = sanitizeInput($data['color'] ?? '#6c757d');
    if (empty($name)) errorResponse('Category name is required');
    if (!in_array($type, ['income', 'expense'])) errorResponse('Invalid category type');
    $collection = getCollection('categories');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne(['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'name' => $name, 'type' => $type, 'deleted_at' => null]);
    if ($existing) errorResponse('Category already exists');
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'name' => $name,
        'type' => $type,
        'icon' => $icon,
        'color' => $color,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create category');
    successResponse(['category_id' => (string)$result->getInsertedId()], 'Category created successfully');
}

function getCategories() {
    requireActiveSession();
    $type = $_GET['type'] ?? null;
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    if ($type && in_array($type, ['income', 'expense'])) $filter['type'] = $type;
    $collection = getCollection('categories');
    $categories = $collection->find($filter, ['sort' => ['name' => 1]])->toArray();
    $formatted = array_map(function($c) {
        return ['_id' => (string)$c['_id'], 'name' => $c['name'], 'type' => $c['type'], 'icon' => $c['icon'] ?? 'fa-tag', 'color' => $c['color'] ?? '#6c757d'];
    }, $categories);
    successResponse(['categories' => $formatted]);
}

function updateCategory() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid category ID');
    $collection = getCollection('categories');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$existing) errorResponse('Category not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['name'])) $updateData['name'] = sanitizeInput($data['name']);
    if (isset($data['icon'])) $updateData['icon'] = sanitizeInput($data['icon']);
    if (isset($data['color'])) $updateData['color'] = sanitizeInput($data['color']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    successResponse(['category_id' => $id, 'updated' => true], 'Category updated successfully');
}

function deleteCategory() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid category ID');
    $collection = getCollection('categories');
    $c = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$c) errorResponse('Category not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]);
    successResponse(null, 'Category deleted successfully');
}