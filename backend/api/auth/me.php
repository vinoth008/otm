<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../services/auth_service.php';

start_secure_session();

if (empty($_SESSION['user_id'])) {
    json_response(false, 'Not authenticated', [], 401);
}

$user = auth_find_user_by_id((int)$_SESSION['user_id']);
if (!$user) {
    json_response(false, 'User not found', [], 404);
}

json_response(true, 'Profile loaded', [
    'user_id' => (int)$user['id'],
    'full_name' => $user['full_name'],
    'username' => $user['username'],
    'email' => $user['email'],
    'mobile' => $user['mobile'],
    'role_code' => $user['role_code'],
    'role_name' => $user['role_name']
]);