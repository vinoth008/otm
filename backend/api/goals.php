<?php
declare(strict_types=1);
// Savings Goals API - Create, edit, delete, track goals
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createGoal(); break;
    case 'get_all': $method === 'GET' && getGoals(); break;
    case 'get': $method === 'GET' && getGoal(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateGoal(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteGoal(); break;
    case 'add_funds': $method === 'POST' && addFundsToGoal(); break;
    default: errorResponse('Invalid action', 404);
}

/**
 * Create a new savings goal.
 * Fields: name, target_amount, saved_amount, target_date, icon, color, description
 */
function createGoal() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $name = sanitizeInput($data['name'] ?? '');
    $targetAmount = (float)($data['target_amount'] ?? 0);
    $savedAmount = (float)($data['saved_amount'] ?? 0);
    $targetDate = sanitizeInput($data['target_date'] ?? '');
    $icon = sanitizeInput($data['icon'] ?? 'fa-bullseye');
    $color = sanitizeInput($data['color'] ?? '#00b894');
    $description = sanitizeInput($data['description'] ?? '');
    if (empty($name)) errorResponse('Goal name is required');
    if ($targetAmount <= 0) errorResponse('Target amount must be greater than 0');
    if ($savedAmount < 0) errorResponse('Saved amount cannot be negative');
    if ($savedAmount > $targetAmount) errorResponse('Saved amount cannot exceed target amount');
    if (!empty($targetDate) && !validateDate($targetDate)) errorResponse('Invalid target date format');
    if (empty($targetDate)) $targetDate = date('Y-12-31');
    $collection = getCollection('goals');
    if (!$collection) errorResponse('Database connection error');
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'name' => $name,
        'target_amount' => $targetAmount,
        'saved_amount' => $savedAmount,
        'target_date' => phpDateToMongo($targetDate),
        'icon' => $icon,
        'color' => $color,
        'description' => $description,
        'status' => $savedAmount >= $targetAmount ? 'completed' : 'in_progress',
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create goal');
    logActivity('goal_created', getCurrentUserId(), ['goal_id' => (string)$result->getInsertedId(), 'name' => $name]);
    successResponse(['goal_id' => (string)$result->getInsertedId()], 'Goal created successfully');
}

/**
 * Get all goals for the current user with progress calculations.
 */
function getGoals() {
    requireActiveSession();
    $collection = getCollection('goals');
    if (!$collection) errorResponse('Database connection error');
    $goals = $collection->find(
        ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null],
        ['sort' => ['created_at' => -1]]
    )->toArray();
    $formatted = array_map(function($g) {
        $target = (float)($g['target_amount'] ?? 0);
        $saved = (float)($g['saved_amount'] ?? 0);
        $targetDate = $g['target_date'] ?? null;
        $daysRemaining = 0;
        if ($targetDate instanceof MongoDB\BSON\UTCDateTime) {
            $targetTS = $targetDate->toDateTime()->getTimestamp();
            $now = time();
            $daysRemaining = (int)ceil(($targetTS - $now) / 86400);
        }
        $monthlyNeeded = 0;
        if ($daysRemaining > 0 && $target > $saved) {
            $monthlyNeeded = ($target - $saved) / ($daysRemaining / 30.44);
        }
        return [
            '_id' => (string)$g['_id'],
            'name' => $g['name'],
            'target_amount' => $target,
            'saved_amount' => $saved,
            'remaining_amount' => round($target - $saved, 2),
            'target_date' => $targetDate ? mongoDateToPHP($targetDate)->format('Y-m-d') : date('Y-12-31'),
            'progress_percent' => $target > 0 ? round(($saved / $target) * 100, 2) : 0,
            'days_remaining' => $daysRemaining,
            'monthly_needed' => round($monthlyNeeded, 2),
            'icon' => $g['icon'] ?? 'fa-bullseye',
            'color' => $g['color'] ?? '#00b894',
            'description' => $g['description'] ?? '',
            'status' => $g['status'] ?? ($target > 0 && $saved >= $target ? 'completed' : 'in_progress'),
            'created_at' => mongoDateToPHP($g['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }, $goals);
    successResponse(['goals' => $formatted]);
}

/**
 * Get a single goal by ID.
 */
function getGoal() {
    requireActiveSession();
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid goal ID');
    $collection = getCollection('goals');
    $g = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$g) errorResponse('Goal not found');
    $target = (float)($g['target_amount'] ?? 0);
    $saved = (float)($g['saved_amount'] ?? 0);
    successResponse([
        '_id' => (string)$g['_id'],
        'name' => $g['name'],
        'target_amount' => $target,
        'saved_amount' => $saved,
        'remaining_amount' => round($target - $saved, 2),
        'target_date' => isset($g['target_date']) ? mongoDateToPHP($g['target_date'])->format('Y-m-d') : date('Y-12-31'),
        'progress_percent' => $target > 0 ? round(($saved / $target) * 100, 2) : 0,
        'icon' => $g['icon'] ?? 'fa-bullseye',
        'color' => $g['color'] ?? '#00b894',
        'description' => $g['description'] ?? '',
        'status' => $g['status'] ?? 'in_progress'
    ]);
}

/**
 * Update goal details.
 * Fields: name, target_amount, saved_amount, target_date, icon, color, description, status
 */
function updateGoal() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid goal ID');
    $collection = getCollection('goals');
    $existing = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$existing) errorResponse('Goal not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['name'])) {
        $name = sanitizeInput($data['name']);
        if (empty($name)) errorResponse('Goal name is required');
        $updateData['name'] = $name;
    }
    if (isset($data['target_amount'])) {
        $target = (float)$data['target_amount'];
        if ($target <= 0) errorResponse('Target amount must be greater than 0');
        $updateData['target_amount'] = $target;
    }
    if (isset($data['saved_amount'])) {
        $saved = (float)$data['saved_amount'];
        if ($saved < 0) errorResponse('Saved amount cannot be negative');
        $newTarget = $updateData['target_amount'] ?? (float)($existing['target_amount'] ?? 0);
        if ($saved > $newTarget) errorResponse('Saved amount cannot exceed target amount');
        $updateData['saved_amount'] = $saved;
    }
    if (isset($data['target_date'])) {
        if (!validateDate($data['target_date'])) errorResponse('Invalid target date format');
        $updateData['target_date'] = phpDateToMongo($data['target_date']);
    }
    if (isset($data['icon'])) $updateData['icon'] = sanitizeInput($data['icon']);
    if (isset($data['color'])) $updateData['color'] = sanitizeInput($data['color']);
    if (isset($data['description'])) $updateData['description'] = sanitizeInput($data['description']);
    // Auto-compute status based on final saved/target amounts
    $finalTarget = $updateData['target_amount'] ?? (float)($existing['target_amount'] ?? 0);
    $finalSaved = $updateData['saved_amount'] ?? (float)($existing['saved_amount'] ?? 0);
    if (isset($data['status'])) {
        $validStatuses = ['in_progress', 'completed', 'paused', 'cancelled'];
        if (in_array($data['status'], $validStatuses, true)) {
            $updateData['status'] = $data['status'];
        }
    } else {
        $updateData['status'] = $finalTarget > 0 && $finalSaved >= $finalTarget ? 'completed' : 'in_progress';
    }
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('goal_updated', getCurrentUserId(), ['goal_id' => $id]);
    successResponse(['goal_id' => $id, 'updated' => true], 'Goal updated successfully');
}

/**
 * Delete a goal (soft delete).
 */
function deleteGoal() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid goal ID');
    $collection = getCollection('goals');
    $g = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$g) errorResponse('Goal not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], [
        '$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]
    ]);
    logActivity('goal_deleted', getCurrentUserId(), ['goal_id' => $id]);
    successResponse(null, 'Goal deleted successfully');
}

/**
 * Add funds to a goal. Creates an expense transaction if wallet balance is used,
 * or just increments saved_amount directly.
 * Fields: goal_id, amount, from_wallet_id (optional), note
 */
function addFundsToGoal() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $goalId = $data['goal_id'] ?? '';
    $amount = (float)($data['amount'] ?? 0);
    $note = sanitizeInput($data['note'] ?? 'Goal contribution');
    if (!isValidObjectId($goalId)) errorResponse('Invalid goal ID');
    if ($amount <= 0) errorResponse('Amount must be greater than 0');
    $collection = getCollection('goals');
    $goal = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($goalId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$goal) errorResponse('Goal not found');
    $target = (float)($goal['target_amount'] ?? 0);
    $currentSaved = (float)($goal['saved_amount'] ?? 0);
    $newSaved = $currentSaved + $amount;
    if ($newSaved > $target) errorResponse('This contribution would exceed the goal target');
    // If wallet specified, deduct from wallet balance
    $walletId = $data['from_wallet_id'] ?? null;
    if (!empty($walletId) && isValidObjectId($walletId)) {
        $walletsCollection = getCollection('wallets');
        if (!$walletsCollection) errorResponse('Database connection error');
        $wallet = $walletsCollection->findOne([
            '_id' => new MongoDB\BSON\ObjectId($walletId),
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'deleted_at' => null
        ]);
        if (!$wallet) errorResponse('Wallet not found');
        if ((float)($wallet['balance'] ?? 0) < $amount) errorResponse('Insufficient wallet balance');
        $walletsCollection->updateOne(['_id' => $wallet['_id']], [
            '$inc' => ['balance' => -$amount],
            '$set' => ['updated_at' => phpDateToMongo()]
        ]);
        // Record as a savings transaction
        $txCollection = getCollection('transactions');
        if ($txCollection) {
            $txCollection->insertOne([
                'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
                'type' => 'expense',
                'category' => 'Savings',
                'amount' => $amount,
                'currency' => 'INR',
                'description' => $note . ' (towards ' . ($goal['name'] ?? 'goal') . ')',
                'date' => phpDateToMongo(),
                'payment_method' => 'wallet',
                'wallet_id' => $wallet['_id'],
                'goal_id' => $goal['_id'],
                'created_at' => phpDateToMongo(),
                'updated_at' => phpDateToMongo(),
                'deleted_at' => null
            ]);
        }
    }
    $collection->updateOne(['_id' => $goal['_id']], [
        '$set' => [
            'saved_amount' => $newSaved,
            'status' => $newSaved >= $target ? 'completed' : 'in_progress',
            'updated_at' => phpDateToMongo()
        ]
    ]);
    logActivity('goal_funds_added', getCurrentUserId(), ['goal_id' => $goalId, 'amount' => $amount]);
    successResponse(['goal_id' => $goalId, 'saved_amount' => $newSaved], 'Funds added to goal successfully');
}