<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, ref_no, username, txn_type, amount, status, created_at FROM transactions ORDER BY id DESC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

if ($method === 'POST' && $action === 'update_status') {
    $stmt = $pdo->prepare("UPDATE transactions SET status=? WHERE id=?");
    $stmt->execute([
        $input['status'] ?? 'Pending',
        (int)($input['id'] ?? 0)
    ]);
    jsonResponse(['success' => true, 'message' => 'Transaction status updated']);
}

if ($method === 'POST' && $action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id=?");
    $stmt->execute([(int)($input['id'] ?? 0)]);
    jsonResponse(['success' => true, 'message' => 'Transaction deleted']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);