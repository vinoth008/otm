<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, branch_name, branch_code, city, phone, status FROM branches ORDER BY id DESC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

if ($method === 'POST' && $action === 'create') {
    $stmt = $pdo->prepare("INSERT INTO branches (branch_name, branch_code, city, phone, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $input['branch_name'] ?? '',
        $input['branch_code'] ?? '',
        $input['city'] ?? '',
        $input['phone'] ?? '',
        $input['status'] ?? 'Active'
    ]);
    jsonResponse(['success' => true, 'message' => 'Branch created']);
}

if ($method === 'POST' && $action === 'update') {
    $stmt = $pdo->prepare("UPDATE branches SET branch_name=?, branch_code=?, city=?, phone=?, status=? WHERE id=?");
    $stmt->execute([
        $input['branch_name'] ?? '',
        $input['branch_code'] ?? '',
        $input['city'] ?? '',
        $input['phone'] ?? '',
        $input['status'] ?? 'Active',
        (int)($input['id'] ?? 0)
    ]);
    jsonResponse(['success' => true, 'message' => 'Branch updated']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);