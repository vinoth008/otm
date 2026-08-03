<?php
declare(strict_types=1);
// Appointments API - adapted from MPWT reference project
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_all': $method === 'GET' && getAppointments(); break;
    case 'create': $method === 'POST' && createAppointment(); break;
    case 'update_status': $method === 'POST' && updateAppointmentStatus(); break;
    case 'branches': $method === 'GET' && getAppointmentBranches(); break;
    case 'summary': $method === 'GET' && getAppointmentsSummary(); break;
    default: errorResponse('Invalid action', 404);
}

function getAppointments() {
    requireActiveSession();
    $role = getCurrentUserRole();
    $collection = getCollection('appointments');
    if (!$collection) errorResponse('Database connection error');
    $filter = ['deleted_at' => null];
    if (!in_array($role, ['admin', 'staff', 'receptionist'], true)) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
    }
    $statusFilter = $_GET['status'] ?? '';
    if (in_array($statusFilter, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
        $filter['status'] = $statusFilter;
    }
    if (!empty($_GET['search'])) {
        $search = sanitizeInput($_GET['search']);
        $filter['$or'] = [
            ['purpose' => new MongoDB\BSON\Regex($search, 'i')],
            ['notes' => new MongoDB\BSON\Regex($search, 'i')]
        ];
    }
    $cursor = $collection->find($filter, ['sort' => ['appointment_date' => -1], 'limit' => 200]);
    $list = [];
    $usersCollection = getCollection('users');
    foreach ($cursor as $a) {
        $userName = '';
        if (isset($a['user_id']) && $usersCollection) {
            $u = $usersCollection->findOne(['_id' => $a['user_id']]);
            if ($u) $userName = ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '');
        }
        $list[] = [
            'appointment_id' => (string)$a['_id'],
            '_id' => (string)$a['_id'],
            'user_id' => isset($a['user_id']) ? (string)$a['user_id'] : '',
            'user_name' => trim($userName),
            'branch_id' => isset($a['branch_id']) ? (string)$a['branch_id'] : '',
            'appointment_date' => mongoDateToPHP($a['appointment_date'] ?? null)->format('Y-m-d'),
            'appointment_time' => $a['appointment_time'] ?? '',
            'purpose' => $a['purpose'] ?? '',
            'status' => $a['status'] ?? 'pending',
            'notes' => $a['notes'] ?? '',
            'created_at' => mongoDateToPHP($a['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
    successResponse(['appointments' => $list]);
}

function createAppointment() {
    requireActiveSession();
    $role = getCurrentUserRole();
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $appointmentDate = sanitizeInput($data['appointment_date'] ?? '');
    $appointmentTime = sanitizeInput($data['appointment_time'] ?? '');
    $purpose = sanitizeInput($data['purpose'] ?? '');
    $branchId = $data['branch_id'] ?? '';
    if (empty($appointmentDate) || !validateDate($appointmentDate)) errorResponse('Enter a valid appointment date');
    if (empty($appointmentTime)) errorResponse('Appointment time is required');
    if (empty($purpose)) errorResponse('Purpose is required');
    if (!isValidObjectId($branchId)) errorResponse('Select a valid branch');
    $collection = getCollection('appointments');
    if (!$collection) errorResponse('Database connection error');
    $targetUserId = getCurrentUserId();
    if (in_array($role, ['staff', 'receptionist', 'admin'], true) && !empty($data['user_id']) && isValidObjectId($data['user_id'])) {
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
    if (!$result->getInsertedId()) errorResponse('Failed to book appointment');
    $appointmentId = (string)$result->getInsertedId();
    createNotification($targetUserId, 'appointment', 'Appointment Booked', 'Your appointment on ' . $appointmentDate . ' has been booked successfully.');
    logActivity('appointment_booked', getCurrentUserId(), ['appointment_id' => $appointmentId, 'date' => $appointmentDate]);
    successResponse(['appointment_id' => $appointmentId], 'Appointment booked successfully');
}

function updateAppointmentStatus() {
    requireRole(['staff', 'receptionist', 'admin']);
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $appointmentId = $data['appointment_id'] ?? '';
    $status = sanitizeInput($data['status'] ?? '');
    if (!isValidObjectId($appointmentId)) errorResponse('Invalid appointment ID');
    if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) errorResponse('Invalid status');
    $collection = getCollection('appointments');
    if (!$collection) errorResponse('Database connection error');
    $appointment = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($appointmentId), 'deleted_at' => null]);
    if (!$appointment) errorResponse('Appointment not found');
    $update = ['status' => $status, 'updated_at' => phpDateToMongo()];
    $notes = sanitizeInput($data['notes'] ?? '');
    if ($notes !== '') $update['notes'] = $notes;
    $collection->updateOne(['_id' => $appointment['_id']], ['$set' => $update]);
    if (isset($appointment['user_id'])) {
        createNotification(
            (string)$appointment['user_id'],
            'appointment',
            'Appointment ' . ucfirst($status),
            'Your appointment on ' . mongoDateToPHP($appointment['appointment_date'] ?? null)->format('Y-m-d') . ' has been ' . $status
        );
    }
    logActivity('appointment_status_changed', getCurrentUserId(), ['appointment_id' => $appointmentId, 'status' => $status]);
    successResponse(null, 'Appointment ' . $status . ' successfully');
}

function getAppointmentBranches() {
    requireActiveSession();
    $collection = getCollection('branches');
    if (!$collection) errorResponse('Database connection error');
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

function getAppointmentsSummary() {
    requireActiveSession();
    $role = getCurrentUserRole();
    $collection = getCollection('appointments');
    if (!$collection) errorResponse('Database connection error');
    $filter = ['deleted_at' => null];
    if (!in_array($role, ['admin', 'staff', 'receptionist'], true)) {
        $filter['user_id'] = new MongoDB\BSON\ObjectId(getCurrentUserId());
    }
    $base = $filter;
    $base['status'] = 'pending';
    $pending = $collection->countDocuments($base);
    $base['status'] = 'confirmed';
    $confirmed = $collection->countDocuments($base);
    $base['status'] = 'completed';
    $completed = $collection->countDocuments($base);
    $base['status'] = 'cancelled';
    $cancelled = $collection->countDocuments($base);
    $today = date('Y-m-d');
    $todayFilter = array_merge($filter, [
        'appointment_date' => ['$gte' => phpDateToMongo($today . ' 00:00:00'), '$lte' => phpDateToMongo($today . ' 23:59:59')]
    ]);
    $todayCount = $collection->countDocuments($todayFilter);
    successResponse([
        'total' => $pending + $confirmed + $completed + $cancelled,
        'pending' => $pending,
        'confirmed' => $confirmed,
        'completed' => $completed,
        'cancelled' => $cancelled,
        'today' => $todayCount
    ]);
}