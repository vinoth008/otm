<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case 'get_all': getAllAppointments(); break;
    case 'create': createAppointment(); break;
    case 'update': updateAppointment(); break;
    case 'delete': deleteAppointment(); break;
    default: errorResponse('Invalid action');
}

function getAllAppointments() {
    requireRole(['admin', 'receptionist', 'staff']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;
    $filter = [];
    if (!empty($_GET['status'])) $filter['status'] = sanitizeInput($_GET['status']);
    if (!empty($_GET['date']) && validateDate($_GET['date'])) {
        $dayStart = phpDateToMongo($_GET['date'] . ' 00:00:00');
        $dayEnd = phpDateToMongo($_GET['date'] . ' 23:59:59');
        $filter['date'] = ['$gte' => $dayStart, '$lte' => $dayEnd];
    }
    $collection = getCollection('appointments');
    if (!$collection) errorResponse('Database connection error');
    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort' => ['date' => -1],
        'skip' => $skip,
        'limit' => $limit
    ]);
    $users = getCollection('users');
    $list = [];
    foreach ($cursor as $a) {
        $customerName = 'Unknown';
        if (isset($a['customer_id']) && $users) {
            $cust = $users->findOne(['_id' => $a['customer_id']]);
            if ($cust) $customerName = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? ''));
        }
        $list[] = [
            '_id' => (string)$a['_id'],
            'customer_id' => isset($a['customer_id']) ? (string)$a['customer_id'] : '',
            'customer_name' => $customerName,
            'title' => $a['title'] ?? '',
            'date' => mongoDateToPHP($a['date'] ?? null)->format('Y-m-d'),
            'time' => $a['time'] ?? '',
            'status' => $a['status'] ?? 'pending',
            'notes' => $a['notes'] ?? '',
            'created_at' => mongoDateToPHP($a['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse([
        'appointments' => $list,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_count' => $total,
            'limit' => $limit
        ]
    ], 'Appointments retrieved');
}

function createAppointment() {
    requireRole(['admin', 'receptionist', 'staff']);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $customerId = $data['customer_id'] ?? '';
    $title = sanitizeInput($data['title'] ?? '');
    $date = sanitizeInput($data['date'] ?? '');
    $time = sanitizeInput($data['time'] ?? '');
    $notes = sanitizeInput($data['notes'] ?? '');
    $status = sanitizeInput($data['status'] ?? 'pending');
    if (!isValidObjectId($customerId)) errorResponse('Invalid customer ID');
    if (empty($title)) errorResponse('Title is required');
    if (empty($date) || !validateDate($date)) errorResponse('Invalid date format');
    $users = getCollection('users');
    if ($users) {
        $customer = $users->findOne(['_id' => new MongoDB\BSON\ObjectId($customerId), 'role' => 'customer', 'deleted_at' => null]);
        if (!$customer) errorResponse('Customer not found');
    }
    $collection = getCollection('appointments');
    if (!$collection) errorResponse('Database connection error');
    $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    if (!in_array($status, $validStatuses)) $status = 'pending';
    $doc = [
        'customer_id' => new MongoDB\BSON\ObjectId($customerId),
        'title' => $title,
        'date' => phpDateToMongo($date . ' 00:00:00'),
        'time' => $time,
        'status' => $status,
        'notes' => $notes,
        'created_by' => new MongoDB\BSON\ObjectId(getCurrentUserId()),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Failed to create appointment');
    $apptId = (string)$result->getInsertedId();
    logActivity('appointment_created', getCurrentUserId(), ['appointment_id' => $apptId]);
    successResponse(['appointment_id' => $apptId], 'Appointment created successfully');
}

function updateAppointment() {
    requireRole(['admin', 'receptionist', 'staff']);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $appointmentId = $data['appointment_id'] ?? '';
    if (!isValidObjectId($appointmentId)) errorResponse('Invalid appointment ID');
    $collection = getCollection('appointments');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($appointmentId), 'deleted_at' => null]);
    if (!$existing) errorResponse('Appointment not found');
    $updateData = ['updated_at' => phpDateToMongo()];
    if (isset($data['customer_id'])) {
        $cid = $data['customer_id'];
        if (!isValidObjectId($cid)) errorResponse('Invalid customer ID');
        $updateData['customer_id'] = new MongoDB\BSON\ObjectId($cid);
    }
    if (isset($data['title'])) $updateData['title'] = sanitizeInput($data['title']);
    if (isset($data['date'])) {
        if (!validateDate($data['date'])) errorResponse('Invalid date format');
        $updateData['date'] = phpDateToMongo($data['date'] . ' 00:00:00');
    }
    if (isset($data['time'])) $updateData['time'] = sanitizeInput($data['time']);
    if (isset($data['status'])) {
        $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
        if (in_array($data['status'], $validStatuses)) $updateData['status'] = $data['status'];
    }
    if (isset($data['notes'])) $updateData['notes'] = sanitizeInput($data['notes']);
    $collection->updateOne(['_id' => new MongoDB\BSON\ObjectId($appointmentId)], ['$set' => $updateData]);
    logActivity('appointment_updated', getCurrentUserId(), ['appointment_id' => $appointmentId]);
    successResponse(['appointment_id' => $appointmentId], 'Appointment updated successfully');
}

function deleteAppointment() {
    requireRole(['admin', 'receptionist', 'staff']);
    $data = getRequestData();
    if (!$data || !is_array($data)) errorResponse('Invalid request format');
    $appointmentId = $data['appointment_id'] ?? '';
    if (!isValidObjectId($appointmentId)) errorResponse('Invalid appointment ID');
    $collection = getCollection('appointments');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($appointmentId), 'deleted_at' => null]);
    if (!$existing) errorResponse('Appointment not found');
    $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($appointmentId)],
        ['$set' => ['deleted_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]]
    );
    logActivity('appointment_deleted', getCurrentUserId(), ['appointment_id' => $appointmentId]);
    successResponse(null, 'Appointment deleted successfully');
}
