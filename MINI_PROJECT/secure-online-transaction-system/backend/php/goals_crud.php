<?php
// backend/php/goals_crud.php
/**
 * Savings Goals Management for Smart Transaction Control
 * Handles create, read, update, delete and contributions for savings goals
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
 * Get all goals for the current user
 * GET: action=get_all
 */
function getGoals() {
    requireActiveSession();
    $collection = getCollection('goals');
    $goals = $collection->find(
        ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())],
        ['sort' => ['created_at' => -1]]
    )->toArray();
    $formattedGoals = array_map(function($goal) {
        return [
            '_id' => (string)$goal['_id'],
            'name' => $goal['name'],
            'target_amount' => (float)$goal['target_amount'],
            'current_amount' => (float)($goal['current_amount'] ?? 0),
            'target_date' => isset($goal['target_date']) ? mongoDateToPHP($goal['target_date'])->format('Y-m-d') : null,
            'icon' => $goal['icon'] ?? 'fa-bullseye',
            'description' => $goal['description'] ?? '',
            'status' => $goal['status'] ?? 'active',
            'created_at' => isset($goal['created_at']) ? mongoDateToPHP($goal['created_at'])->format('Y-m-d H:i:s') : null
        ];
    }, $goals);
    successResponse($formattedGoals);
}
/**
 * Create a new savings goal
 * POST: action=create
 */
function createGoal() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    $name = sanitizeInput($data['name'] ?? '');
    $targetAmount = $data['target_amount'] ?? 0;
    $currentAmount = $data['current_amount'] ?? 0;
    $targetDate = $data['target_date'] ?? null;
    $icon = sanitizeInput($data['icon'] ?? 'fa-bullseye');
    $description = sanitizeInput($data['description'] ?? '');
    if (empty($name)) {
        errorResponse('Goal name is required');
    }
    if (!validateAmount($targetAmount) || $targetAmount <= 0) {
        errorResponse('Target amount must be greater than zero');
    }
    $goalDocument = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'name' => $name,
        'target_amount' => (float)$targetAmount,
        'current_amount' => (float)max(0, $currentAmount),
        'target_date' => $targetDate ? new MongoDB\BSON\UTCDateTime(strtotime($targetDate) * 1000) : null,
        'icon' => $icon,
        'description' => $description,
        'status' => 'active',
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo()
    ];
    $collection = getCollection('goals');
    $result = $collection->insertOne($goalDocument);
    logActivity('goal_created', getCurrentUserId(), [
        'goal_id' => (string)$result->getInsertedId(),
        'name' => $name,
        'target_amount' => $targetAmount
    ]);
    successResponse([
        'goal_id' => (string)$result->getInsertedId()
    ], 'Goal created successfully');
}
/**
 * Update an existing goal
 * POST: action=update
 */
function updateGoal() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    $goalId = $data['id'] ?? '';
    if (!isValidObjectId($goalId)) {
        errorResponse('Invalid goal ID');
    }
    $collection = getCollection('goals');
    $goal = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($goalId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
    ]);
    if (!$goal) {
        errorResponse('Goal not found');
    }
    $updateData = [];
    if (isset($data['name'])) {
        $name = sanitizeInput($data['name']);
        if (empty($name)) {
            errorResponse('Goal name cannot be empty');
        }
        $updateData['name'] = $name;
    }
    if (isset($data['target_amount'])) {
        if (!validateAmount($data['target_amount']) || $data['target_amount'] <= 0) {
            errorResponse('Target amount must be greater than zero');
        }
        $updateData['target_amount'] = (float)$data['target_amount'];
    }
    if (isset($data['current_amount'])) {
        $updateData['current_amount'] = (float)max(0, $data['current_amount']);
    }
    if (array_key_exists('target_date', $data)) {
        $updateData['target_date'] = $data['target_date']
            ? new MongoDB\BSON\UTCDateTime(strtotime($data['target_date']) * 1000)
            : null;
    }
    if (isset($data['icon'])) {
        $updateData['icon'] = sanitizeInput($data['icon']);
    }
    if (isset($data['description'])) {
        $updateData['description'] = sanitizeInput($data['description']);
    }
    if (isset($data['status'])) {
        $status = sanitizeInput($data['status']);
        if (in_array($status, ['active', 'completed', 'paused'])) {
            $updateData['status'] = $status;
        }
    }
    if (empty($updateData)) {
        errorResponse('No changes provided');
    }
    $updateData['updated_at'] = phpDateToMongo();
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($goalId)],
        ['$set' => $updateData]
    );
    logActivity('goal_updated', getCurrentUserId(), [
        'goal_id' => $goalId
    ]);
    successResponse(null, 'Goal updated successfully');
}
/**
 * Delete a goal
 * POST: action=delete
 */
function deleteGoal() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    $goalId = $data['id'] ?? '';
    if (!isValidObjectId($goalId)) {
        errorResponse('Invalid goal ID');
    }
    $collection = getCollection('goals');
    $result = $collection->deleteOne([
        '_id' => new MongoDB\BSON\ObjectId($goalId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
    ]);
    if ($result->getDeletedCount() === 0) {
        errorResponse('Goal not found');
    }
    logActivity('goal_deleted', getCurrentUserId(), [
        'goal_id' => $goalId
    ]);
    successResponse(null, 'Goal deleted successfully');
}
/**
 * Add a contribution to a goal
 * POST: action=contribute
 */
function addContribution() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    $goalId = $data['id'] ?? '';
    $amount = $data['amount'] ?? 0;
    if (!isValidObjectId($goalId)) {
        errorResponse('Invalid goal ID');
    }
    if (!validateAmount($amount) || $amount <= 0) {
        errorResponse('Contribution amount must be greater than zero');
    }
    $collection = getCollection('goals');
    $goal = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($goalId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
    ]);
    if (!$goal) {
        errorResponse('Goal not found');
    }
    $newAmount = (float)($goal['current_amount'] ?? 0) + (float)$amount;
    $status = $newAmount >= (float)$goal['target_amount'] ? 'completed' : ($goal['status'] ?? 'active');
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($goalId)],
        ['$set' => [
            'current_amount' => $newAmount,
            'status' => $status,
            'updated_at' => phpDateToMongo()
        ]]
    );
    logActivity('goal_contribution', getCurrentUserId(), [
        'goal_id' => $goalId,
        'amount' => $amount
    ]);
    successResponse([
        'current_amount' => $newAmount,
        'status' => $status
    ], 'Contribution added successfully');
}
/**
 * Get goal summary stats
 * GET: action=summary
 */
function getGoalSummary() {
    requireActiveSession();
    $collection = getCollection('goals');
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $goals = $collection->find(['user_id' => $userId])->toArray();
    $totalTarget = 0;
    $totalSaved = 0;
    $activeCount = 0;
    $completedCount = 0;
    foreach ($goals as $goal) {
        $totalTarget += (float)$goal['target_amount'];
        $totalSaved += (float)($goal['current_amount'] ?? 0);
        if (($goal['status'] ?? 'active') === 'completed') {
            $completedCount++;
        } else {
            $activeCount++;
        }
    }
    successResponse([
        'total_target' => $totalTarget,
        'total_saved' => $totalSaved,
        'active_count' => $activeCount,
        'completed_count' => $completedCount,
        'overall_progress' => $totalTarget > 0 ? round(($totalSaved / $totalTarget) * 100, 2) : 0
    ]);
}
// Route handling
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getGoals();
        break;
    case 'create':
        if ($method === 'POST') createGoal();
        break;
    case 'update':
        if ($method === 'POST' || $method === 'PUT') updateGoal();
        break;
    case 'delete':
        if ($method === 'POST' || $method === 'DELETE') deleteGoal();
        break;
    case 'contribute':
        if ($method === 'POST') addContribution();
        break;
    case 'summary':
        if ($method === 'GET') getGoalSummary();
        break;
    default:
        errorResponse('Invalid action');
}
?>
