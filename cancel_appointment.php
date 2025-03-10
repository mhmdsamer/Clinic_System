<?php
session_start();
require_once 'connection.php';

// Check if appointment ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: admin_dashboard.php?error=Invalid+Appointment+ID");
    exit();
}

$appointment_id = intval($_GET['id']);
$is_admin = isset($_GET['admin']) && $_GET['admin'] === 'true' && isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin';
$is_patient = isset($_SESSION['user_id']) && $_SESSION['role'] === 'patient';

// Verify the appointment exists and get details
$appointment_query = "
    SELECT a.*, p.user_id as patient_user_id 
    FROM appointments a 
    JOIN patients p ON a.patient_id = p.patient_id 
    WHERE a.appointment_id = ?
";
$appointment_stmt = $conn->prepare($appointment_query);
$appointment_stmt->bind_param("i", $appointment_id);
$appointment_stmt->execute();
$appointment_result = $appointment_stmt->get_result();

if ($appointment_result->num_rows === 0) {
    header("Location: admin_dashboard.php?error=Appointment+Not+Found");
    exit();
}

$appointment = $appointment_result->fetch_assoc();

// Check if it's an admin or the patient who owns the appointment
$is_patient_owner = $is_patient && $appointment['patient_user_id'] == $_SESSION['user_id'];

// If neither admin nor the appointment owner, redirect
if (!$is_admin && !$is_patient_owner) {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

// Now we can proceed with cancelling the appointment
$update_query = "
    UPDATE appointments 
    SET status = 'cancelled' 
    WHERE appointment_id = ?
";
$update_stmt = $conn->prepare($update_query);
$update_stmt->bind_param("i", $appointment_id);

if ($update_stmt->execute()) {
    // Success - redirect back to appropriate page
    if ($is_admin) {
        header("Location: admin_appointments.php?message=Appointment+Successfully+Cancelled");
    } else {
        header("Location: homepage.php?message=Appointment+Successfully+Cancelled");
    }
} else {
    // Error - redirect back with error message
    if ($is_admin) {
        header("Location: admin_appointments.php?error=Failed+to+Cancel+Appointment");
    } else {
        header("Location: homepage.php?error=Failed+to+Cancel+Appointment");
    }
}
exit();
?>