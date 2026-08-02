<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, ticket_no, customer_name, category, priority, status FROM complaints ORDER BY id DESC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

if ($method === 'POST' && $action === 'update_status') {
    $stmt = $pdo->prepare("UPDATE complaints SET status=?, staff_reply=? WHERE id=?");
    $stmt->execute([
        $input['status'] ?? 'Open',
        $input['staff_reply'] ?? '',
        (int)($input['id'] ?? 0)
    ]);
    jsonResponse(['success' => true, 'message' => 'Complaint updated']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);