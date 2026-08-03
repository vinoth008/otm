<?php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach (($input['settings'] ?? []) as $key => $value) {
        $stmt->execute([$key, (string)$value]);
    }
    jsonResponse(['success' => true, 'message' => 'Settings updated']);
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);