<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, role_name, role_code, permissions, status FROM roles ORDER BY id DESC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

if ($method === 'POST' && $action === 'create') {
    $stmt = $pdo->prepare("INSERT INTO roles (role_name, role_code, permissions, status) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $input['role_name'] ?? '',
        $input['role_code'] ?? '',
        $input['permissions'] ?? '',
        $input['status'] ?? 'Active'
    ]);
    jsonResponse(['success' => true, 'message' => 'Role created']);
}

if ($method === 'POST' && $action === 'update') {
    $stmt = $pdo->prepare("UPDATE roles SET role_name=?, role_code=?, permissions=?, status=? WHERE id=?");
    $stmt->execute([
        $input['role_name'] ?? '',
        $input['role_code'] ?? '',
        $input['permissions'] ?? '',
        $input['status'] ?? 'Active',
        (int)($input['id'] ?? 0)
    ]);
    jsonResponse(['success' => true, 'message' => 'Role updated']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);