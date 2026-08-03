<?php
declare(strict_types=1);
// Recurring Transactions API - Auto-generate transactions on a schedule
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createRecurring(); break;
    case 'get_all': $method === 'GET' && getRecurring(); break;
    case 'get': $method === 'GET' && getRecurringById(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateRecurring(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteRecurring(); break;
    case 'toggle': $method === 'POST' && toggleRecurring(); break;
    case 'process_due': $method === 'POST' && processDueRecurring(); break;
    default: errorResponse('Invalid action', 404);
}

/**
 * Create a new recurring transaction template.
 * Fields: name, type (income/expense), category, amount, frequency (daily/weekly/monthly/yearly),
 *         interval (every N periods), start_date, end_date (optional), wallet_id (optional),
 *         payment_method, merchant, description, tags (array)
 */
function createRecurring() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $name = sanitizeInput($data['name'] ?? '');
    $type = sanitizeInput($data['type'] ?? 'expense');
    $category = sanitizeInput($data['category'] ?? '');
    $amount = (float)($data['amount'] ?? 0);
    $frequency = sanitizeInput($data['frequency'] ?? 'monthly');
    $interval = max(1, (int)($data['interval'] ?? 1));
    $startDate = sanitizeInput($data['start_date'] ?? date('Y-m-d'));
    $endDate = sanitizeInput($data['end_date'] ?? '');
    $walletId = $data['wallet_id'] ?? null;
    $paymentMethod = sanitizeInput($data['payment_method'] ?? 'cash');
    $merchant = sanitizeInput($data['merchant'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');
    $tags = $data['tags'] ?? [];
    if (empty($name)) errorResponse('Recurring transaction name is required');
    if (!in_array($type, ['income', 'expense'], true)) errorResponse('Invalid type. Must be income or expense');
    if (empty($category)) errorResponse('Category is required');
    if ($amount <= 0) errorResponse('Amount must be greater than 0');
    if (!validateDate($startDate)) errorResponse('Invalid start date format');
    if (!empty($endDate) && !validateDate($endDate)) errorResponse('Invalid end date format');
    if (!empty($endDate) && strtotime($endDate) < strtotime($startDate)) errorResponse('End date cannot be before start date');
    $validFrequencies = ['daily', 'weekly', 'monthly', 'yearly'];
    if (!in_array($frequency, $validFrequencies, true)) $frequency = 'monthly';
    $validPaymentMethods = ['cash', 'bank', 'credit_card', 'debit_card', 'upi', 'wallet'];
    if (!in_array($paymentMethod, $validPaymentMethods, true)) $paymentMethod = 'cash';
    if (!is_array($tags)) $tags = [];
    $tags = array_map('sanitizeInput', $tags);
    $tags = array_values(array_unique(array_filter($tags)));
    $collection = getCollection('recurring_transactions');
    if (!$collection) errorResponse('Database connection error');
    // Verify wallet ownership if provided
    if ($walletId && isValidObjectId($walletId)) {
        $walletsCollection = getCollection('wallets');
        $wallet = $walletsCollection->findOne([
            '_id' => new MongoDB\BSON\ObjectId($walletId),
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'deleted_at' => null
        ]);
        if (!$wallet) errorResponse('Wallet not found');
    } else {
        $walletId = null;
    }
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'name' => $name,
        'type' => $type,
        'category' => $category,
        'amount' => $amount,
        'currency' => 'INR',
        'frequency' => $frequency,
        'interval' => $interval,
        'start_date' => phpDateToMongo($startDate),
        'end_date' => $endDate ? phpDateToMongo($endDate) : null,
        'wallet_id' => $walletId ? new MongoDB\BSON\ObjectId($walletId) : null,
        'payment_method' => $paymentMethod,
        'merchant' => $merchant,
        'description' => $description,
        'tags' => $tags,
        'is_active' => true,
        'next_run_date' => phpDateToMongo($startDate),
        'last_run_date' => null,
        'times_generated' => 0,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create recurring transaction');
    logActivity('recurring_created', getCurrentUserId(), ['recurring_id' => (string)$result->getInsertedId(), 'name' => $name]);
    successResponse(['recurring_id' => (string)$result->getInsertedId()], 'Recurring transaction created successfully');
}

/**
 * Get all recurring transaction templates for the current user.
 */
function getRecurring() {
    requireActiveSession();
    $collection = getCollection('recurring_transactions');
    if (!$collection) errorResponse('Database connection error');
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    if (isset($_GET['active_only']) && $_GET['active_only'] === '1') {
        $filter['is_active'] = true;
    }
    if (!empty($_GET['type']) && in_array($_GET['type'], ['income', 'expense'], true)) {
        $filter['type'] = $_GET['type'];
    }
    $recurring = $collection->find($filter, ['sort' => ['next_run_date' => 1]])->toArray();
    $formatted = array_map(function($r) {
        return [
            '_id' => (string)$r['_id'],
            'name' => $r['name'],
            'type' => $r['type'],
            'category' => $r['category'],
            'amount' => round((float)($r['amount'] ?? 0), 2),
            'currency' => $r['currency'] ?? 'INR',
            'frequency' => $r['frequency'] ?? 'monthly',
            'interval' => $r['interval'] ?? 1,
            'start_date' => isset($r['start_date']) ? mongoDateToPHP($r['start_date'])->format('Y-m-d') : '',
            'end_date' => isset($r['end_date']) ? mongoDateToPHP($r['end_date'])->format('Y-m-d') : null,
            'payment_method' => $r['payment_method'] ?? 'cash',
            'merchant' => $r['merchant'] ?? '',
            'description' => $r['description'] ?? '',
            'tags' => $r['tags'] ?? [],
            'is_active' => !empty($r['is_active']),
            'next_run_date' => isset($r['next_run_date']) ? mongoDateToPHP($r['next_run_date'])->format('Y-m-d') : '',
            'last_run_date' => isset($r['last_run_date']) ? mongoDateToPHP($r['last_run_date'])->format('Y-m-d') : null,
            'times_generated' => $r['times_generated'] ?? 0
        ];
    }, $recurring);
    successResponse(['recurring_transactions' => $formatted]);
}

/**
 * Get a single recurring transaction by ID.
 */
function getRecurringById() {
    requireActiveSession();
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid recurring transaction ID');
    $collection = getCollection('recurring_transactions');
    $r = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$r) errorResponse('Recurring transaction not found');
    successResponse([
        '_id' => (string)$r['_id'],
        'name' => $r['name'],
        'type' => $r['type'],
        'category' => $r['category'],
        'amount' => round((float)($r['amount'] ?? 0), 2),
        'currency' => $r['currency'] ?? 'INR',
        'frequency' => $r['frequency'] ?? 'monthly',
        'interval' => $r['interval'] ?? 1,
        'start_date' => isset($r['start_date']) ? mongoDateToPHP($r['start_date'])->format('Y-m-d') : '',
        'end_date' => isset($r['end_date']) ? mongoDateToPHP($r['end_date'])->format('Y-m-d') : null,
        'payment_method' => $r['payment_method'] ?? 'cash',
        'merchant' => $r['merchant'] ?? '',
        'description' => $r['description'] ?? '',
        'tags' => $r['tags'] ?? [],
        'is_active' => !empty($r['is_active']),
        'next_run_date' => isset($r['next_run_date']) ? mongoDateToPHP($r['next_run_date'])->format('Y-m-d') : '',
        'last_run_date' => isset($r['last_run_date']) ? mongoDateToPHP($r['last_run_date'])->format('Y-m-d') : null,
        'times_generated' => $r['times_generated'] ?? 0
    ]);
}

/**
 * Update a recurring transaction template.
 */
function updateRecurring() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid recurring transaction ID');
    $collection = getCollection('recurring_transactions');
    $existing = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$existing) errorResponse('Recurring transaction not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['name'])) {
        $name = sanitizeInput($data['name']);
        if (empty($name)) errorResponse('Name is required');
        $updateData['name'] = $name;
    }
    if (isset($data['type']) && in_array($data['type'], ['income', 'expense'], true)) {
        $updateData['type'] = $data['type'];
    }
    if (isset($data['category'])) {
        $category = sanitizeInput($data['category']);
        if (empty($category)) errorResponse('Category is required');
        $updateData['category'] = $category;
    }
    if (isset($data['amount'])) {
        $amount = (float)$data['amount'];
        if ($amount <= 0) errorResponse('Amount must be greater than 0');
        $updateData['amount'] = $amount;
    }
    $validFrequencies = ['daily', 'weekly', 'monthly', 'yearly'];
    if (isset($data['frequency']) && in_array($data['frequency'], $validFrequencies, true)) {
        $updateData['frequency'] = $data['frequency'];
    }
    if (isset($data['interval'])) {
        $updateData['interval'] = max(1, (int)$data['interval']);
    }
    if (isset($data['start_date']) && validateDate($data['start_date'])) {
        $updateData['start_date'] = phpDateToMongo($data['start_date']);
    }
    if (isset($data['end_date'])) {
        $updateData['end_date'] = empty($data['end_date']) ? null : phpDateToMongo($data['end_date']);
    }
    $validPaymentMethods = ['cash', 'bank', 'credit_card', 'debit_card', 'upi', 'wallet'];
    if (isset($data['payment_method']) && in_array($data['payment_method'], $validPaymentMethods, true)) {
        $updateData['payment_method'] = $data['payment_method'];
    }
    if (isset($data['merchant'])) $updateData['merchant'] = sanitizeInput($data['merchant']);
    if (isset($data['description'])) $updateData['description'] = sanitizeInput($data['description']);
    if (isset($data['tags'])) {
        $tags = is_array($data['tags']) ? array_map('sanitizeInput', $data['tags']) : [];
        $updateData['tags'] = array_values(array_unique(array_filter($tags)));
    }
    if (isset($data['wallet_id'])) {
        if ($data['wallet_id'] && isValidObjectId($data['wallet_id'])) {
            $walletsCollection = getCollection('wallets');
            $wallet = $walletsCollection->findOne([
                '_id' => new MongoDB\BSON\ObjectId($data['wallet_id']),
                'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
                'deleted_at' => null
            ]);
            if (!$wallet) errorResponse('Wallet not found');
            $updateData['wallet_id'] = new MongoDB\BSON\ObjectId($data['wallet_id']);
        } else {
            $updateData['wallet_id'] = null;
        }
    }
    // If start_date changed, reset next_run_date
    if (isset($data['start_date'])) {
        $updateData['next_run_date'] = phpDateToMongo($data['start_date']);
    }
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('recurring_updated', getCurrentUserId(), ['recurring_id' => $id]);
    successResponse(['recurring_id' => $id, 'updated' => true], 'Recurring transaction updated successfully');
}

/**
 * Delete a recurring transaction template (soft delete).
 */
function deleteRecurring() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid recurring transaction ID');
    $collection = getCollection('recurring_transactions');
    $r = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$r) errorResponse('Recurring transaction not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], [
        '$set' => ['deleted_at' => phpDateToMongo(), 'is_active' => false, 'updated_at' => phpDateToMongo()]
    ]);
    logActivity('recurring_deleted', getCurrentUserId(), ['recurring_id' => $id]);
    successResponse(null, 'Recurring transaction deleted successfully');
}

/**
 * Toggle active/paused state of a recurring transaction.
 */
function toggleRecurring() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid recurring transaction ID');
    $collection = getCollection('recurring_transactions');
    $r = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$r) errorResponse('Recurring transaction not found');
    $newState = !empty($r['is_active']) ? false : true;
    $collection->updateOne(['_id' => $r['_id']], [
        '$set' => ['is_active' => $newState, 'updated_at' => phpDateToMongo()]
    ]);
    successResponse(['recurring_id' => $id, 'is_active' => $newState], $newState ? 'Recurring transaction activated' : 'Recurring transaction paused');
}

/**
 * Process all due recurring transactions.
 * Called automatically by cron or manually via admin/users.
 * Body: user_id (optional, process for one user), preview (bool)
 */
function processDueRecurring() {
    requireActiveSession();
    $data = getJSONRequest() ?? [];
    $preview = !empty($data['preview']);
    $collection = getCollection('recurring_transactions');
    if (!$collection) errorResponse('Database connection error');
    $filter = ['is_active' => true, 'deleted_at' => null];
    if (!empty($data['user_id']) && isValidObjectId($data['user_id'])) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId($data['user_id']);
    } else {
        $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
    }
    $due = $collection->find([
        'user_id' => $filter['user_id'],
        'is_active' => true,
        'deleted_at' => null,
        'next_run_date' => ['$lte' => phpDateToMongo()]
    ])->toArray();
    $generated = [];
    $txCollection = getCollection('transactions');
    $now = phpDateToMongo();
    foreach ($due as $r) {
        $timesGenerated = (int)($r['times_generated'] ?? 0);
        // Check end date
        if (isset($r['end_date']) && $r['end_date'] instanceof MongoDB\BSON\UTCDateTime) {
            if ($r['next_run_date']->toDateTime()->getTimestamp() > $r['end_date']->toDateTime()->getTimestamp()) {
                $collection->updateOne(['_id' => $r['_id']], ['$set' => ['is_active' => false, 'updated_at' => $now]]);
                continue;
            }
        }
        $txDoc = [
            'user_id' => $r['user_id'],
            'type' => $r['type'] ?? 'expense',
            'category' => $r['category'] ?? 'Other',
            'amount' => (float)($r['amount'] ?? 0),
            'currency' => $r['currency'] ?? 'INR',
            'description' => ($r['description'] ?? '') ?: ('Recurring: ' . ($r['name'] ?? '')),
            'date' => $r['next_run_date'],
            'payment_method' => $r['payment_method'] ?? 'cash',
            'merchant' => $r['merchant'] ?? '',
            'tags' => $r['tags'] ?? [],
            'recurring_id' => $r['_id'],
            'is_recurring' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null
        ];
        if (isset($r['wallet_id']) && $r['wallet_id'] instanceof MongoDB\BSON\ObjectId) {
            $txDoc['wallet_id'] = $r['wallet_id'];
            // Update wallet balance for expense/income
            $walletsCollection = getCollection('wallets');
            if ($walletsCollection) {
                $delta = ($r['type'] ?? 'expense') === 'income' ? (float)($r['amount'] ?? 0) : -(float)($r['amount'] ?? 0);
                $walletsCollection->updateOne(
                    ['_id' => $r['wallet_id'], 'deleted_at' => null],
                    ['$inc' => ['balance' => $delta], '$set' => ['updated_at' => $now]]
                );
            }
        }
        if (!$preview) {
            $txResult = $txCollection->insertOne($txDoc);
            // Advance next_run_date based on frequency
            $nextDate = $r['next_run_date']->toDateTime();
            $interval = max(1, (int)($r['interval'] ?? 1));
            switch ($r['frequency'] ?? 'monthly') {
                case 'daily':
                    $nextDate->modify('+' . $interval . ' day');
                    break;
                case 'weekly':
                    $nextDate->modify('+' . $interval . ' week');
                    break;
                case 'monthly':
                    $nextDate->modify('+' . $interval . ' month');
                    break;
                case 'yearly':
                    $nextDate->modify('+' . $interval . ' year');
                    break;
            }
            $collection->updateOne(['_id' => $r['_id']], [
                '$set' => [
                    'last_run_date' => $r['next_run_date'],
                    'next_run_date' => new MongoDB\BSON\UTCDateTime($nextDate->getTimestamp() * 1000),
                    'times_generated' => $timesGenerated + 1,
                    'updated_at' => $now
                ]
            ]);
            $generated[] = [
                'recurring_id' => (string)$r['_id'],
                'transaction_id' => (string)$txResult->getInsertedId(),
                'name' => $r['name'] ?? '',
                'amount' => $r['amount'] ?? 0
            ];
        } else {
            $generated[] = [
                'recurring_id' => (string)$r['_id'],
                'name' => $r['name'] ?? '',
                'amount' => $r['amount'] ?? 0,
                'scheduled_date' => mongoDateToPHP($r['next_run_date'])->format('Y-m-d')
            ];
        }
    }
    successResponse(['generated' => $generated, 'count' => count($generated), 'preview' => $preview]);
}