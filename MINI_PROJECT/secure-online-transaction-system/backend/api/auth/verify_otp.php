<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../services/auth_service.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = (int)($input['user_id'] ?? 0);
$purpose = clean_string($input['purpose'] ?? '');
$otp = clean_string($input['otp'] ?? '');

if ($userId <= 0 || $purpose === '' || $otp === '') {
    json_response(false, 'Invalid request', [], 400);
}

$result = auth_verify_otp($userId, $purpose, $otp);

if (!$result['ok']) {
    json_response(false, $result['message'], [], 400);
}

json_response(true, $result['message']);