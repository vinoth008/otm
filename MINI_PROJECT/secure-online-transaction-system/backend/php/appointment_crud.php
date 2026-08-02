<?php
// backend/php/appointment_crud.php
/**
 * Appointment Management for Smart Transaction Control
 * Handles in-branch appointments for customers, managed by receptionist/staff
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
 * Get appointments (customer sees own; receptionist/staff/admin see all)
 * GET
 */
function getAppointments() {
    requireActiveSession();
    $collection = getCollection('appointments');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $filter = ['deleted_at' => null];
    if (!isAdmin() && !isStaff() && !isReceptionist()) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
    }
    $statusFilter = $_GET['status'] ?? '';
    if (in_array($statusFilter, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
        $filter['status'] = $statusFilter;
    }
    $cursor = $collection->find($filter, ['sort' => ['appointment_date' => -1], 'limit' => 200]);
    $list = [];
    foreach ($cursor as $a) {
        $list[] = [
            'appointment_id' => (string)$a['_id'],
            'user_id' => isset($a['user_id']) ? (string)$a['user_id'] : '',
            'branch_id' => isset($a['branch_id']) ? (string)$a['branch_id'] : '',
            'appointment_date' => mongoDateToPHP($a['appointment_date'] ?? null)->format('Y-m-d'),
            'appointment_time' => $a['appointment_time'] ?? '',
            'purpose' => $a['purpose'] ?? '',
            'status' => $a['status'] ?? 'pending',
            'notes' => $a['notes'] ?? '',
            'created_at' => mongoDateToPHP($a['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['appointments' => $list], 'Appointments retrieved');
}
/**
 * Book an appointment (customer or staff booking for customer)
 * POST: appointment_date, appointment_time, purpose, branch_id, notes
 */
function createAppointment() {
    requireActiveSession();
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $appointmentDate = sanitizeInput($data['appointment_date'] ?? '');
    $appointmentTime = sanitizeInput($data['appointment_time'] ?? '');
    $purpose = sanitizeInput($data['purpose'] ?? '');
    $branchId = $data['branch_id'] ?? '';
    if (empty($appointmentDate) || !validateDate($appointmentDate)) {
        errorResponse('Enter a valid appointment date');
    }
    if (empty($appointmentTime)) {
        errorResponse('Appointment time is required');
    }
    if (empty($purpose)) {
        errorResponse('Purpose is required');
    }
    if (!isValidObjectId($branchId)) {
        errorResponse('Select a valid branch');
    }
    $collection = getCollection('appointments');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    // Staff/receptionist can book on behalf of a customer
    $targetUserId = getCurrentUserId();
    if ((isStaff() || isReceptionist() || isAdmin()) && !empty($data['user_id']) && isValidObjectId($data['user_id'])) {
        $targetUserId = $data['user_id'];
    }
    $result = $collection->insertOne([
        'user_id' => new MongoDB\BSON\ObjectId($targetUserId),
        'branch_id' => new MongoDB\BSON\ObjectId($branchId),
        'appointment_date' => phpDateToMongo($appointmentDate . ' 00:00:00'),
        'appointment_time' => $appointmentTime,
        'purpose' => $purpose,
        'status' => 'pending',
        'notes' => sanitizeInput($data['notes'] ?? ''),
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'deleted_at' => null
    ]);
    logActivity('appointment_booked', getCurrentUserId(), [
        'appointment_id' => (string)$result->getInsertedId(),
        'date' => $appointmentDate
    ]);
    successResponse(['appointment_id' => (string)$result->getInsertedId()], 'Appointment booked successfully');
}
/**
 * Update appointment status (staff/receptionist/admin)
 * POST: appointment_id, status, notes
 */
function updateAppointmentStatus() {
    requireRole(['staff', 'receptionist']);
    $data = getRequestData();
    if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
        errorResponse('Invalid security token');
    }
    $appointmentId = $data['appointment_id'] ?? '';
    $status = sanitizeInput($data['status'] ?? '');
    if (!isValidObjectId($appointmentId)) {
        errorResponse('Invalid appointment ID');
    }
    if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
        errorResponse('Invalid status');
    }
    $collection = getCollection('appointments');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $appointment = $collection->findOne([
        '_id' => new MongoDB\BSON\ObjectId($appointmentId),
        'deleted_at' => null
    ]);
    if (!$appointment) {
        errorResponse('Appointment not found');
    }
    $update = [
        'status' => $status,
        'updated_at' => phpDateToMongo()
    ];
    $notes = sanitizeInput($data['notes'] ?? '');
    if ($notes !== '') {
        $update['notes'] = $notes;
    }
    $collection->updateOne(['_id' => $appointment['_id']], ['$set' => $update]);
    // Notify customer
    if (isset($appointment['user_id'])) {
        $notifications = getCollection('notifications');
        if ($notifications) {
            $notifications->insertOne([
                'user_id' => $appointment['user_id'],
                'title' => 'Appointment ' . $status,
                'message' => 'Your appointment on ' .
                    mongoDateToPHP($appointment['appointment_date'] ?? null)->format('Y-m-d') .
                    ' has been ' . $status,
                'type' => 'appointment',
                'read' => false,
                'created_at' => phpDateToMongo()
            ]);
        }
    }
    logActivity('appointment_status_changed', getCurrentUserId(), [
        'appointment_id' => $appointmentId,
        'status' => $status
    ]);
    successResponse(null, 'Appointment ' . $status . ' successfully');
}
/**
 * Get available branches for booking
 * GET
 */
function getAppointmentBranches() {
    requireActiveSession();
    $collection = getCollection('branches');
    if (!$collection) {
        errorResponse('Database connection error');
    }
    $cursor = $collection->find(['status' => 'active'], ['sort' => ['branch_name' => 1, 'name' => 1]]);
    $list = [];
    foreach ($cursor as $b) {
        $list[] = [
            'branch_id' => (string)$b['_id'],
            'name' => $b['name'] ?? $b['branch_name'] ?? '',
            'address' => $b['address'] ?? $b['address_line1'] ?? '',
            'city' => $b['city'] ?? '',
            'phone' => $b['phone'] ?? '',
            'opening_hours' => $b['opening_hours'] ?? ''
        ];
    }
    successResponse(['branches' => $list], 'Branches retrieved');
}
/**
 * Route actions
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all':
        if ($method === 'GET') getAppointments();
        break;
    case 'create':
        if ($method === 'POST') createAppointment();
        break;
    case 'update_status':
        if ($method === 'POST') updateAppointmentStatus();
        break;
    case 'branches':
        if ($method === 'GET') getAppointmentBranches();
        break;
    default:
        errorResponse('Invalid action');
}
?>
