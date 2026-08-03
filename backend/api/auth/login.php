<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../services/auth_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed', [], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$result = auth_login_user($input);

if (!$result['ok']) {
    json_response(false, $result['message'], [], 400);
}

json_response(true, $result['message'], [
    'user_id' => $result['user_id'],
    'role_code' => $result['role_code'],
    'otp_required' => $result['otp_required'],
    'otp' => $result['otp']
]);