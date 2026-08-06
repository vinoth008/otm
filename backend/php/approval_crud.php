<?php
// backend/php/approval_crud.php
/**
 * Transaction Approval Workflow for Smart Transaction Control
 * BANK-LIKE WORKFLOW:
 *   - NEFT and IMPS transactions require Admin/Staff approval before balance update
 *   - All other transaction types process immediately (auto-approved)
 *   - Customer requests are stored as 'pending' until approved/rejected
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
 * Check if a payment method requires approval
 * @param string $method
 * @return bool
 */
function requiresApproval($method) {
    return in_array(strtolower($method), ['neft', 'imps'], true);
}

/**
 * Create transaction request (customer-facing)
 * - NEFT/IMPS → status = pending, requires approval
 * - Others → status = approved, updates balance immediately
 * POST: type, amount, payment_method, category, description, recipient, etc.
 */
function createTransactionRequest() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    // -- Extract and validate --
    $type = sanitizeInput($data['type'] ?? 'transfer');
    $amount = $data['amount'] ?? 0;
    $paymentMethod = sanitizeInput($data['payment_method'] ?? 'bank_transfer');
    $category = sanitizeInput($data['category'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');
    $date = sanitizeInput($data['date'] ?? date('Y-m-d'));
    $currency = sanitizeInput($data['currency'] ?? $_SESSION['user_currency'] ?? 'INR');
    $recipientName = sanitizeInput($data['recipient_name'] ?? '');
    $accountNumber = sanitizeInput($data['account_number'] ?? '');
    $ifscCode = sanitizeInput($data['ifsc_code'] ?? '');
    $note = sanitizeInput($data['note'] ?? $data['notes'] ?? '');
    // Validation
    $validTypes = ['income', 'expense', 'transfer', 'deposit', 'withdrawal', 'payment'];
    if (!in_array($type, $validTypes, true)) {
        errorResponse('Invalid transaction type');
    }
    if (!is_numeric($amount) || $amount <= 0) {
        errorResponse('Amount must be greater than 0');
    }
    if (!validateDate($date)) {
        errorResponse('Invalid date format');
    }
    // Payment method validation
    $validMethods = ['cash', 'card', 'upi', 'neft', 'imps', 'rtgs', 'bank_transfer', 'wallet', 'cheque', 'other'];
    if (!in_array($paymentMethod, $validMethods, true)) {
        errorResponse('Invalid payment method');
    }
    // NEFT/IMPS require bank details
    if (requiresApproval($paymentMethod)) {
        if (empty($recipientName)) {
            errorResponse('Recipient name is required for ' . strtoupper($paymentMethod));
        }
        if (empty($accountNumber) || !preg_match('/^\d{9,18}$/', $accountNumber)) {
            errorResponse('Valid recipient account number is required (9-18 digits)');
        }
        if (empty($ifscCode) || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper($ifscCode))) {
            errorResponse('Valid IFSC code is required (e.g., SBIN0001234)');
        }
    }
    $userId = getCurrentUserId();
    $isPending = requiresApproval($paymentMethod);
    $status = $isPending ? 'pending' : 'approved';
    // Build transaction document
    $transactionDocument = [
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'type' => $type,
        'category' => $category ?: 'Transfer',
        'subcategory' => $data['subcategory'] ?? null,
        'amount' => (float)$amount,
        'currency' => $currency,
        'description' => $description,
        'date' => new MongoDB\BSON\UTCDateTime(strtotime($date) * 1000),
        'payment_method' => strtolower($paymentMethod),
        'recipient_name' => $recipientName,
        'account_number' => $accountNumber,
        'ifsc_code' => strtoupper($ifscCode),
        'notes' => $note,
        'status' => $status,
        'requires_approval' => $isPending,
        'approved_by' => null,
        'approved_at' => null,
        'rejected_by' => null,
        'rejected_at' => null,
        'rejection_reason' => null,
        'modification_requested' => false,
        'modification_message' => null,
        'created_by' => $userId,
        'created_by_role' => $_SESSION['user_role'] ?? 'customer',
        'receipt_url' => null,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->insertOne($transactionDocument);
    if (!$result->getInsertedId()) {
        errorResponse('Failed to create transaction request');
    }
    $transactionId = (string)$result->getInsertedId();

    // If instant (no approval needed), update balances + all derived data immediately
    if (!$isPending) {
        applyTransactionToLedger($transactionId, $transactionDocument);
    } else {
        // Notify admin and staff about pending approval
        notifyApprovalRequired($transactionId, $transactionDocument);
    }
    // Handle receipt upload
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $receiptResult = handleReceiptUpload($_FILES['receipt'], $transactionId);
        if ($receiptResult['success']) {
            $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
                ['$set' => ['receipt_url' => $receiptResult['url']]]
            );
        }
    }
    // Log activity
    logActivity($isPending ? 'transaction_request_created' : 'transaction_created', $userId, [
        'transaction_id' => $transactionId,
        'type' => $type,
        'amount' => $amount,
        'payment_method' => $paymentMethod,
        'status' => $status
    ]);
    successResponse([
        'transaction_id' => $transactionId,
        'status' => $status,
        'requires_approval' => $isPending,
        'message' => $isPending
            ? 'Your ' . strtoupper($paymentMethod) . ' request has been submitted for approval.'
            : 'Transaction completed successfully.'
    ]);
}

/**
 * Apply an approved transaction to the ledger:
 *   - Update user balance
 *   - Update wallet balance
 *   - Update expense/income totals (categories collection)
 *   - Update budgets
 *   - Notify customer
 *   - Log audit entry
 * @param string $transactionId
 * @param array $transaction
 */
function applyTransactionToLedger($transactionId, $transaction) {
    $userId = (string)$transaction['user_id'];
    $amount = (float)$transaction['amount'];
    $type = $transaction['type'];
    $paymentMethod = $transaction['payment_method'] ?? 'bank_transfer';
    // 1. Update user balance
    $usersCol = getCollection('users');
    if ($usersCol) {
        $balanceChange = 0;
        if (in_array($type, ['income', 'deposit'], true)) {
            $balanceChange = $amount;
        } elseif (in_array($type, ['expense', 'withdrawal', 'payment', 'transfer'], true)) {
            $balanceChange = -$amount;
        }
        $usersCol->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($userId)],
            ['$inc' => ['balance' => $balanceChange]]
        );
    }
    // 2. Update wallet balance
    $walletsCol = getCollection('wallets');
    if ($walletsCol) {
        $walletChange = $balanceChange ?? 0;
        $walletsCol->updateOne(
            ['user_id' => new MongoDB\BSON\ObjectId($userId)],
            ['$inc' => ['balance' => $walletChange]]
        );
    }
    // 3. Update category totals
    $categoriesCol = getCollection('categories');
    if ($categoriesCol && !empty($transaction['category'])) {
        $categoryType = in_array($type, ['income', 'deposit'], true) ? 'income' : 'expense';
        $categoriesCol->updateOne(
            [
                'user_id' => new MongoDB\BSON\ObjectId($userId),
                'name' => $transaction['category']
            ],
            ['$inc' => ['total_spent' => $amount, 'transaction_count' => 1]]
        );
    }
    // 4. Update budget spent if expense category matches
    if (in_array($type, ['expense', 'payment', 'withdrawal'], true) && !empty($transaction['category'])) {
        $budgetsCol = getCollection('budgets');
        if ($budgetsCol) {
            $budgetsCol->updateOne(
                [
                    'user_id' => new MongoDB\BSON\ObjectId($userId),
                    'category' => $transaction['category'],
                    'is_active' => true
                ],
                ['$inc' => ['current_spent' => $amount]]
            );
        }
    }
    // 5. Update transaction status to approved (in case it was pending)
    $txCol = getCollection('transactions');
    if ($txCol && $transactionId) {
        $txCol->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
            [
                '$set' => [
                    'status' => 'approved',
                    'approved_at' => phpDateToMongo(),
                    'approved_by' => $transaction['approved_by'] ?? $transaction['created_by'] ?? null,
                    'requires_approval' => !empty($transaction['requires_approval']),
                    'updated_at' => phpDateToMongo()
                ]
            ]
        );
    }
    // 6. Notify customer
    $notificationsCol = getCollection('notifications');
    if ($notificationsCol) {
        $methodLabel = strtoupper($paymentMethod);
        $notificationsCol->insertOne([
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'type' => 'transaction',
            'title' => $transaction['requires_approval'] ? 'Transaction Approved' : 'Transaction Successful',
            'message' => $transaction['requires_approval']
                ? 'Your ' . $methodLabel . ' request of ' . $amount . ' ' . ($transaction['currency'] ?? 'INR') . ' has been approved.'
                : 'Your ' . $methodLabel . ' transaction of ' . $amount . ' ' . ($transaction['currency'] ?? 'INR') . ' was successful.',
            'read' => false,
            'link' => 'frontend/html/customer/transactions.html',
            'created_at' => phpDateToMongo()
        ]);
    }
    // 7. Add to audit log
    logActivity('transaction_approved', $transaction['approved_by'] ?? $userId, [
        'transaction_id' => $transactionId,
        'amount' => $amount,
        'type' => $type,
        'payment_method' => $paymentMethod,
        'customer_id' => $userId
    ]);
}

/**
 * Notify admin & staff of a pending approval request
 * @param string $transactionId
 * @param array $transaction
 */
function notifyApprovalRequired($transactionId, $transaction) {
    $usersCol = getCollection('users');
    if (!$usersCol) {
        return;
    }
    $notificationsCol = getCollection('notifications');
    if (!$notificationsCol) {
        return;
    }
    $approvers = $usersCol->find([
        'role' => ['$in' => ['admin', 'staff']],
        'status' => 'active'
    ], ['projection' => ['_id' => 1, 'first_name' => 1, 'last_name' => 1]]);
    $amount = $transaction['amount'];
    $method = strtoupper($transaction['payment_method'] ?? 'NEFT');
    $customerName = '';
    $customer = $usersCol->findOne(['_id' => $transaction['user_id']]);
    if ($customer) {
        $customerName = ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '');
    }
    foreach ($approvers as $approver) {
        $notificationsCol->insertOne([
            'user_id' => $approver['_id'],
            'type' => 'approval',
            'title' => 'New ' . $method . ' approval request',
            'message' => $customerName . ' has submitted a ' . $method . ' request of ' . $amount . ' ' . ($transaction['currency'] ?? 'INR') . '. Please review.',
            'read' => false,
            'link' => 'frontend/html/admin/transactions.html?pending=1',
            'created_at' => phpDateToMongo()
        ]);
    }
}

/**
 * Get transactions for approval (admin/staff)
 * GET: status (pending/approved/rejected), page, limit
 */
function getApprovalQueue() {
    requireRole(['admin', 'staff']);
    $status = $_GET['status'] ?? 'pending';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = [
        'status' => in_array($status, ['pending', 'approved', 'rejected', 'modification_requested'], true) ? $status : 'pending',
        'deleted_at' => null
    ];
    // Optional user filter
    if (!empty($_GET['user_id']) && isValidObjectId($_GET['user_id'])) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId($_GET['user_id']);
    }
    // Search
    $search = $_GET['search'] ?? '';
    if ($search !== '') {
        $filter['$or'] = [
            ['description' => new MongoDB\BSON\Regex($search, 'i')],
            ['recipient_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['account_number' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort' => ['created_at' => -1],
        'skip' => $skip,
        'limit' => $limit
    ]);
    $usersCol = getCollection('users');
    $list = [];
    foreach ($cursor as $t) {
        $customer = null;
        if (isset($t['user_id']) && $usersCol) {
            $customer = $usersCol->findOne(
                ['_id' => $t['user_id']],
                ['projection' => ['first_name' => 1, 'last_name' => 1, 'email' => 1, 'account_number' => 1]]
            );
        }
        $list[] = [
            'transaction_id' => (string)$t['_id'],
            'user_id' => isset($t['user_id']) ? (string)$t['user_id'] : '',
            'customer_name' => $customer ? ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '') : 'Unknown',
            'customer_email' => $customer['email'] ?? '',
            'customer_account' => $customer['account_number'] ?? '',
            'type' => $t['type'] ?? 'transfer',
            'category' => $t['category'] ?? '',
            'amount' => (float)($t['amount'] ?? 0),
            'currency' => $t['currency'] ?? 'INR',
            'description' => $t['description'] ?? '',
            'payment_method' => $t['payment_method'] ?? '',
            'recipient_name' => $t['recipient_name'] ?? '',
            'account_number' => $t['account_number'] ?? '',
            'ifsc_code' => $t['ifsc_code'] ?? '',
            'status' => $t['status'] ?? 'pending',
            'requires_approval' => (bool)($t['requires_approval'] ?? false),
            'rejection_reason' => $t['rejection_reason'] ?? null,
            'modification_message' => $t['modification_message'] ?? null,
            'date' => mongoDateToPHP($t['date'] ?? null)->format('Y-m-d'),
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse([
        'transactions' => $list,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ], 'Approval queue retrieved');
}

/**
 * Approve transaction (admin/staff)
 * POST: transaction_id, note
 */
function approveTransaction() {
    requireRole(['admin', 'staff']);
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transactionId = $data['transaction_id'] ?? '';
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $transaction = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transactionId),
        'status' => 'pending',
        'deleted_at' => null
    ]);
    if (!$transaction) {
        errorResponse('Transaction not found or already processed');
    }
    // Mark approved metadata
    $approvalNote = sanitizeInput($data['note'] ?? '');
    $approvedDoc = $transaction;
    $approvedDoc['approved_by'] = getCurrentUserId();
    $approvedDoc['approved_at'] = phpDateToMongo();
    $approvedDoc['status'] = 'approved';
    $approvedDoc['approval_note'] = $approvalNote;
    // Apply to ledger (this also updates the transaction status in DB)
    applyTransactionToLedger($transactionId, $approvedDoc);
    // Log activity
    logActivity('transaction_approved', getCurrentUserId(), [
        'transaction_id' => $transactionId,
        'customer_id' => (string)$transaction['user_id'],
        'amount' => $transaction['amount'],
        'payment_method' => $transaction['payment_method']
    ]);
    successResponse([
        'transaction_id' => $transactionId,
        'status' => 'approved'
    ], 'Transaction approved and customer balance updated');
}

/**
 * Reject transaction (admin/staff)
 * POST: transaction_id, reason
 */
function rejectTransaction() {
    requireRole(['admin', 'staff']);
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transactionId = $data['transaction_id'] ?? '';
    $reason = sanitizeInput($data['reason'] ?? '');
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    if (empty($reason)) {
        errorResponse('Rejection reason is required');
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $transaction = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transactionId),
        'status' => 'pending',
        'deleted_at' => null
    ]);
    if (!$transaction) {
        errorResponse('Transaction not found or already processed');
    }
    // Update transaction status
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
        [
            '$set' => [
                'status' => 'rejected',
                'rejected_by' => getCurrentUserId(),
                'rejected_at' => phpDateToMongo(),
                'rejection_reason' => $reason,
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Notify customer
    $notificationsCol = getCollection('notifications');
    if ($notificationsCol) {
        $notificationsCol->insertOne([
            'user_id' => $transaction['user_id'],
            'type' => 'transaction_rejected',
            'title' => 'Transaction Rejected',
            'message' => 'Your ' . strtoupper($transaction['payment_method'] ?? 'NEFT') . ' request of ' . $transaction['amount'] . ' ' . ($transaction['currency'] ?? 'INR') . ' was rejected. Reason: ' . $reason,
            'read' => false,
            'link' => 'frontend/html/customer/transactions.html',
            'created_at' => phpDateToMongo()
        ]);
    }
    // Log activity
    logActivity('transaction_rejected', getCurrentUserId(), [
        'transaction_id' => $transactionId,
        'customer_id' => (string)$transaction['user_id'],
        'amount' => $transaction['amount'],
        'reason' => $reason
    ]);
    successResponse([
        'transaction_id' => $transactionId,
        'status' => 'rejected'
    ], 'Transaction rejected. Customer notified.');
}

/**
 * Request modification for a pending transaction (admin/staff)
 * POST: transaction_id, message
 */
function requestTransactionModification() {
    requireRole(['admin', 'staff']);
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transactionId = $data['transaction_id'] ?? '';
    $message = sanitizeInput($data['message'] ?? '');
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    if (empty($message)) {
        errorResponse('Modification message is required');
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $transaction = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transactionId),
        'status' => ['$in' => ['pending', 'modification_requested']],
        'deleted_at' => null
    ]);
    if (!$transaction) {
        errorResponse('Transaction not found or already processed');
    }
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
        [
            '$set' => [
                'status' => 'modification_requested',
                'modification_requested' => true,
                'modification_message' => $message,
                'modification_requested_by' => getCurrentUserId(),
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Notify customer
    $notificationsCol = getCollection('notifications');
    if ($notificationsCol) {
        $notificationsCol->insertOne([
            'user_id' => $transaction['user_id'],
            'type' => 'modification_requested',
            'title' => 'Transaction Modification Requested',
            'message' => 'Your transaction request needs modification: ' . $message,
            'read' => false,
            'link' => 'frontend/html/customer/transactions.html',
            'created_at' => phpDateToMongo()
        ]);
    }
    logActivity('transaction_modification_requested', getCurrentUserId(), [
        'transaction_id' => $transactionId,
        'message' => $message
    ]);
    successResponse(null, 'Modification requested from customer');
}

/**
 * Customer: edit a pending / modification-requested transaction
 * POST: transaction_id + fields to update
 */
function editPendingTransaction() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transactionId = $data['transaction_id'] ?? '';
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $transaction = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transactionId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'status' => ['$in' => ['pending', 'modification_requested']],
        'deleted_at' => null
    ]);
    if (!$transaction) {
        errorResponse('Transaction not found or cannot be edited');
    }
    $updateData = ['updated_at' => phpDateToMongo()];
    // Allow editing these fields
    $editableFields = ['amount', 'description', 'category', 'recipient_name', 'account_number', 'ifsc_code', 'notes'];
    foreach ($editableFields as $field) {
        if (isset($data[$field])) {
            switch ($field) {
                case 'amount':
                    if (!is_numeric($data[$field]) || $data[$field] <= 0) {
                        errorResponse('Amount must be greater than 0');
                    }
                    $updateData['amount'] = (float)$data[$field];
                    break;
                case 'account_number':
                    if (!preg_match('/^\d{9,18}$/', $data[$field])) {
                        errorResponse('Valid account number required (9-18 digits)');
                    }
                    $updateData['account_number'] = $data[$field];
                    break;
                case 'ifsc_code':
                    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper($data[$field]))) {
                        errorResponse('Valid IFSC code required');
                    }
                    $updateData['ifsc_code'] = strtoupper($data[$field]);
                    break;
                default:
                    $updateData[$field] = sanitizeInput($data[$field]);
            }
        }
    }
    // Revert status to pending for re-review
    $updateData['status'] = 'pending';
    $updateData['modification_requested'] = false;
    $updateData['modification_message'] = null;
    $updateData['modification_requested_by'] = null;
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
        ['$set' => $updateData]
    );
    // Notify approvers
    $usersCol = getCollection('users');
    $notificationsCol = getCollection('notifications');
    if ($usersCol && $notificationsCol) {
        $approvers = $usersCol->find([
            'role' => ['$in' => ['admin', 'staff']],
            'status' => 'active'
        ], ['projection' => ['_id' => 1]]);
        foreach ($approvers as $approver) {
            $notificationsCol->insertOne([
                'user_id' => $approver['_id'],
                'type' => 'approval',
                'title' => 'Transaction updated for re-review',
                'message' => 'A customer has updated their transaction request. Please review again.',
                'read' => false,
                'link' => 'frontend/html/admin/transactions.html?pending=1',
                'created_at' => phpDateToMongo()
            ]);
        }
    }
    logActivity('transaction_edited', getCurrentUserId(), [
        'transaction_id' => $transactionId,
        'fields' => array_keys($updateData)
    ]);
    successResponse(null, 'Transaction updated and submitted for re-review');
}

/**
 * Customer: cancel a pending transaction
 * POST: transaction_id
 */
function cancelPendingTransaction() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transactionId = $data['transaction_id'] ?? '';
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $transaction = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($transactionId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'status' => ['$in' => ['pending', 'modification_requested']],
        'deleted_at' => null
    ]);
    if (!$transaction) {
        errorResponse('Transaction not found or cannot be cancelled');
    }
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
        [
            '$set' => [
                'status' => 'cancelled',
                'cancelled_by' => getCurrentUserId(),
                'cancelled_at' => phpDateToMongo(),
                'cancellation_reason' => 'Cancelled by customer',
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Notify approvers
    $usersCol = getCollection('users');
    $notificationsCol = getCollection('notifications');
    if ($usersCol && $notificationsCol) {
        $approvers = $usersCol->find([
            'role' => ['$in' => ['admin', 'staff']],
            'status' => 'active'
        ], ['projection' => ['_id' => 1]]);
        foreach ($approvers as $approver) {
            $notificationsCol->insertOne([
                'user_id' => $approver['_id'],
                'type' => 'approval',
                'title' => 'Transaction request cancelled',
                'message' => 'A customer has cancelled their pending transaction request.',
                'read' => false,
                'link' => 'frontend/html/admin/transactions.html?pending=1',
                'created_at' => phpDateToMongo()
            ]);
        }
    }
    logActivity('transaction_cancelled', getCurrentUserId(), ['transaction_id' => $transactionId]);
    successResponse(null, 'Transaction request cancelled');
}

/**
 * Customer: get their transactions with status filtering
 * GET: status (pending/approved/rejected/cancelled/all), page, limit
 */
function getCustomerTransactions() {
    requireActiveSession();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = [
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ];
    $status = $_GET['status'] ?? 'all';
    $validStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'modification_requested'];
    if (in_array($status, $validStatuses, true)) {
        $filter['status'] = $status;
    }
    // Optional search
    $search = $_GET['search'] ?? '';
    if ($search !== '') {
        $filter['$or'] = [
            ['description' => new MongoDB\BSON\Regex($search, 'i')],
            ['recipient_name' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort' => ['created_at' => -1],
        'skip' => $skip,
        'limit' => $limit
    ]);
    $list = [];
    foreach ($cursor as $t) {
        $list[] = [
            'transaction_id' => (string)$t['_id'],
            'type' => $t['type'] ?? 'transfer',
            'category' => $t['category'] ?? '',
            'amount' => (float)($t['amount'] ?? 0),
            'currency' => $t['currency'] ?? 'INR',
            'description' => $t['description'] ?? '',
            'payment_method' => $t['payment_method'] ?? '',
            'recipient_name' => $t['recipient_name'] ?? '',
            'status' => $t['status'] ?? 'pending',
            'requires_approval' => (bool)($t['requires_approval'] ?? false),
            'rejection_reason' => $t['rejection_reason'] ?? null,
            'modification_message' => $t['modification_message'] ?? null,
            'approved_at' => isset($t['approved_at']) ? mongoDateToPHP($t['approved_at'])->format('Y-m-d H:i:s') : null,
            'rejected_at' => isset($t['rejected_at']) ? mongoDateToPHP($t['rejected_at'])->format('Y-m-d H:i:s') : null,
            'date' => mongoDateToPHP($t['date'] ?? null)->format('Y-m-d'),
            'created_at' => mongoDateToPHP($t['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse([
        'transactions' => $list,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ], 'Transactions retrieved');
}

/**
 * Get pending count for admin/staff dashboard
 */
function getPendingApprovalCount() {
    requireRole(['admin', 'staff']);
    $collection = getCollection('transactions');
    if (!$collection) {
        successResponse(['pending_count' => 0]);
    }
    $count = $collection->countDocuments([
        'status' => 'pending',
        'deleted_at' => null
    ]);
    successResponse(['pending_count' => $count]);
}

/**
 * Admin: soft delete / restore transaction
 * POST: transaction_id, action (delete/restore)
 */
function adminDeleteRestoreTransaction() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $transactionId = $data['transaction_id'] ?? '';
    $action = $data['action'] ?? '';
    if (!isValidObjectId($transactionId)) {
        errorResponse('Invalid transaction ID');
    }
    if (!in_array($action, ['delete', 'restore'], true)) {
        errorResponse('Invalid action');
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    if ($action === 'delete') {
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
            [
                '$set' => [
                    'deleted_at' => phpDateToMongo(),
                    'deleted_by' => getCurrentUserId(),
                    'updated_at' => phpDateToMongo()
                ]
            ]
        );
        logActivity('transaction_deleted', getCurrentUserId(), ['transaction_id' => $transactionId]);
        successResponse(null, 'Transaction moved to trash');
    } else {
        $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($transactionId)],
            [
                '$set' => [
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'updated_at' => phpDateToMongo()
                ]
            ]
        );
        logActivity('transaction_restored', getCurrentUserId(), ['transaction_id' => $transactionId]);
        successResponse(null, 'Transaction restored');
    }
}

/**
 * Admin: bulk delete transactions (soft delete)
 * POST: transaction_ids []
 */
function bulkDeleteTransactions() {
    requireRole(['admin']);
    $data = getJSONRequest();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $ids = $data['transaction_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        errorResponse('No transactions selected');
    }
    $objectIds = [];
    foreach ($ids as $id) {
        if (isValidObjectId($id)) {
            $objectIds[] = new MongoDB\BSON\ObjectId($id);
        }
    }
    if (empty($objectIds)) {
        errorResponse('No valid transaction IDs');
    }
    $collection = getCollection('transactions');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->updateMany(
        ['_id' => ['$in' => $objectIds]],
        [
            '$set' => [
                'deleted_at' => phpDateToMongo(),
                'deleted_by' => getCurrentUserId(),
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    logActivity('transactions_bulk_deleted', getCurrentUserId(), ['count' => $result->getModifiedCount()]);
    successResponse(['deleted_count' => $result->getModifiedCount()], 'Transactions moved to trash');
}

// Route handling
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'create_request':
        if ($method === 'POST') createTransactionRequest();
        break;
    case 'approval_queue':
        if ($method === 'GET') getApprovalQueue();
        break;
    case 'approve':
        if ($method === 'POST') approveTransaction();
        break;
    case 'reject':
        if ($method === 'POST') rejectTransaction();
        break;
    case 'request_modification':
        if ($method === 'POST') requestTransactionModification();
        break;
    case 'edit_pending':
        if ($method === 'POST') editPendingTransaction();
        break;
    case 'cancel_pending':
        if ($method === 'POST') cancelPendingTransaction();
        break;
    case 'my_transactions':
        if ($method === 'GET') getCustomerTransactions();
        break;
    case 'pending_count':
        if ($method === 'GET') getPendingApprovalCount();
        break;
    case 'admin_delete':
        if ($method === 'POST') adminDeleteRestoreTransaction();
        break;
    case 'admin_restore':
        if ($method === 'POST') adminDeleteRestoreTransaction();
        break;
    case 'bulk_delete':
        if ($method === 'POST') bulkDeleteTransactions();
        break;
    default:
        errorResponse('Invalid action');
}
?>