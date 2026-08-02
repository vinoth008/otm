<?php
// backend/php/branch_crud.php
/**
 * Branch Management for Smart Transaction Control
 * Handles branches for admin and customer-facing branch listings
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
 * Get all branches (public for logged-in users)
 * GET
 */
function getAllBranches() {
    requireActiveSession();
    $collection = getCollection('branches');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $cursor = $collection->find([], ['sort' => ['branch_name' => 1, 'name' => 1]]);
    $list = [];
    foreach ($cursor as $b) {
        $list[] = [
            'branch_id' => (string)$b['_id'],
            'name' => $b['name'] ?? $b['branch_name'] ?? '',
            'code' => $b['code'] ?? $b['branch_code'] ?? '',
            'address' => $b['address'] ?? $b['address_line1'] ?? '',
            'city' => $b['city'] ?? '',
            'state' => $b['state'] ?? '',
            'pincode' => $b['pincode'] ?? '',
            'phone' => $b['phone'] ?? '',
            'email' => $b['email'] ?? '',
            'manager_name' => $b['manager_name'] ?? '',
            'status' => $b['status'] ?? 'active',
            'opening_hours' => $b['opening_hours'] ?? '',
            'created_at' => mongoDateToPHP($b['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['branches' => $list], 'Branches retrieved');
}
/**
 * Admin: create a branch
 * POST: name, code, address, city, phone, email, manager_name, opening_hours
 */
function createBranch() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $name = sanitizeInput($data['name'] ?? '');
    $code = strtoupper(sanitizeInput($data['code'] ?? ''));
    $address = sanitizeInput($data['address'] ?? '');
    $city = sanitizeInput($data['city'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    if (empty($name) || empty($code) || empty($address) || empty($city)) {
        errorResponse('Name, code, address and city are required');
    }
    $collection = getCollection('branches');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $exists = $collection->findOne(['code' => $code]);
    if ($exists) {
        errorResponse('Branch code already exists');
    }
    $result = $collection->insertOne([
        'name' => $name,
        'code' => $code,
        'address' => $address,
        'city' => $city,
        'phone' => $phone,
        'email' => strtolower(trim($data['email'] ?? '')),
        'manager_name' => sanitizeInput($data['manager_name'] ?? ''),
        'status' => 'active',
        'opening_hours' => sanitizeInput($data['opening_hours'] ?? '9:00 AM - 5:00 PM'),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('branch_created', getCurrentUserId(), [
        'branch_id' => (string)$result->getInsertedId(),
        'name' => $name
    ]);
    successResponse(['branch_id' => (string)$result->getInsertedId()], 'Branch created successfully');
}
/**
 * Admin: update a branch
 * POST: branch_id, name, address, city, phone, email, manager_name, status, opening_hours
 */
function updateBranch() {
    requireRole(['admin']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $branchId = $data['branch_id'] ?? '';
    if (!isValidObjectId($branchId)) {
        errorResponse('Invalid branch ID');
    }
    $collection = getCollection('branches');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $branch = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($branchId)]);
    if (!$branch) {
        errorResponse('Branch not found');
    }
    $update = ['updated_at' => phpDateToMongo()];
    foreach (['name', 'address', 'city', 'phone', 'email', 'manager_name', 'status', 'opening_hours'] as $field) {
        if (array_key_exists($field, $data)) {
            $update[$field] = sanitizeInput($data[$field]);
        }
    }
    $collection->updateOne(['_id' => $branch['_id']], ['$set' => $update]);
    logActivity('branch_updated', getCurrentUserId(), ['branch_id' => $branchId]);
    successResponse(null, 'Branch updated successfully');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getAllBranches();
        break;
    case 'create':
        if ($method === 'POST') createBranch();
        break;
    case 'update':
        if ($method === 'POST') updateBranch();
        break;
    default:
        errorResponse('Invalid action');
}
?>
