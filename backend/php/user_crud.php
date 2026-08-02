<?php
// backend/php/user_crud.php
/**
 * User CRUD Operations for Smart Transaction Control
 * Handles profile management, user data operations
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
 * Get user profile
 */
function getUserProfile() {
    requireActiveSession();
    $collection = getCollection('users');
    $user = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
    ], [
        'projection' => [
            'password_hash' => 0,
            'login_attempts' => 0,
            'locked_until' => 0
        ]
    ]);
    if (!$user) {
        errorResponse('User not found');
    }
    successResponse($user);
}
/**
 * Update user profile
 * POST: first_name, last_name, phone, currency
 */
function updateUserProfile() {
    requireActiveSession();
    $data = getRequestData();
    if (!$data) {
        errorResponse('Invalid request format');
    }
    // Verify CSRF token
    $csrf = $data['csrf_token'] ?? $_COOKIE['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrf)) {
        errorResponse('Invalid security token');
    }
    $firstName = sanitizeInput($data['first_name'] ?? '');
    $lastName = sanitizeInput($data['last_name'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    $currency = sanitizeInput($data['currency'] ?? 'INR');
    if (empty($firstName)) {
        errorResponse('First name is required');
    }
    if (!validatePhone($phone)) {
        errorResponse('Invalid phone number');
    }
    $updateData = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'phone' => $phone,
        'currency' => $currency,
        'updated_at' => phpDateToMongo()
    ];
    $collection = getCollection('users');
    $result = $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())],
        ['$set' => $updateData]
    );
    if ($result->getModifiedCount() === 0 && $result->getUpsertedCount() === 0) {
        errorResponse('No changes made');
    }
    // Update session
    $_SESSION['user_name'] = $firstName . ' ' . $lastName;
    $_SESSION['user_currency'] = $currency;
    // Log activity
    logActivity('profile_updated', getCurrentUserId(), $updateData);
    successResponse(null, 'Profile updated successfully');
}
/**
 * Upload profile photo
 * POST: profile_photo (file)
 */
function uploadProfilePhoto() {
    requireActiveSession();
    if (!isset($_FILES['profile_photo'])) {
        errorResponse('No file uploaded');
    }
    $file = $_FILES['profile_photo'];
    $validation = validateFileUpload($file);
    if (!$validation['success']) {
        errorResponse($validation['error']);
    }
    // Create upload directory if not exists
    $uploadDir = UPLOAD_DIR . 'profile_photos/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    // Move uploaded file
    $destination = $uploadDir . $validation['filename'];
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        errorResponse('File upload failed');
    }
    // Generate URL
    $photoUrl = BASE_URL . 'uploads/profile_photos/' . $validation['filename'];
    // Update user
    $collection = getCollection('users');
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())],
        [
            '$set' => [
                'profile_photo' => $photoUrl,
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Log activity
    logActivity('profile_photo_uploaded', getCurrentUserId());
    successResponse(['photo_url' => $photoUrl], 'Profile photo uploaded successfully');
}
/**
 * Delete user account (soft delete)
 */
function deleteUserAccount() {
    requireActiveSession();
    $data = getRequestData();
    // Verify CSRF token (cookie fallback for simple POST calls)
    $csrf = ($data['csrf_token'] ?? '') ?: ($_COOKIE['csrf_token'] ?? '');
    if (!verifyCSRFToken($csrf)) {
        errorResponse('Invalid security token');
    }
    $userId = getCurrentUserId();
    // Soft delete - mark as deleted
    $collection = getCollection('users');
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($userId)],
        [
            '$set' => [
                'status' => 'deleted',
                'deleted_at' => phpDateToMongo(),
                'updated_at' => phpDateToMongo()
            ]
        ]
    );
    // Also mark user's transactions as deleted (optional)
    $transactionsCollection = getCollection('transactions');
    $transactionsCollection->updateMany(
        ['user_id' => new MongoDB\BSON\ObjectId($userId)],
        [
            '$set' => [
                'deleted_at' => phpDateToMongo()
            ]
        ]
    );
    // Log activity
    logActivity('account_deleted', $userId);

    // Destroy session
    destroySession();
    successResponse(null, 'Account deleted successfully');
}
/**
 * Get user statistics
 */
function getUserStatistics() {
    requireActiveSession();
    $userId = getCurrentUserId();
    $transactionsCollection = getCollection('transactions');
    // Total transactions
    $totalTransactions = $transactionsCollection->countDocuments([
        'user_id' => new MongoDB\BSON\ObjectId($userId)
    ]);
    // Total income
    $incomePipeline = [
        ['$match' => [
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'type' => 'income'
        ]],
        ['$group' => [
            '_id' => null,
            'total' => ['$sum' => '$amount']
        ]]
    ];
    $incomeResult = $transactionsCollection->aggregate($incomePipeline)->toArray();
    $totalIncome = $incomeResult[0]['total'] ?? 0;
    // Total expense
    $expensePipeline = [
        ['$match' => [
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'type' => 'expense'
        ]],
        ['$group' => [
            '_id' => null,
            'total' => ['$sum' => '$amount']
        ]]
    ];
    $expenseResult = $transactionsCollection->aggregate($expensePipeline)->toArray();
    $totalExpense = $expenseResult[0]['total'] ?? 0;
    // Balance
    $balance = $totalIncome - $totalExpense;
    // This month's transactions
    $firstDayOfMonth = date('Y-m-01');
    $monthlyTransactions = $transactionsCollection->countDocuments([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'date' => ['$gte' => new MongoDB\BSON\UTCDateTime(strtotime($firstDayOfMonth) * 1000)]
    ]);
    successResponse([
        'total_transactions' => $totalTransactions,
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'balance' => $balance,
        'monthly_transactions' => $monthlyTransactions
    ]);
}
// Route handling
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'profile':
            getUserProfile();
            break;
        case 'statistics':
            getUserStatistics();
            break;
        default:
            errorResponse('Invalid action');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'update':
        case 'update_profile':
            updateUserProfile();
            break;
        case 'upload_photo':
            uploadProfilePhoto();
            break;
        case 'delete_account':
            deleteUserAccount();
            break;
        default:
            errorResponse('Invalid action');
    }
} else {
    errorResponse('Method not allowed');
}
?>
