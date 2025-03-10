<?php
session_start();
require_once 'connection.php';

// Ensure only doctors can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

// Get doctor's details
$doctor_id = null;
$doctor_info = null;
$user_id = $_SESSION['user_id'];

$doctor_query = "SELECT d.*, s.speciality_name 
                 FROM doctors d 
                 JOIN specialties s ON d.speciality_id = s.speciality_id 
                 WHERE d.user_id = ?";
$doctor_stmt = $conn->prepare($doctor_query);
$doctor_stmt->bind_param("i", $user_id);
$doctor_stmt->execute();
$doctor_result = $doctor_stmt->get_result();

if ($doctor_result->num_rows > 0) {
    $doctor_info = $doctor_result->fetch_assoc();
    $doctor_id = $doctor_info['doctor_id'];
}

// Fetch doctor's appointments with patient info
$appointments_query = "SELECT a.*, 
                        p.first_name AS patient_first_name, 
                        p.last_name AS patient_last_name,
                        p.patient_id
                      FROM appointments a 
                      JOIN patients p ON a.patient_id = p.patient_id 
                      WHERE a.doctor_id = ? 
                      ORDER BY a.appointment_date DESC, a.time_slot ASC";

$appointments_stmt = $conn->prepare($appointments_query);
$appointments_stmt->bind_param("i", $doctor_id);
$appointments_stmt->execute();
$appointments_result = $appointments_stmt->get_result();
$appointments = [];
while ($row = $appointments_result->fetch_assoc()) {
    $appointments[] = $row;
}

// Handle Appointment Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_appointment'])) {
    $appointment_id = $_POST['appointment_id'];
    $new_status = $_POST['status'];
    $notes = $_POST['notes'];
    
    $update_query = "UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ? AND doctor_id = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("ssii", $new_status, $notes, $appointment_id, $doctor_id);
    
    if ($update_stmt->execute()) {
        $success_message = "Appointment updated successfully!";
        
        // Refresh appointments list
        $appointments_stmt->execute();
        $appointments_result = $appointments_stmt->get_result();
        $appointments = [];
        while ($row = $appointments_result->fetch_assoc()) {
            $appointments[] = $row;
        }
    } else {
        $error_message = "Failed to update appointment.";
    }
}

// Handle Patient Medical Info View
$patient_info = null;
if (isset($_GET['view_patient']) && is_numeric($_GET['view_patient'])) {
    $patient_id = $_GET['view_patient'];
    
    // Verify this patient has an appointment with the doctor
    $verify_query = "SELECT COUNT(*) as count FROM appointments 
                    WHERE doctor_id = ? AND patient_id = ?";
    $verify_stmt = $conn->prepare($verify_query);
    $verify_stmt->bind_param("ii", $doctor_id, $patient_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result()->fetch_assoc();
    
    if ($verify_result['count'] > 0) {
        // Fetch patient information
        $patient_query = "SELECT * FROM patients WHERE patient_id = ?";
        $patient_stmt = $conn->prepare($patient_query);
        $patient_stmt->bind_param("i", $patient_id);
        $patient_stmt->execute();
        $patient_info = $patient_stmt->get_result()->fetch_assoc();
    }
}

// Handle Availability Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_availability'])) {
    // Validate input
    $availability_success = true;
    $days_to_update = [];

    // Prepare days to update
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($days as $day) {
        if (isset($_POST['days'][$day]) && 
            !empty($_POST['start_time'][$day]) && 
            !empty($_POST['end_time'][$day])) {
            
            // Additional validation for time
            $start_time = $_POST['start_time'][$day];
            $end_time = $_POST['end_time'][$day];
            
            // Ensure end time is after start time
            if (strtotime($end_time) <= strtotime($start_time)) {
                $availability_success = false;
                $error_message = "Invalid time range for $day. End time must be after start time.";
                break;
            }
            
            $days_to_update[] = [
                'day' => $day,
                'start_time' => $start_time,
                'end_time' => $end_time
            ];
        }
    }

    // If validation passes, update availability
    if ($availability_success) {
        try {
            // Start transaction
            $conn->begin_transaction();

            // Delete existing availability
            $delete_sql = "DELETE FROM doctor_availability WHERE doctor_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $doctor_id);
            $delete_stmt->execute();

            // Insert new availability
            $insert_sql = "INSERT INTO doctor_availability (doctor_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);

            foreach ($days_to_update as $day_data) {
                $insert_stmt->bind_param(
                    "isss", 
                    $doctor_id, 
                    $day_data['day'], 
                    $day_data['start_time'], 
                    $day_data['end_time']
                );
                
                if (!$insert_stmt->execute()) {
                    throw new Exception("Failed to insert availability for " . $day_data['day']);
                }
            }

            // Commit transaction
            $conn->commit();
            $success_message = "Availability updated successfully!";
        } catch (Exception $e) {
            // Rollback transaction
            $conn->rollback();
            $availability_success = false;
            $error_message = "Error updating availability: " . $e->getMessage();
        }
    }
}

// Fetch existing availability
$availability_query = "SELECT * FROM doctor_availability WHERE doctor_id = ?";
$availability_stmt = $conn->prepare($availability_query);
$availability_stmt->bind_param("i", $doctor_id);
$availability_stmt->execute();
$availability_result = $availability_stmt->get_result();
$existing_availability = [];
while ($row = $availability_result->fetch_assoc()) {
    $existing_availability[$row['day_of_week']] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZJVS/gpW6BzT9YyIqzZTY5sXl/sA1rZVfNWJ5D4IbRSxIk2XYsM36X6gKDe5" crossorigin="anonymous">

    
</head>
<body class="bg-gray-100" x-data="{ 
    activeTab: <?= isset($_GET['view_patient']) ? '\'patient\'' : '\'appointments\'' ?>, 
    showAvailabilityModal: false,
    showAppointmentModal: false,
    appointmentId: null,
    appointmentStatus: '',
    appointmentNotes: ''
}">
    <div class="container mx-auto px-4 py-8">
        <div class="bg-white shadow-md rounded-lg">
            <!-- Header Section -->
            <div class="bg-blue-600 text-white px-6 py-4 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold">
                        Doctor Dashboard - 
                        <?= htmlspecialchars($doctor_info['first_name'] . ' ' . $doctor_info['last_name']) ?>
                    </h1>
                    <p class="text-blue-100">
                        <?= htmlspecialchars($doctor_info['speciality_name']) ?> Specialist
                    </p>
                </div>
                <a href="logout.php" class="bg-white text-blue-600 px-4 py-2 rounded-md hover:bg-blue-50">
                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                </a>
            </div>

            <!-- Tabs Section -->
            <div class="border-b">
                <nav class="flex">
                    <button 
                        @click="activeTab = 'appointments'" 
                        :class="activeTab === 'appointments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'"
                        class="px-4 py-4 border-b-2 font-medium focus:outline-none hover:text-blue-600 transition"
                    >
                        <i class="fas fa-calendar-check mr-2"></i>
                        Appointments
                    </button>
                    <button 
                        @click="activeTab = 'availability'" 
                        :class="activeTab === 'availability' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'"
                        class="px-4 py-4 border-b-2 font-medium focus:outline-none hover:text-blue-600 transition"
                    >
                        <i class="fas fa-clock mr-2"></i>
                        Availability
                    </button>
                    <button 
                        x-show="activeTab === 'patient'"
                        :class="activeTab === 'patient' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'"
                        class="px-4 py-4 border-b-2 font-medium focus:outline-none"
                    >
                        <i class="fas fa-user mr-2"></i>
                        Patient Details
                    </button>
                </nav>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded m-4">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded m-4">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Appointments Tab -->
            <div x-show="activeTab === 'appointments'" class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-gray-800">My Appointments</h2>
                    
                    <!-- Filter Section -->
                    <div class="flex space-x-2">
                        <a href="doctor_dashboard.php" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                            All
                        </a>
                        <a href="doctor_dashboard.php?filter=scheduled" class="px-3 py-2 bg-yellow-100 text-yellow-700 rounded-md hover:bg-yellow-200">
                            Scheduled
                        </a>
                        <a href="doctor_dashboard.php?filter=completed" class="px-3 py-2 bg-green-100 text-green-700 rounded-md hover:bg-green-200">
                            Completed
                        </a>
                        <a href="doctor_dashboard.php?filter=cancelled" class="px-3 py-2 bg-red-100 text-red-700 rounded-md hover:bg-red-200">
                            Cancelled
                        </a>
                    </div>
                </div>

                <!-- Appointments List -->
                <?php if (empty($appointments)): ?>
                    <div class="bg-blue-50 p-4 rounded-lg text-center">
                        <p class="text-blue-700">You don't have any appointments yet.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left">Date</th>
                                    <th class="py-3 px-4 text-left">Time</th>
                                    <th class="py-3 px-4 text-left">Patient</th>
                                    <th class="py-3 px-4 text-left">Status</th>
                                    <th class="py-3 px-4 text-left">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($appointments as $appointment): ?>
                                    <?php 
                                    // Skip if filter is applied and doesn't match
                                    if (isset($_GET['filter']) && $appointment['status'] != $_GET['filter']) {
                                        continue;
                                    }
                                    
                                    // Set status colors
                                    $statusClasses = [
                                        'scheduled' => 'bg-yellow-100 text-yellow-800',
                                        'completed' => 'bg-green-100 text-green-800',
                                        'cancelled' => 'bg-red-100 text-red-800'
                                    ];
                                    $statusClass = $statusClasses[$appointment['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4">
                                            <?= date('M d, Y', strtotime($appointment['appointment_date'])) ?>
                                        </td>
                                        <td class="py-3 px-4">
                                            <?= date('h:i A', strtotime($appointment['time_slot'])) ?>
                                        </td>
                                        <td class="py-3 px-4">
                                            <?= htmlspecialchars($appointment['patient_first_name'] . ' ' . $appointment['patient_last_name']) ?>
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>">
                                                <?= ucfirst($appointment['status']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="flex space-x-2">
                                                <a href="doctor_dashboard.php?view_patient=<?= $appointment['patient_id'] ?>" 
                                                   class="px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700"
                                                   title="View Patient Details">
                                                    <i class="fas fa-user-md"></i>
                                                </a>
                                                <button 
                                                    @click="appointmentId = <?= $appointment['appointment_id'] ?>; 
                                                            appointmentStatus = '<?= $appointment['status'] ?>'; 
                                                            appointmentNotes = '<?= addslashes($appointment['notes'] ?? '') ?>'; 
                                                            showAppointmentModal = true"
                                                    class="px-2 py-1 bg-yellow-500 text-white rounded hover:bg-yellow-600"
                                                    title="Update Appointment">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Patient Details Tab -->
            <div x-show="activeTab === 'patient'" class="p-6">
                <?php if ($patient_info): ?>
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800">
                            Patient: <?= htmlspecialchars($patient_info['first_name'] . ' ' . $patient_info['last_name']) ?>
                        </h2>
                        <a href="doctor_dashboard.php" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Appointments
                        </a>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <!-- Patient Information Section -->
                        <div class="bg-white p-6 rounded-lg shadow-md">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                                <i class="fas fa-user mr-2 text-blue-600"></i>Patient Information
                            </h3>
                            
                            <div class="space-y-4">
                                <div class="grid grid-cols-2">
                                    <div class="text-gray-600">Full Name:</div>
                                    <div><?= htmlspecialchars($patient_info['first_name'] . ' ' . $patient_info['last_name']) ?></div>
                                </div>
                                
                                <div class="grid grid-cols-2">
                                    <div class="text-gray-600">Date of Birth:</div>
                                    <div><?= date('M d, Y', strtotime($patient_info['dob'])) ?></div>
                                </div>
                                
                                <div class="grid grid-cols-2">
                                    <div class="text-gray-600">Age:</div>
                                    <div><?= date_diff(date_create($patient_info['dob']), date_create('today'))->y ?> years</div>
                                </div>
                                
                                <div class="grid grid-cols-2">
                                    <div class="text-gray-600">Gender:</div>
                                    <div><?= ucfirst($patient_info['gender']) ?></div>
                                </div>
                                
                                <div class="grid grid-cols-2">
                                    <div class="text-gray-600">Phone:</div>
                                    <div><?= htmlspecialchars($patient_info['phone']) ?></div>
                                </div>
                                
                                <div class="grid grid-cols-2">
                                    <div class="text-gray-600">Address:</div>
                                    <div><?= htmlspecialchars($patient_info['address'] ?: 'Not provided') ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Medical History Section -->
                        <div class="bg-white p-6 rounded-lg shadow-md">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                                <i class="fas fa-notes-medical mr-2 text-blue-600"></i>Medical History
                            </h3>
                            
                            <?php if (empty($patient_info['medical_history'])): ?>
                                <p class="text-gray-500 italic">No medical history records available.</p>
                            <?php else: ?>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <p class="whitespace-pre-line"><?= nl2br(htmlspecialchars($patient_info['medical_history'])) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Past Appointments Section -->
                            <h4 class="text-md font-semibold text-gray-800 mt-6 mb-3">
                                <i class="fas fa-history mr-2 text-blue-600"></i>Past Appointments with You
                            </h4>
                            
                            <?php
                            // Filter appointments for this patient
                            $patient_appointments = array_filter($appointments, function($appt) use ($patient_info) {
                                return $appt['patient_id'] == $patient_info['patient_id'];
                            });
                            
                            if (empty($patient_appointments)):
                            ?>
                                <p class="text-gray-500 italic">No past appointments found.</p>
                            <?php else: ?>
                                <div class="divide-y divide-gray-200">
                                    <?php foreach ($patient_appointments as $appt): ?>
                                        <div class="py-3">
                                            <div class="flex justify-between">
                                                <div>
                                                    <span class="font-medium"><?= date('M d, Y', strtotime($appt['appointment_date'])) ?></span>
                                                    <span class="text-gray-600 text-sm ml-2"><?= date('h:i A', strtotime($appt['time_slot'])) ?></span>
                                                </div>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold 
                                                    <?= $statusClasses[$appt['status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                                    <?= ucfirst($appt['status']) ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($appt['notes'])): ?>
                                                <p class="mt-1 text-sm text-gray-600">
                                                    <span class="font-medium">Notes:</span> <?= htmlspecialchars($appt['notes']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-yellow-50 p-4 rounded-lg text-center">
                        <p class="text-yellow-700">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Patient information not found or you don't have access to this patient's records.
                        </p>
                        <a href="doctor_dashboard.php" class="inline-block mt-3 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            Return to Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Availability Tab -->
            <div x-show="activeTab === 'availability'" class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-gray-800">My Availability</h2>
                    <button 
                        @click="showAvailabilityModal = true" 
                        class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition"
                    >
                        <i class="fas fa-edit mr-2"></i>Update Availability
                    </button>
                </div>

                <!-- Availability Grid -->
                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <?php 
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    foreach ($days as $day): 
                    ?>
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h3 class="font-semibold text-gray-700 mb-2"><?= $day ?></h3>
                            <?php if (isset($existing_availability[$day])): ?>
                                <div class="flex items-center">
                                    <i class="fas fa-clock text-blue-500 mr-2"></i>
                                    <span>
                                        <?= date('h:i A', strtotime($existing_availability[$day]['start_time'])) ?> - 
                                        <?= date('h:i A', strtotime($existing_availability[$day]['end_time'])) ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-500 italic">Not Available</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Availability Update Modal -->
    <div 
        x-show="showAvailabilityModal" 
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        x-cloak
    >
        <div 
            @click.away="showAvailabilityModal = false" 
            class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6 m-4 max-h-[90vh] overflow-y-auto"
        >
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Update Availability</h2>
                <button 
                    @click="showAvailabilityModal = false" 
                    class="text-gray-600 hover:text-gray-900"
                >
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>

            <form action="doctor_dashboard.php" method="POST">
                <div class="space-y-4">
                    <?php 
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    foreach ($days as $day): 
                    ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <input 
                                    type="checkbox" 
                                    name="days[<?= $day ?>]" 
                                    id="<?= $day ?>" 
                                    value="1"
                                    <?= isset($existing_availability[$day]) ? 'checked' : '' ?>
                                    class="mr-3 h-4 w-4 text-blue-600 focus:ring-blue-500"
                                >
                                <label for="<?= $day ?>" class="font-medium text-gray-700"><?= $day ?></label>
                            </div>
                            <div class="flex items-center space-x-3">
                                <input 
                                    type="time" 
                                    name="start_time[<?= $day ?>]" 
                                    id="start_time_<?= $day ?>" 
                                    value="<?= isset($existing_availability[$day]) ? $existing_availability[$day]['start_time'] : '' ?>"
                                    class="border rounded px-2 py-1"
                                >
                                <span>to</span>
                                <input 
                                    type="time" 
                                    name="end_time[<?= $day ?>]" 
                                    id="end_time_<?= $day ?>" 
                                    value="<?= isset($existing_availability[$day]) ? $existing_availability[$day]['end_time'] : '' ?>"
                                    class="border rounded px-2 py-1"
                                >
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <button 
                        type="button" 
                        @click="showAvailabilityModal = false"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300"
                    >
                        Cancel
                    </button>
                    <button 
                        type="submit" 
                        name="update_availability"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                    >
                        Update Availability
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Appointment Update Modal -->
    <div 
        x-show="showAppointmentModal"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        x-cloak
    >
        <div 
            @click.away="showAppointmentModal = false"
            class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 m-4"
        >
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">Update Appointment</h2>
                <button 
                    @click="showAppointmentModal = false"
                    class="text-gray-600 hover:text-gray-900"
                >
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form action="doctor_dashboard.php" method="POST">
                <input type="hidden" name="appointment_id" :value="appointmentId">
                
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2">Status</label>
                    <select 
                        name="status" 
                        class="w-full border rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        x-model="appointmentStatus"
                    >
                        <option value="scheduled">Scheduled</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="