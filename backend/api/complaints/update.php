<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();

$data = getRequestData();
if (!$data) {
    errorResponse('Invalid request format');
}

$complaintId = $data['complaint_id'] ?? '';
if (!isValidObjectId($complaintId)) {
    errorResponse('Invalid complaint ID');
}

$col = getCollection('complaints');
if (!$col) {
    errorResponse('Database connection error');
}

$complaint = $col->findOne([
    '_id' => new MongoDB\BSON\ObjectId($complaintId),
    'deleted_at' => null
]);

if (!$complaint) {
    errorResponse('Complaint not found');
}

$role = getCurrentUserRole();
$userId = getCurrentUserId();

if (!in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    if ((string)$complaint['user_id'] !== $userId) {
        errorResponse('You can only update your own complaints', 403);
    }
}

$updateData = ['updated_at' => phpDateToMongo()];

if (isset($data['status'])) {
    $status = sanitizeInput($data['status']);
    $validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
    if (in_array($status, $validStatuses, true)) {
        $updateData['status'] = $status;
    }
}

if (isset($data['priority'])) {
    $priority = sanitizeInput($data['priority']);
    $validPriorities = ['Low', 'Medium', 'High', 'Critical'];
    if (in_array($priority, $validPriorities, true)) {
        $updateData['priority'] = $priority;
    }
}

if (isset($data['assigned_to']) && in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $assignedTo = sanitizeInput($data['assigned_to']);
    if (empty($assignedTo)) {
        $updateData['assigned_to'] = null;
    } elseif (isValidObjectId($assignedTo)) {
        $updateData['assigned_to'] = new MongoDB\BSON\ObjectId($assignedTo);
    }
}

if (isset($data['staff_reply']) && in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $updateData['staff_reply'] = sanitizeInput($data['staff_reply']);
}

if (isset($data['response']) && in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $updateData['staff_reply'] = sanitizeInput($data['response']);
}

if (isset($data['reply']) && in_array($role, ['admin', 'staff', 'receptionist'], true)) {
    $updateData['staff_reply'] = sanitizeInput($data['reply']);
}

$col->updateOne(
    ['_id' => new MongoDB\BSON\ObjectId($complaintId)],
    ['$set' => $updateData]
);

logActivity('complaint_updated', getCurrentUserId(), ['complaint_id' => $complaintId, 'changes' => array_keys($updateData)]);

successResponse(['complaint_id' => $complaintId, 'updated' => true], 'Complaint updated successfully');
