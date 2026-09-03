<?php
/**
 * Customer self-registration endpoint.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$data = getRequestData();
$firstName = sanitizeInput($data['first_name'] ?? '');
$lastName = sanitizeInput($data['last_name'] ?? '');
$email = strtolower(trim(sanitizeInput($data['email'] ?? '')));
$phone = sanitizeInput($data['phone'] ?? '');
$password = $data['password'] ?? '';
$accountType = sanitizeInput($data['account_type'] ?? 'savings');

if (empty($firstName) || empty($lastName) || empty($email) || empty($phone) || empty($password)) {
    errorResponse('All fields are required');
}
if (!validateEmail($email)) {
    errorResponse('Invalid email format');
}
if (empty($phone) || !validatePhone($phone)) {
    errorResponse('Invalid phone number. Enter a valid 10-digit Indian mobile number');
}
$passwordValidation = validatePasswordStrength($password);
if (!$passwordValidation['valid']) {
    errorResponse(implode(', ', $passwordValidation['errors']));
}

$validAccountTypes = ['savings', 'current', 'salary', 'fixed'];
if (!in_array($accountType, $validAccountTypes, true)) {
    $accountType = 'savings';
}

$collection = getCollection('users');
if (!$collection) {
    errorResponse('Database connection error', 500);
}

$existing = $collection->findOne(['email' => $email, 'deleted_at' => null]);
if ($existing) {
    errorResponse('Email already registered');
}

$accountNumber = generateAccountNumber();
$userDocument = [
    'email' => $email,
    'password_hash' => hashPassword($password),
    'first_name' => $firstName,
    'last_name' => $lastName,
    'phone' => $phone,
    'role' => 'customer',
    'status' => 'active',
    'is_verified' => true,
    'account_number' => $accountNumber,
    'account_type' => $accountType,
    'balance' => 0.0,
    'login_attempts' => 0,
    'locked_until' => null,
    'created_at' => phpDateToMongo(),
    'updated_at' => phpDateToMongo(),
    'deleted_at' => null
];

$result = $collection->insertOne($userDocument);
if (!$result->getInsertedId()) {
    errorResponse('Registration failed. Please try again.', 500);
}
$userId = (string)$result->getInsertedId();

$walletCollection = getCollection('wallets');
if ($walletCollection) {
    $walletCollection->insertOne([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'name' => 'Main Account',
        'account_number' => $accountNumber,
        'balance' => 0,
        'currency' => 'INR',
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
}

$notificationCollection = getCollection('notifications');
if ($notificationCollection) {
    $notificationCollection->insertOne([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'type' => 'account',
        'title' => 'Welcome to ' . APP_NAME,
        'message' => 'Your account has been created successfully. Your account number is ' . $accountNumber . '.',
        'read' => false,
        'link' => '',
        'created_at' => phpDateToMongo()
    ]);
}

logActivity('user_registered', $userId, ['email' => $email, 'account_number' => $accountNumber]);

$userDocument['_id'] = $result->getInsertedId();
createUserSession($userDocument);

successResponse([
    'user_id' => $userId,
    'email' => $email,
    'name' => $firstName . ' ' . $lastName,
    'role' => 'customer',
    'redirect' => getRoleDashboardUrl()
], 'Registration successful');

/**
 * Generate a unique 16-digit account number starting with 5.
 * @return string
 */
function generateAccountNumber() {
    $collection = getCollection('users');
    do {
        $number = '5' . random_int(100000000000000, 999999999999999);
        if (!$collection) {
            break;
        }
        $exists = $collection->findOne(['account_number' => $number]);
    } while ($exists);
    return $number;
}
