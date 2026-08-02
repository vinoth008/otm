<?php
// backend/php/complaint_crud.php
/**
 * Complaint Management for Smart Transaction Control
 * Handles customer complaints/support tickets and staff/admin resolution
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/session_manager.php';
// Prevent direct access
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
/**
 * Get complaints (customer sees own; staff/admin sees all)
 * GET
 */
function getComplaints() {
    requireActiveSession();
    $collection = getCollection('complaints');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $filter = ['deleted_at' => null];
    if (!isAdmin() && !isStaff()) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
    }
    $statusFilter = $_GET['status'] ?? '';
    if (in_array($statusFilter, ['open', 'in_progress', 'resolved', 'closed'], true)) {
        $filter['status'] = $statusFilter;
    }
    $cursor = $collection->find($filter, ['sort' => ['created_at' => -1], 'limit' => 200]);
    $list = [];
    foreach ($cursor as $c) {
        $list[] = [
            'complaint_id' => (string)$c['_id'],
            'subject' => $c['subject'] ?? '',
            'category' => $c['category'] ?? 'General',
            'description' => $c['description'] ?? '',
            'status' => $c['status'] ?? 'open',
            'priority' => $c['priority'] ?? 'medium',
            'response' => $c['response'] ?? '',
            'user_id' => isset($c['user_id']) ? (string)$c['user_id'] : '',
            'created_at' => mongoDateToPHP($c['created_at'] ?? null)->format('Y-m-d H:i:s'),
            'updated_at' => mongoDateToPHP($c['updated_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['complaints' => $list], 'Complaints retrieved');
}
/**
 * Create a complaint
 * POST: subject, category, description, priority
 */
function createComplaint() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $subject = sanitizeInput($data['subject'] ?? '');
    $category = sanitizeInput($data['category'] ?? 'General');
    $description = sanitizeInput($data['description'] ?? '');
    $priority = sanitizeInput($data['priority'] ?? 'medium');
    if (empty($subject)) {
        errorResponse('Subject is required');
    }
    if (empty($description)) {
        errorResponse('Description is required');
    }
    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $priority = 'medium';
    }
    $collection = getCollection('complaints');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $result = $collection->insertOne([
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'subject' => $subject,
        'category' => $category,
        'description' => $description,
        'priority' => $priority,
        'status' => 'open',
        'response' => '',
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('complaint_created', getCurrentUserId(), [
        'complaint_id' => (string)$result->getInsertedId(),
        'subject' => $subject
    ]);
    successResponse(['complaint_id' => (string)$result->getInsertedId()], 'Complaint submitted successfully');
}
/**
 * Staff/admin update complaint status and respond
 * POST: complaint_id, status, response
 */
function updateComplaint() {
    requireRole(['staff']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $complaintId = $data['complaint_id'] ?? '';
    $status = sanitizeInput($data['status'] ?? '');
    $response = sanitizeInput($data['response'] ?? '');
    if (!isValidObjectId($complaintId)) {
        errorResponse('Invalid complaint ID');
    }
    if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
        errorResponse('Invalid status');
    }
    $collection = getCollection('complaints');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $complaint = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($complaintId),
        'deleted_at' => null
    ]);
    if (!$complaint) {
        errorResponse('Complaint not found');
    }
    $update = [
        'status' => $status,
        'updated_at' => phpDateToMongo(),
        'resolved_by' => new MongoDB\BSON\ObjectId(getCurrentUserId())
    ];
    if ($response !== '') {
        $update['response'] = $response;
    }
    $collection->updateOne(['_id' => $complaint['_id']], ['$set' => $update]);
    // Notify the complaint owner
    if (isset($complaint['user_id'])) {
        $notifications = getCollection('notifications');
        if ($notifications) {
            $notifications->insertOne([
                'user_id' => $complaint['user_id'],
                'title' => 'Complaint ' . $status,
                'message' => 'Your complaint "' . ($complaint['subject'] ?? '') . '" has been updated to ' . $status,
                'type' => 'complaint',
                'read' => false,
                'created_at' => phpDateToMongo()
            ]);
        }
    }
    logActivity('complaint_updated', getCurrentUserId(), [
        'complaint_id' => $complaintId,
        'status' => $status
    ]);
    successResponse(null, 'Complaint updated successfully');
}
/**
 * Get complaint summary stats
 * GET
 */
function getComplaintSummary() {
    requireActiveSession();
    $collection = getCollection('complaints');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $filter = ['deleted_at' => null];
    if (!isAdmin() && !isStaff()) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
    }
    $summary = [
        'open' => $collection->countDocuments($filter + ['status' => 'open']),
        'in_progress' => $collection->countDocuments($filter + ['status' => 'in_progress']),
        'resolved' => $collection->countDocuments($filter + ['status' => 'resolved']),
        'closed' => $collection->countDocuments($filter + ['status' => 'closed']),
        'high_priority' => $collection->countDocuments($filter + ['priority' => 'high', 'status' => ['$in' => ['open', 'in_progress']]])
    ];
    successResponse($summary, 'Complaint summary retrieved');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getComplaints();
        break;
    case 'create':
        if ($method === 'POST') createComplaint();
        break;
    case 'update':
        if ($method === 'POST') updateComplaint();
        break;
    case 'summary':
        if ($method === 'GET') getComplaintSummary();
        break;
    default:
        errorResponse('Invalid action');
}
?>
