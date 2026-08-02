<?php
declare(strict_types=1);
// Profile API
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get': $method === 'GET' && getProfile(); break;
    case 'update': $method === 'POST' && updateProfile(); break;
    case 'change_password': $method === 'POST' && changePassword(); break;
    case 'upload_photo': $method === 'POST' && uploadPhoto(); break;
    default: errorResponse('Invalid action', 404);
}

function getProfile() {
    requireActiveSession();
    $collection = getCollection('users');
    $u = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())]);
    if (!$u) errorResponse('User not found');
    successResponse([
        '_id' => (string)$u['_id'],
        'first_name' => $u['first_name'],
        'last_name' => $u['last_name'] ?? '',
        'email' => $u['email'],
        'phone' => $u['phone'] ?? '',
        'role' => $u['role'],
        'profile_photo' => $u['profile_photo'] ?? null,
        'currency' => $u['currency'] ?? 'INR',
        'created_at' => mongoDateToPHP($u['created_at'])->format('Y-m-d H:i:s')
    ]);
}

function updateProfile() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $collection = getCollection('users');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['first_name'])) {
        $fn = sanitizeInput($data['first_name']);
        if (empty($fn)) errorResponse('First name is required');
        $updateData['first_name'] = $fn;
    }
    if (isset($data['last_name'])) $updateData['last_name'] = sanitizeInput($data['last_name']);
    if (isset($data['phone'])) $updateData['phone'] = sanitizeInput($data['phone']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['$set' => $updateData]);
    logActivity('profile_updated', getCurrentUserId());
    successResponse(null, 'Profile updated successfully');
}

function changePassword() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $current = $data['current_password'] ?? '';
    $new = $data['new_password'] ?? '';
    $confirm = $data['confirm_password'] ?? '';
    if (empty($current) || empty($new) || empty($confirm)) errorResponse('All password fields are required');
    if ($new !== $confirm) errorResponse('New passwords do not match');
    if (strlen($new) < 8) errorResponse('Password must be at least 8 characters');
    $collection = getCollection('users');
    $u = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())]);
    if (!$u || !password_verify($current, $u['password_hash'])) errorResponse('Current password is incorrect');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['$set' => ['password_hash' => password_hash($new, PASSWORD_BCRYPT), 'updated_at' => phpDateToMongo()]]);
    createNotification(getCurrentUserId(), 'security', 'Password Changed', 'Your password was changed successfully.');
    logActivity('password_changed', getCurrentUserId());
    successResponse(null, 'Password changed successfully');
}

function uploadPhoto() {
    requireActiveSession();
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) errorResponse('No photo uploaded');
    $file = $_FILES['photo'];
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowed)) errorResponse('Invalid file type. Only JPG, PNG, WEBP allowed');
    if ($file['size'] > 2 * 1024 * 1024) errorResponse('File too large. Max 2MB');
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'user_' . getCurrentUserId() . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../../../uploads/profile_photos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) errorResponse('Failed to upload photo');
    $collection = getCollection('users');
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())], ['$set' => ['profile_photo' => $filename, 'updated_at' => phpDateToMongo()]]);
    successResponse(['profile_photo' => $filename], 'Profile photo updated');
}