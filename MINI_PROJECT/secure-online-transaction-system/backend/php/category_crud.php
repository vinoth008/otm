<?php
// backend/php/category_crud.php
/**
 * Category Management for Smart Transaction Control
 * Handles system categories and custom user categories
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
 * Get all categories (system + user custom)
 * GET: type (income|expense)
 */
function getCategories() {
    requireActiveSession();
    $type = $_GET['type'] ?? null;
    $userId = getCurrentUserId();
    $filter = [];
    // System categories
    $systemFilter = ['is_system' => true];
    if ($type) {
        $systemFilter['type'] = sanitizeInput($type);
    }
    // User custom categories
    $userFilter = ['user_id' => new MongoDB\BSON\ObjectId($userId)];
    if ($type) {
        $userFilter['type'] = sanitizeInput($type);
    }
    $collection = getCollection('categories');
    // Get system categories
    $systemCategories = $collection->find($systemFilter, [
        'sort' => ['name' => 1]
    ])->toArray();
    // Get user categories
    $userCategories = $collection->find($userFilter, [
        'sort' => ['name' => 1]
    ])->toArray();
    // Format and merge
    $formattedCategories = [];
    foreach ($systemCategories as $cat) {
        $formattedCategories[] = [
            '_id' => (string)$cat['_id'],
            'name' => $cat['name'],
            'type' => $cat['type'],
            'icon' => $cat['icon'] ?? 'tag',
            'color' => $cat['color'] ?? '#6b7280',
            'is_system' => true,
            'is_custom' => false,
            'is_favorite' => false
        ];
    }
    foreach ($userCategories as $cat) {
        $formattedCategories[] = [
            '_id' => (string)$cat['_id'],
            'name' => $cat['name'],
            'type' => $cat['type'],
            'icon' => $cat['icon'] ?? 'tag',
            'color' => $cat['color'] ?? '#6b7280',
            'is_system' => false,
            'is_custom' => true,
            'is_favorite' => $cat['is_favorite'] ?? false,
            'parent_category' => isset($cat['parent_category']) ? (string)$cat['parent_category'] : null,
        ];
    }
    successResponse(['categories' => $formattedCategories]);
}
/**
 * Create custom category
 * POST: name, type, icon, color, parent_category
 */
function createCategory() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $name = sanitizeInput($data['name'] ?? '');
    $type = sanitizeInput($data['type'] ?? 'expense');
    $icon = sanitizeInput($data['icon'] ?? 'tag');
    $color = sanitizeInput($data['color'] ?? '#6b7280');
    $parentCategory = $data['parent_category'] ?? null;
    if (empty($name)) {
        errorResponse('Category name is required');
    }
    if (!in_array($type, ['income', 'expense'])) {
        errorResponse('Invalid category type');
    }
    // Check for duplicate
    $collection = getCollection('categories');
    $existing = $collection->findOne([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'name' => $name,
        'type' => $type
    ]);
    if ($existing) {
        errorResponse('Category already exists');
    }
    $categoryDocument = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'name' => $name,
        'type' => $type,
        'icon' => $icon,
        'color' => $color,
        'parent_category' => $parentCategory ? new MongoDB\BSON\ObjectId($parentCategory) : null,
        'is_system' => false,
        'is_favorite' => false,
        'created_at' => phpDateToMongo()
    ];
    $result = $collection->insertOne($categoryDocument);
    logActivity('category_created', getCurrentUserId(), [
        'category_id' => (string)$result->getInsertedId(),
        'name' => $name,
        'type' => $type
    ]);
    successResponse([
        'category_id' => (string)$result->getInsertedId(),
        'name' => $name,
        'type' => $type
    ], 'Category created successfully');
}
/**
 * Update custom category
 * PUT/POST: id, name, icon, color
 */
function updateCategory() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $categoryId = $data['id'] ?? '';
    if (!isValidObjectId($categoryId)) {
        errorResponse('Invalid category ID');
    }
    $collection = getCollection('categories');
    $category = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($categoryId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
    ]);
    if (!$category) {
        errorResponse('Category not found');
    }
    if ($category['is_system']) {
        errorResponse('Cannot modify system categories');
    }
    $updateData = [];
    if (isset($data['name'])) {
        $name = sanitizeInput($data['name']);
        if (empty($name)) {
            errorResponse('Category name cannot be empty');
        }
        $updateData['name'] = $name;
    }
    if (isset($data['icon'])) {
        $updateData['icon'] = sanitizeInput($data['icon']);
    }
    if (isset($data['color'])) {
        $updateData['color'] = sanitizeInput($data['color']);
    }
    if (isset($data['is_favorite'])) {
        $updateData['is_favorite'] = (bool)$data['is_favorite'];
    }
    if (empty($updateData)) {
        errorResponse('No changes provided');
    }
    $updateData['updated_at'] = phpDateToMongo();
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($categoryId)],
        ['$set' => $updateData]
    );
    logActivity('category_updated', getCurrentUserId(), [
        'category_id' => $categoryId
    ]);
    successResponse(null, 'Category updated successfully');
}
/**
 * Delete custom category
 * DELETE: id
 */
function deleteCategory() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $categoryId = $data['id'] ?? '';
    if (!isValidObjectId($categoryId)) {
        errorResponse('Invalid category ID');
    }
    $collection = getCollection('categories');
    $category = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($categoryId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
    ]);
    if (!$category) {
        errorResponse('Category not found');
    }
    if ($category['is_system']) {
        errorResponse('Cannot delete system categories');
    }
    // Check if category is used in transactions
    $transactionsCollection = getCollection('transactions');
    $transactionCount = $transactionsCollection->countDocuments([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'category' => $category['name'],
        'deleted_at' => null
    ]);
    if ($transactionCount > 0) {
        errorResponse('Cannot delete category with existing transactions. Please reassign or delete those transactions first.');
    }
    $collection->deleteOne(['_id' => new MongoDB\BSON\ObjectId($categoryId)]);
    logActivity('category_deleted', getCurrentUserId(), [
        'category_id' => $categoryId,
        'name' => $category['name']
    ]);
    successResponse(null, 'Category deleted successfully');
}
/**
 * Get favorite categories
 */
function getFavoriteCategories() {
    requireActiveSession();
    $userId = getCurrentUserId();
    $collection = getCollection('categories');
    // Get user favorites
    $userFavorites = $collection->find([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'is_favorite' => true
    ], [
        'sort' => ['name' => 1]
    ])->toArray();
    // Get system favorites (if any - could be implemented)
    $systemFavorites = $collection->find([
        'is_system' => true,
        'is_favorite' => true
    ], [
        'sort' => ['name' => 1]
    ])->toArray();
    $formattedFavorites = [];
    foreach (array_merge($userFavorites, $systemFavorites) as $cat) {
        $formattedFavorites[] = [
            '_id' => (string)$cat['_id'],
            'name' => $cat['name'],
            'type' => $cat['type'],
            'icon' => $cat['icon'] ?? 'tag',
            'color' => $cat['color'] ?? '#6b7280',
            'is_system' => $cat['is_system'] ?? false,
            'is_custom' => !$cat['is_system']
        ];
    }
    successResponse(['favorites' => $formattedFavorites]);
}
/**
 * Toggle favorite status
 * POST: id
 */
function toggleFavorite() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $categoryId = $data['id'] ?? '';
    if (!isValidObjectId($categoryId)) {
        errorResponse('Invalid category ID');
    }
    $collection = getCollection('categories');
    $category = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($categoryId)]);
    if (!$category) {
        errorResponse('Category not found');
    }
    // Only allow toggling favorites for user categories
    if (!$category['is_system'] && isset($category['user_id']) &&
        (string)$category['user_id'] !== getCurrentUserId()) {
        errorResponse('Cannot modify this category');
    }
    $newFavoriteStatus = !($category['is_favorite'] ?? false);
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($categoryId)],
        ['$set' => ['is_favorite' => $newFavoriteStatus]]
    );
    successResponse([
        'category_id' => $categoryId,
        'is_favorite' => $newFavoriteStatus
    ], 'Favorite status updated');
}
// Route handling
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getCategories();
        break;
    case 'get_favorites':
        if ($method === 'GET') getFavoriteCategories();
        break;
    case 'create':
        if ($method === 'POST') createCategory();
        break;
    case 'update':
        if ($method === 'POST' || $method === 'PUT') updateCategory();
        break;
    case 'delete':
        if ($method === 'POST' || $method === 'DELETE') deleteCategory();
        break;
    case 'toggle_favorite':
        if ($method === 'POST') toggleFavorite();
        break;
    default:
        errorResponse('Invalid action');
}
?>
