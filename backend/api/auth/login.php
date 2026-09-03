<?php
/**
 * User login endpoint.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$data = getRequestData();
$email = strtolower(trim(sanitizeInput($data['email'] ?? '')));
$password = $data['password'] ?? '';
$selectedRole = strtolower(trim(sanitizeInput($data['role'] ?? '')));

if (empty($email) || empty($password)) {
    errorResponse('Email and password are required');
}
if (!validateEmail($email)) {
    errorResponse('Invalid email format');
}

$validRoles = ['admin', 'staff', 'receptionist', 'customer'];
if (!empty($selectedRole) && !in_array($selectedRole, $validRoles, true)) {
    $selectedRole = 'customer';
}

$collection = getCollection('users');
if (!$collection) {
    errorResponse('Database connection error', 500);
}

$user = $collection->findOne(['email' => $email, 'deleted_at' => null]);
if (!$user || ($user['status'] ?? 'active') !== 'active' || !verifyPassword($password, $user['password_hash'])) {
    logActivity('login_failed', null, ['email' => $email, 'reason' => 'invalid_credentials']);
    errorResponse('Invalid email or password', 401);
}

$actualRole = normalizeRole($user['role'] ?? 'customer');
if (!empty($selectedRole) && $actualRole !== $selectedRole) {
    logActivity('login_failed', (string)$user['_id'], ['email' => $email, 'reason' => 'role_mismatch']);
    errorResponse('This account does not have ' . $selectedRole . ' access. Please select the correct portal.', 401);
}

createUserSession($user);
successResponse([
    'user_id' => (string)$user['_id'],
    'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
    'email' => $user['email'],
    'role' => $actualRole,
    'redirect' => getRoleDashboardUrl()
], 'Login successful');
