<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, full_name, username, email, role, status FROM users ORDER BY id DESC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

if ($method === 'POST' && $action === 'create') {
    $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, role, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $input['full_name'] ?? '',
        $input['username'] ?? '',
        $input['email'] ?? '',
        $input['role'] ?? 'Customer',
        $input['status'] ?? 'Active'
    ]);
    jsonResponse(['success' => true, 'message' => 'User created']);
}

if ($method === 'POST' && $action === 'update') {
    $stmt = $pdo->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, status=? WHERE id=?");
    $stmt->execute([
        $input['full_name'] ?? '',
        $input['username'] ?? '',
        $input['email'] ?? '',
        $input['role'] ?? 'Customer',
        $input['status'] ?? 'Active',
        (int)($input['id'] ?? 0)
    ]);
    jsonResponse(['success' => true, 'message' => 'User updated']);
}

if ($method === 'POST' && $action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
    $stmt->execute([(int)($input['id'] ?? 0)]);
    jsonResponse(['success' => true, 'message' => 'User deleted']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);