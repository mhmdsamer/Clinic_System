<?php
session_start();
require_once 'connection.php';

// Ensure only admins can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Get parameters
$doctor_id = isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';
$appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;

// Validate parameters
if ($doctor_id <= 0 || empty($date) || $appointment_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

// Get the current time slot for comparison
$current_query = "
    SELECT time_slot 
    FROM appointments 
    WHERE appointment_id = ?
";
$current_stmt = $conn->prepare($current_query);
$current_stmt->bind_param("i", $appointment_id);
$current_stmt->execute();
$current_result = $current_stmt->get_result();

$current_time_slot = null;
if ($current_result->num_rows > 0) {
    $current_data = $current_result->fetch_assoc();
    $current_time_slot = $current_data['time_slot'];
}

// Get day of week
$day_of_week = date('l', strtotime($date));

// Get doctor's availability for that day
$availability_query = "
    SELECT start_time, end_time
    FROM doctor_availability
    WHERE doctor_id = ? AND day_of_week = ?
";
$availability_stmt = $conn->prepare($availability_query);
$availability_stmt->bind_param("is", $doctor_id, $day_of_week);
$availability_stmt->execute();
$availability_result = $availability_stmt->get_result();

if ($availability_result->num_rows === 0) {
    // Doctor not available on this day
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

$availability = $availability_result->fetch_assoc();
$start_time = strtotime($availability['start_time']);
$end_time = strtotime($availability['end_time']);

// Generate 30-minute slots
$time_slots = [];
$slot_duration = 30 * 60; // 30 minutes in seconds

for ($time = $start_time; $time < $end_time; $time += $slot_duration) {
    $slot_time = date('H:i:s', $time);
    
    // Current time slot is always available (we're editing this appointment)
    if ($slot_time == $current_time_slot) {
        $time_slots[] = [
            'time' => $slot_time,
            'formatted_time' => date('h:i A', $time),
            'is_current' => true
        ];
        continue;
    }
    
    // Check if this slot is already booked by another appointment
    $booked_query = "
        SELECT COUNT(*) as is_booked
        FROM appointments
        WHERE doctor_id = ? 
        AND appointment_date = ? 
        AND time_slot = ? 
        AND status = 'scheduled'
        AND appointment_id != ?
    ";
    $booked_stmt = $conn->prepare($booked_query);
    $booked_stmt->bind_param("issi", $doctor_id, $date, $slot_time, $appointment_id);
    $booked_stmt->execute();
    $booked_result = $booked_stmt->get_result();
    $is_booked = $booked_result->fetch_assoc()['is_booked'];
    
    if ($is_booked == 0) {
        $time_slots[] = [
            'time' => $slot_time,
            'formatted_time' => date('h:i A', $time),
            'is_current' => false
        ];
    }
}

// Return the time slots as JSON
header('Content-Type: application/json');
echo json_encode($time_slots);
exit();