<?php
declare(strict_types=1);
// Bill Reminders API - Manage bill reminders and notifications
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create': $method === 'POST' && createReminder(); break;
    case 'get_all': $method === 'GET' && getReminders(); break;
    case 'get': $method === 'GET' && getReminder(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateReminder(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteReminder(); break;
    case 'upcoming': $method === 'GET' && getUpcomingReminders(); break;
    case 'mark_paid': $method === 'POST' && markReminderPaid(); break;
    default: errorResponse('Invalid action', 404);
}

/**
 * Default reminder types with icons and colors.
 */
function getReminderTypes() {
    return [
        'electricity' => ['label' => 'Electricity', 'icon' => 'fa-bolt', 'color' => '#f39c12'],
        'water' => ['label' => 'Water', 'icon' => 'fa-tint', 'color' => '#3498db'],
        'internet' => ['label' => 'Internet', 'icon' => 'fa-wifi', 'color' => '#9b59b6'],
        'gas' => ['label' => 'Gas', 'icon' => 'fa-fire', 'color' => '#e74c3c'],
        'mobile_recharge' => ['label' => 'Mobile Recharge', 'icon' => 'fa-mobile-alt', 'color' => '#2ecc71'],
        'credit_card' => ['label' => 'Credit Card', 'icon' => 'fa-credit-card', 'color' => '#1abc9c'],
        'emi' => ['label' => 'EMI', 'icon' => 'fa-university', 'color' => '#e67e22'],
        'insurance' => ['label' => 'Insurance', 'icon' => 'fa-shield-alt', 'color' => '#34495e'],
        'rent' => ['label' => 'Rent', 'icon' => 'fa-home', 'color' => '#16a085'],
        'other' => ['label' => 'Other', 'icon' => 'fa-bell', 'color' => '#95a5a6']
    ];
}

/**
 * Create a new bill reminder.
 * Fields: name/title, type, amount, due_date, repeat (none/daily/weekly/monthly/yearly),
 *         days_before_notify, category, notes, is_active
 */
function createReminder() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $title = sanitizeInput($data['title'] ?? $data['name'] ?? '');
    $type = sanitizeInput($data['type'] ?? 'other');
    $amount = (float)($data['amount'] ?? 0);
    $dueDate = sanitizeInput($data['due_date'] ?? '');
    $repeat = sanitizeInput($data['repeat'] ?? 'none');
    $daysBefore = max(0, min(30, (int)($data['days_before_notify'] ?? 3)));
    $category = sanitizeInput($data['category'] ?? ucfirst(str_replace('_', ' ', $type)));
    $notes = sanitizeInput($data['notes'] ?? '');
    $isActive = isset($data['is_active']) ? !empty($data['is_active']) : true;
    if (empty($title)) errorResponse('Reminder title is required');
    if ($amount < 0) errorResponse('Amount cannot be negative');
    if (empty($dueDate)) errorResponse('Due date is required');
    if (!validateDate($dueDate)) errorResponse('Invalid due date format');
    $validTypes = array_keys(getReminderTypes());
    if (!in_array($type, $validTypes, true)) $type = 'other';
    $validRepeats = ['none', 'daily', 'weekly', 'monthly', 'yearly'];
    if (!in_array($repeat, $validRepeats, true)) $repeat = 'none';
    $collection = getCollection('reminders');
    if (!$collection) errorResponse('Database connection error');
    $doc = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'title' => $title,
        'type' => $type,
        'amount' => $amount,
        'due_date' => phpDateToMongo($dueDate),
        'repeat' => $repeat,
        'days_before_notify' => $daysBefore,
        'category' => $category,
        'notes' => $notes,
        'is_active' => $isActive,
        'is_paid' => false,
        'paid_date' => null,
        'last_notified_at' => null,
        'notification_sent' => false,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create reminder');
    logActivity('reminder_created', getCurrentUserId(), ['reminder_id' => (string)$result->getInsertedId(), 'title' => $title]);
    successResponse(['reminder_id' => (string)$result->getInsertedId()], 'Reminder created successfully');
}

/**
 * Get all reminders for the current user.
 */
function getReminders() {
    requireActiveSession();
    $collection = getCollection('reminders');
    if (!$collection) errorResponse('Database connection error');
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    if (isset($_GET['active_only']) && $_GET['active_only'] === '1') {
        $filter['is_active'] = true;
    }
    $reminders = $collection->find($filter, ['sort' => ['due_date' => 1]])->toArray();
    $types = getReminderTypes();
    $formatted = array_map(function($r) use ($types) {
        $typeInfo = $types[$r['type'] ?? 'other'] ?? $types['other'];
        $dueDate = $r['due_date'] ?? null;
        $dueTimestamp = $dueDate instanceof MongoDB\BSON\UTCDateTime ? $dueDate->toDateTime()->getTimestamp() : time();
        $daysUntilDue = (int)ceil(($dueTimestamp - time()) / 86400);
        $status = 'upcoming';
        if (!empty($r['is_paid'])) {
            $status = 'paid';
        } elseif ($daysUntilDue < 0) {
            $status = 'overdue';
        } elseif ($daysUntilDue <= ($r['days_before_notify'] ?? 3)) {
            $status = 'due_soon';
        }
        return [
            '_id' => (string)$r['_id'],
            'title' => $r['title'],
            'type' => $r['type'] ?? 'other',
            'type_label' => $typeInfo['label'],
            'icon' => $typeInfo['icon'],
            'color' => $typeInfo['color'],
            'amount' => round((float)($r['amount'] ?? 0), 2),
            'due_date' => $dueDate ? mongoDateToPHP($dueDate)->format('Y-m-d') : '',
            'days_until_due' => $daysUntilDue,
            'repeat' => $r['repeat'] ?? 'none',
            'days_before_notify' => $r['days_before_notify'] ?? 3,
            'category' => $r['category'] ?? '',
            'notes' => $r['notes'] ?? '',
            'is_active' => !empty($r['is_active']),
            'is_paid' => !empty($r['is_paid']),
            'status' => $status,
            'created_at' => mongoDateToPHP($r['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }, $reminders);
    successResponse(['reminders' => $formatted]);
}

/**
 * Get a single reminder by ID.
 */
function getReminder() {
    requireActiveSession();
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid reminder ID');
    $collection = getCollection('reminders');
    $r = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$r) errorResponse('Reminder not found');
    $types = getReminderTypes();
    $typeInfo = $types[$r['type'] ?? 'other'] ?? $types['other'];
    successResponse([
        '_id' => (string)$r['_id'],
        'title' => $r['title'],
        'type' => $r['type'] ?? 'other',
        'type_label' => $typeInfo['label'],
        'icon' => $typeInfo['icon'],
        'color' => $typeInfo['color'],
        'amount' => round((float)($r['amount'] ?? 0), 2),
        'due_date' => isset($r['due_date']) ? mongoDateToPHP($r['due_date'])->format('Y-m-d') : '',
        'repeat' => $r['repeat'] ?? 'none',
        'days_before_notify' => $r['days_before_notify'] ?? 3,
        'category' => $r['category'] ?? '',
        'notes' => $r['notes'] ?? '',
        'is_active' => !empty($r['is_active']),
        'is_paid' => !empty($r['is_paid'])
    ]);
}

/**
 * Update reminder details.
 * Fields: title, type, amount, due_date, repeat, days_before_notify, category, notes, is_active
 */
function updateReminder() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid reminder ID');
    $collection = getCollection('reminders');
    $existing = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$existing) errorResponse('Reminder not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['title'])) {
        $title = sanitizeInput($data['title']);
        if (empty($title)) errorResponse('Reminder title is required');
        $updateData['title'] = $title;
    }
    if (isset($data['type'])) {
        $validTypes = array_keys(getReminderTypes());
        $updateData['type'] = in_array($data['type'], $validTypes, true) ? $data['type'] : 'other';
    }
    if (isset($data['amount'])) {
        $amount = (float)$data['amount'];
        if ($amount < 0) errorResponse('Amount cannot be negative');
        $updateData['amount'] = $amount;
    }
    if (isset($data['due_date'])) {
        if (!validateDate($data['due_date'])) errorResponse('Invalid due date format');
        $updateData['due_date'] = phpDateToMongo($data['due_date']);
        // Reset paid status when due date changes
        $updateData['is_paid'] = false;
        $updateData['paid_date'] = null;
    }
    if (isset($data['repeat'])) {
        $validRepeats = ['none', 'daily', 'weekly', 'monthly', 'yearly'];
        $updateData['repeat'] = in_array($data['repeat'], $validRepeats, true) ? $data['repeat'] : 'none';
    }
    if (isset($data['days_before_notify'])) {
        $updateData['days_before_notify'] = max(0, min(30, (int)$data['days_before_notify']));
    }
    if (isset($data['category'])) $updateData['category'] = sanitizeInput($data['category']);
    if (isset($data['notes'])) $updateData['notes'] = sanitizeInput($data['notes']);
    if (isset($data['is_active'])) $updateData['is_active'] = !empty($data['is_active']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $updateData]);
    logActivity('reminder_updated', getCurrentUserId(), ['reminder_id' => $id]);
    successResponse(['reminder_id' => $id, 'updated' => true], 'Reminder updated successfully');
}

/**
 * Delete a reminder (soft delete).
 */
function deleteReminder() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid reminder ID');
    $collection = getCollection('reminders');
    $r = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$r) errorResponse('Reminder not found');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], [
        '$set' => ['deleted_at' => phpDateToMongo(), 'is_active' => false, 'updated_at' => phpDateToMongo()]
    ]);
    logActivity('reminder_deleted', getCurrentUserId(), ['reminder_id' => $id]);
    successResponse(null, 'Reminder deleted successfully');
}

/**
 * Get upcoming reminders (due within the next X days).
 */
function getUpcomingReminders() {
    requireActiveSession();
    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
    $collection = getCollection('reminders');
    if (!$collection) errorResponse('Database connection error');
    $reminders = $collection->find([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null,
        'is_active' => true,
        'is_paid' => false,
        'due_date' => ['$lte' => phpDateToMongo(date('Y-m-d', strtotime('+' . $days . ' days')))]
    ], ['sort' => ['due_date' => 1]])->toArray();
    $types = getReminderTypes();
    $formatted = array_map(function($r) use ($types) {
        $typeInfo = $types[$r['type'] ?? 'other'] ?? $types['other'];
        return [
            '_id' => (string)$r['_id'],
            'title' => $r['title'],
            'type' => $r['type'] ?? 'other',
            'type_label' => $typeInfo['label'],
            'icon' => $typeInfo['icon'],
            'color' => $typeInfo['color'],
            'amount' => round((float)($r['amount'] ?? 0), 2),
            'due_date' => isset($r['due_date']) ? mongoDateToPHP($r['due_date'])->format('Y-m-d') : '',
            'is_overdue' => isset($r['due_date']) && $r['due_date']->toDateTime()->getTimestamp() < time()
        ];
    }, $reminders);
    successResponse(['reminders' => $formatted, 'count' => count($formatted)]);
}

/**
 * Mark a reminder as paid. Optionally creates an expense transaction.
 * Fields: reminder_id, paid_amount (optional), wallet_id (optional), create_transaction (bool)
 */
function markReminderPaid() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['reminder_id'] ?? $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid reminder ID');
    $collection = getCollection('reminders');
    $r = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($id),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$r) errorResponse('Reminder not found');
    $paidAmount = (float)($data['paid_amount'] ?? ($r['amount'] ?? 0));
    $walletId = $data['wallet_id'] ?? null;
    $createTx = !empty($data['create_transaction']);
    // Create an expense transaction if requested
    if ($createTx) {
        $txCollection = getCollection('transactions');
        if (!$txCollection) errorResponse('Database connection error');
        $txData = [
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
            'type' => 'expense',
            'category' => $r['category'] ?? ucfirst(str_replace('_', ' ', $r['type'] ?? 'bill')),
            'amount' => $paidAmount,
            'currency' => 'INR',
            'description' => 'Payment for ' . ($r['title'] ?? 'bill'),
            'date' => phpDateToMongo(),
            'payment_method' => $walletId ? 'wallet' : 'cash',
            'reminder_id' => $r['_id'],
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo(),
            'deleted_at' => null
        ];
        if ($walletId && isValidObjectId($walletId)) {
            $walletsCollection = getCollection('wallets');
            if ($walletsCollection) {
                $wallet = $walletsCollection->findOne([
                    '_id' => new MongoDB\BSON\ObjectId($walletId),
                    'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
                    'deleted_at' => null
                ]);
                if ($wallet) {
                    if ((float)($wallet['balance'] ?? 0) < $paidAmount) {
                        errorResponse('Insufficient wallet balance');
                    }
                    $walletsCollection->updateOne(['_id' => $wallet['_id']], [
                        '$inc' => ['balance' => -$paidAmount],
                        '$set' => ['updated_at' => phpDateToMongo()]
                    ]);
                    $txData['wallet_id'] = $wallet['_id'];
                }
            }
        }
        $txCollection->insertOne($txData);
    }
    // Handle repeating reminders: advance the due date
    $updateFields = [
        'is_paid' => true,
        'paid_date' => phpDateToMongo(),
        'updated_at' => phpDateToMongo()
    ];
    $repeat = $r['repeat'] ?? 'none';
    if ($repeat !== 'none' && isset($r['due_date']) && $r['due_date'] instanceof MongoDB\BSON\UTCDateTime) {
        $currentDue = $r['due_date']->toDateTime();
        $nextDue = clone $currentDue;
        switch ($repeat) {
            case 'daily':
                $nextDue->modify('+1 day');
                break;
            case 'weekly':
                $nextDue->modify('+1 week');
                break;
            case 'monthly':
                $nextDue->modify('+1 month');
                break;
            case 'yearly':
                $nextDue->modify('+1 year');
                break;
        }
        $updateFields['due_date'] = new MongoDB\BSON\UTCDateTime($nextDue->getTimestamp() * 1000);
        $updateFields['is_paid'] = false;
        $updateFields['paid_date'] = null;
        $updateFields['notification_sent'] = false;
        $updateFields['last_notified_at'] = null;
    }
    $collection->updateOne(['_id' => $r['_id']], ['$set' => $updateFields]);
    logActivity('reminder_paid', getCurrentUserId(), ['reminder_id' => $id, 'amount' => $paidAmount]);
    successResponse(null, !empty($updateFields['is_paid']) ? 'Reminder marked as paid' : 'Payment recorded. Next occurrence scheduled.');
}