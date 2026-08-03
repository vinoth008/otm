<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../services/email_verification_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed', [], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = (int)($input['user_id'] ?? 0);
$token = clean_string($input['token'] ?? '');

if ($userId <= 0 || $token === '') {
    json_response(false, 'Invalid request', [], 400);
}

$result = ev_verify_email_token($userId, $token);

if (!$result['ok']) {
    json_response(false, $result['message'], [], 400);
}

json_response(true, $result['message']);