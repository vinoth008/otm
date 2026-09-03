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

$collection = getCollection('roles');
if (!$collection) {
    errorResponse('Database connection error');
}

$canonicalRoles = ['admin', 'staff', 'receptionist', 'customer'];

switch ($action) {
    case 'get_all':
    case 'get_roles':
        $cursor = $collection->find([], ['sort' => ['created_at' => 1]]);
        $roles = [];
        foreach ($cursor as $r) {
            $roles[] = [
                'id' => (string)$r['_id'],
                'code' => $r['code'] ?? '',
                'name' => $r['name'] ?? '',
                'description' => $r['description'] ?? '',
                'permissions' => $r['permissions'] ?? []
            ];
        }
        successResponse(['roles' => $roles], 'Roles retrieved');
        break;

    case 'create':
        $code = sanitizeInput($data['code'] ?? '');
        $name = sanitizeInput($data['name'] ?? '');
        $description = sanitizeInput($data['description'] ?? '');
        $permissions = $data['permissions'] ?? [];
        if (empty($code) || empty($name)) {
            errorResponse('Role code and name are required');
        }
        $code = strtolower($code);
        if (in_array($code, $canonicalRoles, true)) {
            errorResponse('Cannot create reserved role: ' . $code);
        }
        $existing = $collection->findOne(['code' => $code]);
        if ($existing) {
            errorResponse('Role code already exists');
        }
        if (!is_array($permissions)) {
            $permissions = [];
        }
        $result = $collection->insertOne([
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'permissions' => $permissions,
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]);
        logActivity('admin_role_created', getCurrentUserId(), ['target' => (string)$result->getInsertedId()]);
        successResponse(['id' => (string)$result->getInsertedId()], 'Role created successfully');
        break;

    case 'update':
        $id = $data['id'] ?? '';
        if (!isValidObjectId($id)) {
            errorResponse('Invalid role ID');
        }
        $role = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
        if (!$role) {
            errorResponse('Role not found');
        }
        $update = ['updated_at' => phpDateToMongo()];
        if (isset($data['name'])) {
            $update['name'] = sanitizeInput($data['name']);
        }
        if (isset($data['description'])) {
            $update['description'] = sanitizeInput($data['description']);
        }
        if (isset($data['permissions'])) {
            $update['permissions'] = is_array($data['permissions']) ? $data['permissions'] : [];
        }
        $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $update]);
        logActivity('admin_role_updated', getCurrentUserId(), ['target' => $id]);
        successResponse(null, 'Role updated successfully');
        break;

    case 'delete':
        $id = $data['id'] ?? '';
        if (!isValidObjectId($id)) {
            errorResponse('Invalid role ID');
        }
        $role = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
        if ($role && in_array($role['code'] ?? '', $canonicalRoles, true)) {
            errorResponse('Cannot delete reserved role');
        }
        $collection->deleteOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
        logActivity('admin_role_deleted', getCurrentUserId(), ['target' => $id]);
        successResponse(null, 'Role deleted successfully');
        break;

    default:
        errorResponse('Invalid action', 400);
}
