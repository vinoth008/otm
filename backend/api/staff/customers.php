<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, full_name, username, mobile, status FROM customers ORDER BY id DESC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

if ($method === 'POST' && $action === 'create') {
    $stmt = $pdo->prepare("INSERT INTO customers (full_name, username, email, mobile, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $input['full_name'] ?? '',
        $input['username'] ?? '',
        $input['email'] ?? '',
        $input['mobile'] ?? '',
        $input['status'] ?? 'Pending'
    ]);
    jsonResponse(['success' => true, 'message' => 'Customer created']);
}

if ($method === 'POST' && $action === 'update') {
    $stmt = $pdo->prepare("UPDATE customers SET full_name=?, username=?, email=?, mobile=?, status=? WHERE id=?");
    $stmt->execute([
        $input['full_name'] ?? '',
        $input['username'] ?? '',
        $input['email'] ?? '',
        $input['mobile'] ?? '',
        $input['status'] ?? 'Pending',
        (int)($input['id'] ?? 0)
    ]);
    jsonResponse(['success' => true, 'message' => 'Customer updated']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);