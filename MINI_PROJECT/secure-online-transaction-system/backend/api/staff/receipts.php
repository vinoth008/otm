<?php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("SELECT id, receipt_no, customer_name, receipt_type, amount, created_at FROM receipts ORDER BY id DESC");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);