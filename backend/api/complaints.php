<?php
declare(strict_types=1);
// Complaints API (Admin)
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all': $method === 'GET' && getAllComplaints(); break;
    case 'get': $method === 'GET' && getComplaint(); break;
    case 'get_mine': $method === 'GET' && getMyComplaints(); break;
    case 'create': $method === 'POST' && createComplaint(); break;
    case 'update': ($method === 'POST' || $method === 'PUT') && updateComplaint(); break;
    case 'delete': ($method === 'POST' || $method === 'DELETE') && deleteComplaint(); break;
    case 'resolve': $method === 'POST' && resolveComplaint(); break;
    default: errorResponse('Invalid action', 404);
}

function formatComplaint($c) {
    return [
        '_id' => (string)$c['_id'],
        'ticket_no' => $c['ticket_no'] ?? '',
        'customer_name' => $c['customer_name'] ?? '',
        'subject' => $c['subject'] ?? '',
        'description' => $c['description'] ?? '',
        'category' => $c['category'] ?? '',
        'priority' => $c['priority'] ?? 'Medium',
        'status' => $c['status'] ?? 'Open',
        'staff_reply' => $c['staff_reply'] ?? '',
        'created_at' => mongoDateToPHP($c['created_at'])->format('Y-m-d H:i:s')
    ];
}

function getAllComplaints() {
    requireRole(['admin', 'staff', 'receptionist']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['deleted_at' => null];
    if (!empty($_GET['status'])) $filter['status'] = sanitizeInput($_GET['status']);
    if (!empty($_GET['priority'])) $filter['priority'] = sanitizeInput($_GET['priority']);
    if (!empty($_GET['search'])) {
        $s = sanitizeInput($_GET['search']);
        $filter['$or'] = [
            ['ticket_no' => new MongoDB\BSON\Regex($s, 'i')],
            ['customer_name' => new MongoDB\BSON\Regex($s, 'i')],
            ['subject' => new MongoDB\BSON\Regex($s, 'i')]
        ];
    }
    $col = getCollection('complaints');
    $total = $col->countDocuments($filter);
    $items = $col->find($filter, ['sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit])->toArray();
    successResponse(['complaints' => array_map('formatComplaint', $items), 'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]]);
}

function getMyComplaints() {
    requireActiveSession();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = ['user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()), 'deleted_at' => null];
    if (!empty($_GET['status'])) $filter['status'] = sanitizeInput($_GET['status']);
    $col = getCollection('complaints');
    $total = $col->countDocuments($filter);
    $items = $col->find($filter, ['sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit])->toArray();
    successResponse(['complaints' => array_map('formatComplaint', $items), 'pagination' => ['current_page' => $page, 'total_pages' => ceil($total / $limit), 'total_count' => $total, 'limit' => $limit]]);
}

function getComplaint() {
    requireRole(['admin', 'staff', 'receptionist']);
    $id = $_GET['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid complaint ID');
    $c = getCollection('complaints')->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null]);
    if (!$c) errorResponse('Complaint not found');
    successResponse(formatComplaint($c));
}

function createComplaint() {
    requireActiveSession();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $subject = sanitizeInput($data['subject'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');
    $category = sanitizeInput($data['category'] ?? 'General');
    $priority = sanitizeInput($data['priority'] ?? 'Medium');
    if (empty($subject) || empty($description)) errorResponse('Subject and description are required');
    if (!in_array($priority, ['Low', 'Medium', 'High', 'Critical'])) errorResponse('Invalid priority');
    $col = getCollection('complaints');
    $ticketNo = 'CMP' . str_pad((string)($col->countDocuments() + 1), 4, '0', STR_PAD_LEFT);
    $user = getCollection('users')->findOne(['_id' => new MongoDB\BSON\ObjectId(getCurrentUserId())]);
    $doc = [
        'ticket_no' => $ticketNo,
        'user_id' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'customer_name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
        'subject' => $subject, 'description' => $description, 'category' => $category,
        'priority' => $priority, 'status' => 'Open', 'staff_reply' => '',
        'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo(), 'deleted_at' => null
    ];
    $result = $col->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create complaint');
    logActivity('complaint_created', getCurrentUserId(), ['ticket_no' => $ticketNo]);
    successResponse(['complaint_id' => (string)$result->getInsertedId(), 'ticket_no' => $ticketNo], 'Complaint submitted successfully');
}

function updateComplaint() {
    requireRole(['admin', 'staff', 'receptionist']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid complaint ID');
    $col = getCollection('complaints');
    if (!$col->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null])) errorResponse('Complaint not found');
    $upd = ['updated_at' => phpDateToMongo()];
    if (isset($data['status']) && in_array($data['status'], ['Open', 'In Progress', 'Resolved', 'Closed'])) $upd['status'] = $data['status'];
    if (isset($data['priority']) && in_array($data['priority'], ['Low', 'Medium', 'High', 'Critical'])) $upd['priority'] = $data['priority'];
    if (isset($data['staff_reply'])) $upd['staff_reply'] = sanitizeInput($data['staff_reply']);
    $col->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => $upd]);
    logActivity('complaint_updated', $id, ['updated_by' => getCurrentUserId()]);
    successResponse(['complaint_id' => $id, 'updated' => true], 'Complaint updated successfully');
}

function resolveComplaint() {
    requireRole(['admin', 'staff', 'receptionist']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid complaint ID');
    $col = getCollection('complaints');
    if (!$col->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null])) errorResponse('Complaint not found');
    $col->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['status' => 'Resolved', 'updated_at' => phpDateToMongo()]]);
    logActivity('complaint_resolved', $id, ['resolved_by' => getCurrentUserId()]);
    successResponse(['complaint_id' => $id, 'status' => 'Resolved'], 'Complaint marked as resolved');
}

function deleteComplaint() {
    requireRole(['admin', 'staff', 'receptionist']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $id = $data['id'] ?? '';
    if (!isValidObjectId($id)) errorResponse('Invalid complaint ID');
    $col = getCollection('complaints');
    if (!$col->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null])) errorResponse('Complaint not found');
    $col->updateOne(['_id' => new MongoDB\BSON\ObjectId($id)], ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]);
    logActivity('complaint_deleted', $id, ['deleted_by' => getCurrentUserId()]);
    successResponse(null, 'Complaint deleted successfully');
}