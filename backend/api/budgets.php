<?php
declare(strict_types=1);
// Budgets API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createBudget(); break;
    case 'get_all': $method === 'GET' && getBudgets(); break;
    case 'get': $method === 'GET' && getBudget(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateBudget(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteBudget(); break;
    case 'alerts': $method === 'GET' && getBudgetAlerts(); break;
    default: errorResponse('Invalid action', 404);
}

function createBudget() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $category = sanitizeInput($data['category'] ?? '');
    $limit = $data['limit'] ?? 0;
    $period = sanitizeInput($data['period'] ?? 'monthly');
    if (empty($category)) errorResponse('Category is required');
    if (!validateAmount($limit)) errorResponse('Budget limit must be greater than 0');
    $collection = getCollection('budgets');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne(['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'category' => $category, 'is_active' => true]);
    if ($existing) errorResponse('A budget already exists for this category');
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'category' => $category,
        'limit' => (float)$limit,
        'period' => $period,
        'current_spent' => 0,
        'is_active' => true,
        'period_start' => phpDateToMongo(date('Y-m-01')),
        'period_end' => phpDateToMongo(date('Y-m-t')),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create budget');
    logActivity('budget_created', getCurrentUserId(), ['budget_id' => (string)$result->getInsertedId(), 'category' => $category]);
    successResponse(['budget_id' => (string)$result->getInsertedId()], 'Budget created successfully');
}

function getBudgets() {
    requireActiveSession();
    $collection = getCollection('budgets');
    $budgets = $collection->find(['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'is_active' => true, 'deleted_at' => null], ['sort' => ['created_at' => -1]])->toArray();
    $formatted = array_map(function($b) {
        $spent = $b['current_spent'] ?? 0;
        $limit = $b['limit'] ?? 0;
        return [
            '_id' => (string)$b['_id'],
            'category' => $b['category'],
            'limit' => $limit,
            'period' => $b['period'] ?? 'monthly',
            'current_spent' => $spent,
            'remaining' => $limit - $spent,
            'percentage' => $limit > 0 ? round(($spent / $limit) * 100, 2) : 0,
            'status' => $limit > 0 && $spent >= $limit ? 'exceeded' : ($limit > 0 && $spent >= $limit * 0.8 ? 'warning' : 'ok')
        ];
    }, $budgets);
    successResponse(['budgets' => $formatted]);
}

function getBudget() {
    requireActiveSession();
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid budget ID');
    $collection = getCollection('budgets');
    $b = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$b) errorResponse('Budget not found');
    successResponse(['_id' => (string)$b['_id'], 'category' => $b['category'], 'limit' => $b['limit'], 'period' => $b['period'] ?? 'monthly', 'current_spent' => $b['current_spent'] ?? 0]);
}

function updateBudget() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid budget ID');
    $collection = getCollection('budgets');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$existing) errorResponse('Budget not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['category'])) $updateData['category'] = sanitizeInput($data['category']);
    if (isset($data['limit'])) {
        if (!validateAmount($data['limit'])) errorResponse('Budget limit must be greater than 0');
        $updateData['limit'] = (float)$data['limit'];
    }
    if (isset($data['period'])) $updateData['period'] = sanitizeInput($data['period']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('budget_updated', getCurrentUserId(), ['budget_id' => $id]);
    successResponse(['budget_id' => $id, 'updated' => true], 'Budget updated successfully');
}

function deleteBudget() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid budget ID');
    $collection = getCollection('budgets');
    $b = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null]);
    if (!$b) errorResponse('Budget not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['deleted_at' => phpDateToMongo(), 'is_active' => false, 'updated_at' => phpDateToMongo()]]);
    logActivity('budget_deleted', getCurrentUserId(), ['budget_id' => $id]);
    successResponse(null, 'Budget deleted successfully');
}

function getBudgetAlerts() {
    requireActiveSession();
    $collection = getCollection('budgets');
    $budgets = $collection->find(['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'is_active' => true, 'deleted_at' => null])->toArray();
    $alerts = [];
    foreach ($budgets as $b) {
        $spent = $b['current_spent'] ?? 0;
        $limit = $b['limit'] ?? 0;
        if ($limit > 0 && $spent >= $limit) {
            $alerts[] = ['type' => 'danger', 'message' => "Budget exceeded for {$b['category']}: ₹" . number_format($spent, 2) . " / ₹" . number_format($limit, 2)];
        } elseif ($limit > 0 && $spent >= $limit * 0.8) {
            $alerts[] = ['type' => 'warning', 'message' => "Budget 80% used for {$b['category']}: ₹" . number_format($spent, 2) . " / ₹" . number_format($limit, 2)];
        }
    }
    successResponse(['alerts' => $alerts]);
}