<?php
// admin/ajax/appointment_detail.php
require_once '../../includes/auth.php'; requireAdmin();
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['error'=>'Invalid ID']); exit; }

$appt = $conn->query("SELECT a.*, u.full_name, u.employee_id, u.department, u.email, u.id as uid
    FROM appointments a JOIN users u ON a.user_id=u.id WHERE a.id=$id")->fetch_assoc();

if (!$appt) { echo json_encode(['error'=>'Not found']); exit; }

$uid = (int)$appt['uid'];

// Latest vitals
$vitals = $conn->query("SELECT * FROM vitals WHERE user_id=$uid ORDER BY recorded_at DESC LIMIT 1")->fetch_assoc();

// Latest health record
$hr = $conn->query("SELECT health_status, diagnosis, record_date FROM health_records WHERE user_id=$uid ORDER BY record_date DESC LIMIT 1")->fetch_assoc();

echo json_encode([
    'appointment' => [
        'id'               => $appt['id'],
        'appointment_date' => $appt['appointment_date'],
        'appointment_time' => date('g:i A', strtotime($appt['appointment_time'])),
        'purpose'          => $appt['purpose'],
        'notes'            => $appt['notes'] ?? '',
        'status'           => $appt['status'],
    ],
    'user' => [
        'full_name'   => $appt['full_name'],
        'employee_id' => $appt['employee_id'],
        'department'  => $appt['department'],
        'email'       => $appt['email'],
    ],
    'vitals'        => $vitals ?: (object)[],
    'health_record' => $hr    ?: (object)[],
]);