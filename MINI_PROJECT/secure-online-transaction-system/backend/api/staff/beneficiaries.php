<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, customer_name, beneficiary_name, bank_name, account_no, ifsc FROM beneficiaries ORDER BY id DESC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

if ($method === 'POST' && $action === 'create') {
    $stmt = $pdo->prepare("INSERT INTO beneficiaries (customer_name, beneficiary_name, bank_name, account_no, ifsc) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $input['customer_name'] ?? '',
        $input['beneficiary_name'] ?? '',
        $input['bank_name'] ?? '',
        $input['account_no'] ?? '',
        $input['ifsc'] ?? ''
    ]);
    jsonResponse(['success' => true, 'message' => 'Beneficiary created']);
}

if ($method === 'POST' && $action === 'update') {
    $stmt = $pdo->prepare("UPDATE beneficiaries SET beneficiary_name=?, bank_name=?, account_no=?, ifsc=? WHERE id=?");
    $stmt->execute([
        $input['beneficiary_name'] ?? '',
        $input['bank_name'] ?? '',
        $input['account_no'] ?? '',
        $input['ifsc'] ?? '',
        (int)($input['id'] ?? 0)
    ]);
    jsonResponse(['success' => true, 'message' => 'Beneficiary updated']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);