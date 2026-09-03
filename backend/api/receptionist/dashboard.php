<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

requireRole(['admin', 'receptionist', 'staff']);

$users = getCollection('users');
$appointments = getCollection('appointments');
$complaints = getCollection('complaints');

$totalCustomers = 0;
if ($users) {
    $totalCustomers = $users->countDocuments(['role' => 'customer', 'deleted_at' => null]);
}

$todayAppointments = 0;
$recentAppointments = [];
if ($appointments) {
    $today = new MongoDB\BSON\UTCDateTime(date('Y-m-d') . 'T00:00:00.000+00:00');
    $tomorrow = new MongoDB\BSON\UTCDateTime(date('Y-m-d', strtotime('+1 day')) . 'T00:00:00.000+00:00');
    $todayAppointments = $appointments->countDocuments([
        'date' => ['$gte' => $today, '$lt' => $tomorrow]
    ]);
    $cursor = $appointments->find([], ['sort' => ['date' => -1], 'limit' => 6]);
    foreach ($cursor as $a) {
        $customerName = 'Unknown';
        if (isset($a['customer_id']) && $users) {
            $cust = $users->findOne(['_id' => $a['customer_id']]);
            if ($cust) $customerName = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? ''));
        }
        $recentAppointments[] = [
            '_id' => (string)$a['_id'],
            'customer_id' => isset($a['customer_id']) ? (string)$a['customer_id'] : '',
            'customer_name' => $customerName,
            'title' => $a['title'] ?? '',
            'date' => mongoDateToPHP($a['date'] ?? null)->format('Y-m-d'),
            'time' => $a['time'] ?? '',
            'status' => $a['status'] ?? 'pending',
            'created_at' => mongoDateToPHP($a['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
}

$openComplaints = 0;
$recentComplaints = [];
if ($complaints) {
    $openComplaints = $complaints->countDocuments(['status' => ['$in' => ['open', 'pending']]]);
    $cCursor = $complaints->find([], ['sort' => ['created_at' => -1], 'limit' => 5]);
    foreach ($cCursor as $c) {
        $recentComplaints[] = [
            '_id' => (string)$c['_id'],
            'customer_id' => isset($c['customer_id']) ? (string)$c['customer_id'] : '',
            'subject' => $c['subject'] ?? $c['title'] ?? '',
            'status' => $c['status'] ?? 'open',
            'priority' => $c['priority'] ?? 'low',
            'created_at' => mongoDateToPHP($c['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
}

$recentCustomers = [];
if ($users) {
    $cCursor = $users->find(
        ['role' => 'customer', 'deleted_at' => null],
        ['sort' => ['created_at' => -1], 'limit' => 5]
    );
    foreach ($cCursor as $c) {
        $recentCustomers[] = [
            '_id' => (string)$c['_id'],
            'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
            'email' => $c['email'] ?? '',
            'phone' => $c['phone'] ?? '',
            'account_number' => $c['account_number'] ?? '',
            'status' => $c['status'] ?? 'active',
            'created_at' => mongoDateToPHP($c['created_at'] ?? null)->format('Y-m-d H:i:s')
        ];
    }
}

successResponse([
    'total_customers' => $totalCustomers,
    'today_appointments' => $todayAppointments,
    'open_complaints' => $openComplaints,
    'recent_appointments' => $recentAppointments,
    'recent_customers' => $recentCustomers,
    'recent_complaints' => $recentComplaints
], 'Dashboard loaded');
