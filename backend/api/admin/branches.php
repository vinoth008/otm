<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireRole(['admin']);

$data = getRequestData();
$action = $data['action'] ?? ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get_all';
}

$collection = getCollection('branches');
if (!$collection) {
    errorResponse('Database connection error');
}

switch ($action) {
    case 'get_all':
        $cursor = $collection->find([], ['sort' => ['created_at' => -1]]);
        $branches = [];
        foreach ($cursor as $b) {
            $branches[] = [
                'id' => (string)$b['_id'],
                'name' => $b['name'] ?? '',
                'address' => $b['address'] ?? '',
                'phone' => $b['phone'] ?? '',
                'status' => $b['status'] ?? 'active',
                'created_at' => isset($b['created_at']) ? mongoDateToPHP($b['created_at'])->format('Y-m-d H:i:s') : ''
            ];
        }
        successResponse(['branches' => $branches], 'Branches retrieved');
        break;

    case 'create':
        $name = sanitizeInput($data['name'] ?? '');
        $address = sanitizeInput($data['address'] ?? '');
        $phone = sanitizeInput($data['phone'] ?? '');
        $status = sanitizeInput($data['status'] ?? 'active');
        if (empty($name)) {
            errorResponse('Branch name is required');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        $result = $collection->insertOne([
            'name' => $name,
            'address' => $address,
            'phone' => $phone,
            'status' => $status,
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]);
        logActivity('admin_branch_created', getCurrentUserId(), ['target' => (string)$result->getInsertedId()]);
        successResponse(['id' => (string)$result->getInsertedId()], 'Branch created successfully');
        break;

    case 'update':
        $id = $data['id'] ?? '';
        if (!isValidObjectId($id)) {
            errorResponse('Invalid branch ID');
        }
        $branch = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
        if (!$branch) {
            errorResponse('Branch not found');
        }
        $update = ['updated_at' => phpDateToMongo()];
        if (isset($data['name'])) {
            $update['name'] = sanitizeInput($data['name']);
        }
        if (isset($data['address'])) {
            $update['address'] = sanitizeInput($data['address']);
        }
        if (isset($data['phone'])) {
            $update['phone'] = sanitizeInput($data['phone']);
        }
        if (isset($data['status'])) {
            $update['status'] = sanitizeInput($data['status']);
        }
        $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $update]);
        logActivity('admin_branch_updated', getCurrentUserId(), ['target' => $id]);
        successResponse(null, 'Branch updated successfully');
        break;

    case 'delete':
        $id = $data['id'] ?? '';
        if (!isValidObjectId($id)) {
            errorResponse('Invalid branch ID');
        }
        $collection->deleteOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
        logActivity('admin_branch_deleted', getCurrentUserId(), ['target' => $id]);
        successResponse(null, 'Branch deleted successfully');
        break;

    default:
        errorResponse('Invalid action', 400);
}
