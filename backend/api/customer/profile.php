<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'get') {
    getProfile();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    updateProfile();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_password') {
    changePassword();
} else {
    errorResponse('Invalid action');
}

function getProfile() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) errorResponse('Invalid user session', 401);
    $users = getCollection('users');
    if (!$users) errorResponse('Database connection error');
    $user = $users->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
    if (!$user) errorResponse('User not found');
    successResponse([
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
        'role' => $user['role'] ?? '',
        'account_number' => $user['account_number'] ?? '',
        'account_type' => $user['account_type'] ?? '',
        'balance' => round((float)($user['balance'] ?? 0), 2),
        'created_at' => mongoDateToPHP($user['created_at'] ?? null)->format('Y-m-d H:i:s'),
        'dob' => isset($user['dob']) ? mongoDateToPHP($user['dob'])->format('Y-m-d') : null,
        'address' => $user['address'] ?? '',
        'theme_preference' => $user['theme_preference'] ?? 'light'
    ], 'Profile retrieved');
}

function updateProfile() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) errorResponse('Invalid user session', 401);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $users = getCollection('users');
    if (!$users) errorResponse('Database connection error');
    $user = $users->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
    if (!$user) errorResponse('User not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['first_name'])) $updateData['first_name'] = sanitizeInput($data['first_name']);
    if (isset($data['last_name'])) $updateData['last_name'] = sanitizeInput($data['last_name']);
    if (isset($data['phone'])) {
        $phone = sanitizeInput($data['phone']);
        if (!empty($phone) && !validatePhone($phone)) errorResponse('Invalid phone number');
        $updateData['phone'] = $phone;
    }
    if (isset($data['dob'])) {
        $dob = sanitizeInput($data['dob']);
        if (!empty($dob) && !validateDate($dob)) errorResponse('Invalid date of birth');
        $updateData['dob'] = !empty($dob) ? phpDateToMongo($dob . ' 00:00:00') : null;
    }
    if (isset($data['address'])) $updateData['address'] = sanitizeInput($data['address']);
    if (isset($data['theme_preference'])) {
        $theme = sanitizeInput($data['theme_preference']);
        if (in_array($theme, ['light', 'dark'])) {
            $updateData['theme_preference'] = $theme;
            $_SESSION['user_theme'] = $theme;
        }
    }
    if (count($updateData) <= 1) errorResponse('No fields to update');
    $users->updateOne(['_id' => new MongoDB\BSON\ObjectId($userId)], ['$set' => $updateData]);
    logActivity('profile_updated', $userId);
    successResponse(null, 'Profile updated successfully');
}

function changePassword() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) errorResponse('Invalid user session', 401);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $oldPassword = $data['old_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';
    if (empty($oldPassword) || empty($newPassword)) errorResponse('Old password and new password are required');
    if ($newPassword !== $confirmPassword) errorResponse('New passwords do not match');
    $passwordValidation = validatePasswordStrength($newPassword);
    if (!$passwordValidation['valid']) errorResponse(implode(', ', $passwordValidation['errors']));
    $users = getCollection('users');
    if (!$users) errorResponse('Database connection error');
    $user = $users->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
    if (!$user) errorResponse('User not found');
    if (!verifyPassword($oldPassword, $user['password_hash'])) errorResponse('Current password is incorrect');
    $users->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($userId)],
        ['$set' => ['password_hash' => hashPassword($newPassword), 'updated_at' => phpDateToMongo()]]
    );
    logActivity('password_changed', $userId);
    successResponse(null, 'Password changed successfully');
}
