<?php
// backend/php/beneficiary_crud.php
/**
 * Beneficiary Management for Smart Transaction Control
 * Handles saved beneficiaries for quick transfers
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
 * Get all beneficiaries for current user
 * GET
 */
function getBeneficiaries() {
    requireActiveSession();
    $collection = getCollection('beneficiaries');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $userId = new MongoDB\BSON\ObjectId(getCurrentUserId());
    $cursor = $collection->find(
        ['user_id' => $userId, 'deleted_at' => null],
        ['sort' => ['created_at' => -1]]
    );
    $list = [];
    foreach ($cursor as $b) {
        $list[] = [
            'beneficiary_id' => (string)$b['_id'],
            'name' => $b['name'] ?? '',
            'email' => $b['email'] ?? '',
            'phone' => $b['phone'] ?? '',
            'account_number' => $b['account_number'] ?? '',
            'ifsc_code' => $b['ifsc_code'] ?? '',
            'bank_name' => $b['bank_name'] ?? '',
            'nickname' => $b['nickname'] ?? '',
            'created_at' => mongoDateToPHP($b['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['beneficiaries' => $list], 'Beneficiaries retrieved');
}
/**
 * Create a new beneficiary
 * POST: name, email, phone, bank_name, account_number, ifsc_code, nickname
 */
function createBeneficiary() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $name = sanitizeInput($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $phone = sanitizeInput($data['phone'] ?? '');
    $bankName = sanitizeInput($data['bank_name'] ?? '');
    $accountNumber = sanitizeInput($data['account_number'] ?? '');
    $ifscCode = strtoupper(sanitizeInput($data['ifsc_code'] ?? ''));
    $nickname = sanitizeInput($data['nickname'] ?? '');
    if (empty($name)) {
        errorResponse('Beneficiary name is required');
    }
    if (empty($accountNumber)) {
        errorResponse('Account number is required');
    }
    if (!empty($email) && !validateEmail($email)) {
        errorResponse('Enter a valid email');
    }
    if (!empty($phone) && !validatePhone($phone)) {
        errorResponse('Enter a valid phone number');
    }
    $collection = getCollection('beneficiaries');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->insertOne([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'bank_name' => $bankName,
        'account_number' => $accountNumber,
        'ifsc_code' => $ifscCode,
        'nickname' => $nickname,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('beneficiary_created', getCurrentUserId(), [
        'beneficiary_id' => (string)$result->getInsertedId(),
        'name' => $name
    ]);
    successResponse(['beneficiary_id' => (string)$result->getInsertedId()], 'Beneficiary added successfully');
}
/**
 * Update a beneficiary
 * POST: beneficiary_id, name, email, phone, bank_name, account_number, ifsc_code, nickname
 */
function updateBeneficiary() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $beneficiaryId = $data['beneficiary_id'] ?? '';
    if (!isValidObjectId($beneficiaryId)) {
        errorResponse('Invalid beneficiary ID');
    }
    $collection = getCollection('beneficiaries');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $beneficiary = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($beneficiaryId),
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'deleted_at' => null
    ]);
    if (!$beneficiary) {
        errorResponse('Beneficiary not found');
    }
    $name = sanitizeInput($data['name'] ?? $beneficiary['name']);
    $email = strtolower(trim($data['email'] ?? $beneficiary['email'] ?? ''));
    $phone = sanitizeInput($data['phone'] ?? $beneficiary['phone'] ?? '');
    $bankName = sanitizeInput($data['bank_name'] ?? $beneficiary['bank_name'] ?? '');
    $accountNumber = sanitizeInput($data['account_number'] ?? $beneficiary['account_number'] ?? '');
    $ifscCode = strtoupper(sanitizeInput($data['ifsc_code'] ?? $beneficiary['ifsc_code'] ?? ''));
    $nickname = sanitizeInput($data['nickname'] ?? $beneficiary['nickname'] ?? '');
    if (empty($name) || empty($accountNumber)) {
        errorResponse('Name and account number are required');
    }
    $collection->updateOne(
        ['_id' => $beneficiary['_id']],
        ['$set' => [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'bank_name' => $bankName,
            'account_number' => $accountNumber,
            'ifsc_code' => $ifscCode,
            'nickname' => $nickname,
            'updated_at' => phpDateToMongo()
        ]]
    );
    logActivity('beneficiary_updated', getCurrentUserId(), ['beneficiary_id' => $beneficiaryId]);
    successResponse(null, 'Beneficiary updated successfully');
}
/**
 * Delete a beneficiary
 * POST: beneficiary_id
 */
function deleteBeneficiary() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $beneficiaryId = $data['beneficiary_id'] ?? '';
    if (!isValidObjectId($beneficiaryId)) {
        errorResponse('Invalid beneficiary ID');
    }
    $collection = getCollection('beneficiaries');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->updateOne(
        [
            '_id' => new MongoDB\BSON\ObjectId($beneficiaryId),
            'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())
        ],
        ['$set' => ['deleted_at' => phpDateToMongo()]]
    );
    if ($result->getModifiedCount() === 0 && $result->getMatchedCount() === 0) {
        errorResponse('Beneficiary not found');
    }
    logActivity('beneficiary_deleted', getCurrentUserId(), ['beneficiary_id' => $beneficiaryId]);
    successResponse(null, 'Beneficiary deleted successfully');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getBeneficiaries();
        break;
    case 'create':
        if ($method === 'POST') createBeneficiary();
        break;
    case 'update':
        if ($method === 'POST') updateBeneficiary();
        break;
    case 'delete':
        if ($method === 'POST') deleteBeneficiary();
        break;
    default:
        errorResponse('Invalid action');
}
?>
