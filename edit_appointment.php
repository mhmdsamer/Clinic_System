<?php
session_start();
require_once 'connection.php';

// Ensure only admins can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

// Initialize variables
$message = '';
$error = '';
$appointment = null;

// Check if appointment ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: admin_appointments.php?error=No+appointment+selected");
    exit();
}

$appointment_id = intval($_GET['id']);

// Process update form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_appointment'])) {
    $patient_id = $_POST['patient_id'];
    $doctor_id = $_POST['doctor_id'];
    $appointment_date = $_POST['appointment_date'];
    $time_slot = $_POST['time_slot'];
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'];
    
    // Validate the selected time slot is available (only if date or time or doctor changed)
    $check_availability = false;
    if ($doctor_id != $_POST['original_doctor_id'] || 
        $appointment_date != $_POST['original_date'] || 
        $time_slot != $_POST['original_time_slot']) {
        $check_availability = true;
    }
    
    if ($check_availability) {
        $availability_check = "
            SELECT COUNT(*) as count
            FROM appointments 
            WHERE doctor_id = ? 
            AND appointment_date = ? 
            AND time_slot = ?
            AND status = 'scheduled'
            AND appointment_id != ?
        ";
        $check_stmt = $conn->prepare($availability_check);
        $check_stmt->bind_param("issi", $doctor_id, $appointment_date, $time_slot, $appointment_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        
        if ($check_data['count'] > 0) {
            $error = "This time slot is already booked. Please select another time.";
        }
    }
    
    if (empty($error)) {
        // Update appointment
        $update_query = "
            UPDATE appointments 
            SET patient_id = ?, doctor_id = ?, appointment_date = ?, 
                time_slot = ?, notes = ?, status = ?
            WHERE appointment_id = ?
        ";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iissssi", $patient_id, $doctor_id, $appointment_date, $time_slot, $notes, $status, $appointment_id);
        
        if ($update_stmt->execute()) {
            $message = "Appointment updated successfully!";
        } else {
            $error = "Error updating appointment: " . $conn->error;
        }
    }
}

// Fetch the appointment details
$appointment_query = "
    SELECT 
        a.*, 
        p.first_name as patient_first_name, 
        p.last_name as patient_last_name,
        d.first_name as doctor_first_name, 
        d.last_name as doctor_last_name,
        d.speciality_id
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE a.appointment_id = ?
";
$appointment_stmt = $conn->prepare($appointment_query);
$appointment_stmt->bind_param("i", $appointment_id);
$appointment_stmt->execute();
$appointment_result = $appointment_stmt->get_result();

if ($appointment_result->num_rows === 0) {
    header("Location: admin_appointments.php?error=Appointment+not+found");
    exit();
}

$appointment = $appointment_result->fetch_assoc();

// Fetch all patients
$patients_query = "
    SELECT p.patient_id, p.first_name, p.last_name, u.email
    FROM patients p
    JOIN users u ON p.user_id = u.user_id
    ORDER BY p.last_name, p.first_name
";
$patients_result = $conn->query($patients_query);

// Fetch all specialties
$specialties_query = "SELECT * FROM specialties ORDER BY speciality_name";
$specialties_result = $conn->query($specialties_query);

// Fetch all doctors
$doctors_query = "
    SELECT d.doctor_id, d.first_name, d.last_name, s.speciality_name, d.speciality_id 
    FROM doctors d
    JOIN specialties s ON d.speciality_id = s.speciality_id
    ORDER BY d.last_name, d.first_name
";
$doctors_result = $conn->query($doctors_query);

// Function to generate available time slots based on doctor's availability
function getAvailableTimeSlots($conn, $doctor_id, $date, $current_time_slot = null, $appointment_id = null) {
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
        return []; // Doctor not available on this day
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
    
    return $time_slots;
}

// Get available time slots for the selected doctor and date
$available_slots = getAvailableTimeSlots(
    $conn, 
    $appointment['doctor_id'], 
    $appointment['appointment_date'], 
    $appointment['time_slot'],
    $appointment_id
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Appointment - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        .card-gradient {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        .menu-item-active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #fff;
        }
        .menu-item {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid rgba(255, 255, 255, 0.5);
        }
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        .quick-action {
            transition: all 0.3s ease;
        }
        .quick-action:hover {
            transform: translateY(-3px);
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); }
            70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        .time-slot-selected {
            border-color: #3b82f6;
            background-color: rgba(59, 130, 246, 0.1);
        }
    </style>
    <script>
        // Function to fetch available time slots when date or doctor changes
        function updateTimeSlots() {
            const doctorId = document.getElementById('doctor_id').value;
            const appointmentDate = document.getElementById('appointment_date').value;
            const appointmentId = <?= $appointment_id ?>;
            
            fetch(`get_time_slots.php?doctor_id=${doctorId}&date=${appointmentDate}&appointment_id=${appointmentId}`)
                .then(response => response.json())
                .then(data => {
                    const timeSlotsContainer = document.getElementById('time_slots_container');
                    timeSlotsContainer.innerHTML = '';
                    
                    if (data.length === 0) {
                        timeSlotsContainer.innerHTML = `
                            <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4" role="alert">
                                <p>No available time slots for the selected doctor on this date.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    const slotsGrid = document.createElement('div');
                    slotsGrid.className = 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3';
                    
                    data.forEach(slot => {
                        const label = document.createElement('label');
                        label.className = 'flex items-center p-3 border rounded-lg hover:bg-blue-50 cursor-pointer transition';
                        if (slot.is_current) {
                            label.classList.add('time-slot-selected');
                        }
                        
                        const input = document.createElement('input');
                        input.type = 'radio';
                        input.name = 'time_slot';
                        input.value = slot.time;
                        input.className = 'mr-2 text-blue-600';
                        input.required = true;
                        if (slot.is_current) {
                            input.checked = true;
                        }
                        
                        const span = document.createElement('span');
                        span.textContent = slot.formatted_time;
                        
                        label.appendChild(input);
                        label.appendChild(span);
                        slotsGrid.appendChild(label);
                    });
                    
                    timeSlotsContainer.appendChild(slotsGrid);
                })
                .catch(error => {
                    console.error('Error fetching time slots:', error);
                });
        }
    </script>
</head>
<body class="bg-gray-50" x-data="{ mobileMenuOpen: false }">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <div class="fixed inset-y-0 left-0 z-30 w-64 bg-gradient-to-b from-blue-800 to-blue-900 text-white transform transition-transform duration-300 ease-in-out shadow-xl" 
             :class="{'translate-x-0': mobileMenuOpen, '-translate-x-full': !mobileMenuOpen, 'md:translate-x-0': true}">
            <div class="flex items-center justify-center h-20 border-b border-blue-700">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-clinic-medical text-3xl"></i>
                    <h1 class="text-2xl font-bold">Clinic_System</h1>
                </div>
            </div>
            <div class="p-4">
                <div class="flex items-center space-x-3 p-3 bg-blue-800 rounded-lg mb-6">
                    <div class="w-10 h-10 rounded-full bg-white text-blue-800 flex items-center justify-center">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div>
                        <p class="text-sm opacity-75">Welcome,</p>
                        <p class="font-semibold"><?= $_SESSION['name'] ?? 'Admin' ?></p>
                    </div>
                </div>
            </div>
            <nav class="px-4">
                <p class="text-xs text-gray-400 px-3 mb-2 uppercase">Main</p>
                <ul class="space-y-1">
                    <li>
                        <a href="admin_dashboard.php" class="flex items-center p-3 menu-item rounded-lg">
                            <i class="fas fa-home mr-3 w-5 text-center"></i>Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="user_management.php" class="flex items-center p-3 menu-item rounded-lg">
                            <i class="fas fa-users mr-3 w-5 text-center"></i>User Management
                        </a>
                    </li>
                    <li>
                        <a href="admin_appointments.php" class="flex items-center p-3 menu-item menu-item-active rounded-lg">
                            <i class="fas fa-calendar-alt mr-3 w-5 text-center"></i>Appointments
                        </a>
                    </li>
                    <p class="text-xs text-gray-400 px-3 mt-6 mb-2 uppercase">Medical</p>
                    <li>
                        <a href="specialties.php" class="flex items-center p-3 menu-item rounded-lg">
                            <i class="fas fa-stethoscope mr-3 w-5 text-center"></i>Specialties
                        </a>
                    </li>
                    <li>
                        <a href="admin_medical_records.php" class="flex items-center p-3 menu-item rounded-lg">
                            <i class="fas fa-file-medical mr-3 w-5 text-center"></i>Medical Records
                        </a>
                    </li>
                    <p class="text-xs text-gray-400 px-3 mt-6 mb-2 uppercase">Finance</p>
                    <li>
                        <a href="invoices.php" class="flex items-center p-3 menu-item rounded-lg">
                            <i class="fas fa-file-invoice-dollar mr-3 w-5 text-center"></i>Invoices
                        </a>
                    </li>
                    <li class="mt-6">
                        <a href="logout.php" class="flex items-center p-3 text-red-300 hover:text-red-100 hover:bg-red-900 rounded-lg transition">
                            <i class="fas fa-sign-out-alt mr-3 w-5 text-center"></i>Logout
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-1 ml-0 md:ml-64 transition-all duration-300 overflow-y-auto">
            <!-- Mobile Header -->
            <div class="md:hidden fixed top-0 left-0 right-0 bg-gradient-to-r from-blue-800 to-blue-700 text-white p-4 flex justify-between items-center z-20 shadow-md">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-clinic-medical text-xl"></i>
                    <h1 class="text-xl font-bold">ClinicPro</h1>
                </div>
                <button @click="mobileMenuOpen = !mobileMenuOpen" class="focus:outline-none p-2 rounded-full hover:bg-blue-700">
                    <i :class="mobileMenuOpen ? 'fas fa-times' : 'fas fa-bars'" class="text-xl"></i>
                </button>
            </div>

            <!-- Header with breadcrumbs -->
            <div class="bg-white border-b sticky top-0 z-10 shadow-sm p-4 md:p-6 hidden md:block">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Edit Appointment</h1>
                        <div class="flex items-center text-sm text-gray-500 mt-1">
                            <a href="admin_dashboard.php" class="hover:text-blue-600">Home</a>
                            <span class="mx-2">/</span>
                            <a href="admin_appointments.php" class="hover:text-blue-600">Appointments</a>
                            <span class="mx-2">/</span>
                            <span class="text-gray-700">Edit</span>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <button class="p-2 bg-gray-100 rounded-full hover:bg-gray-200 text-gray-600 focus:outline-none">
                            <i class="far fa-bell"></i>
                        </button>
                        <button class="p-2 bg-gray-100 rounded-full hover:bg-gray-200 text-gray-600 focus:outline-none">
                            <i class="far fa-envelope"></i>
                        </button>
                        <div class="flex items-center space-x-2 ml-2">
                            <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="hidden md:block">
                                <p class="font-medium text-sm text-gray-800"><?= $_SESSION['name'] ?? 'Admin' ?></p>
                                <p class="text-xs text-gray-500">Administrator</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="p-4 pt-20 md:pt-6 md:p-6 space-y-6">
                <!-- Alerts -->
                <?php if ($error): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded-md shadow-sm" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-3 text-red-500"></i>
                            <p><?= $error ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($message): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded-md shadow-sm" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-3 text-green-500"></i>
                            <p><?= $message ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Current Appointment Details Card -->
                <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                    <div class="flex items-center justify-between bg-blue-50 border-b border-blue-100 p-4">
                        <h2 class="text-lg font-semibold text-blue-800 flex items-center">
                            <i class="fas fa-info-circle mr-2"></i>
                            Current Appointment Details
                        </h2>
                        <a href="admin_appointments.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center">
                            <i class="fas fa-arrow-left mr-1"></i>
                            Back to Appointments
                        </a>
                    </div>
                    <div class="p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center">
                                <i class="fas fa-user"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Patient</p>
                                <p class="font-medium"><?= htmlspecialchars($appointment['patient_first_name'] . ' ' . $appointment['patient_last_name']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-full bg-green-100 text-green-700 flex items-center justify-center">
                                <i class="fas fa-user-md"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Doctor</p>
                                <p class="font-medium">Dr. <?= htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Date & Time</p>
                                <p class="font-medium"><?= date('M d, Y', strtotime($appointment['appointment_date'])) ?> at <?= date('h:i A', strtotime($appointment['time_slot'])) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3 md:col-span-3">
                            <div class="w-10 h-10 rounded-full bg-yellow-100 text-yellow-700 flex items-center justify-center">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Status</p>
                                <span class="px-3 py-1 rounded-full text-xs font-medium
                                    <?php
                                    if ($appointment['status'] == 'scheduled') echo 'bg-blue-100 text-blue-700';
                                    elseif ($appointment['status'] == 'completed') echo 'bg-green-100 text-green-700';
                                    elseif ($appointment['status'] == 'cancelled') echo 'bg-red-100 text-red-700';
                                    ?>">
                                    <?= ucfirst($appointment['status']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Form Card -->
                <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                    <div class="p-5 border-b">
                        <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-edit mr-2 text-blue-600"></i>
                            Update Appointment
                        </h2>
                    </div>
                    
                    <form method="post" action="" class="p-5">
                        <input type="hidden" name="original_doctor_id" value="<?= $appointment['doctor_id'] ?>">
                        <input type="hidden" name="original_date" value="<?= $appointment['appointment_date'] ?>">
                        <input type="hidden" name="original_time_slot" value="<?= $appointment['time_slot'] ?>">
                        
                        <div class="grid md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label for="patient_id" class="block text-sm font-medium text-gray-700 mb-2">Patient</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-user text-gray-400"></i>
                                    </div>
                                    <select name="patient_id" id="patient_id" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                                        <?php 
                                        $patients_result->data_seek(0);
                                        while ($patient = $patients_result->fetch_assoc()): 
                                        ?>
                                            <option value="<?= $patient['patient_id'] ?>" <?= $appointment['patient_id'] == $patient['patient_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?> (<?= htmlspecialchars($patient['email']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label for="doctor_id" class="block text-sm font-medium text-gray-700 mb-2">Doctor</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-user-md text-gray-400"></i>
                                    </div>
                                    <select name="doctor_id" id="doctor_id" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required onchange="updateTimeSlots()">
                                        <?php 
                                        $doctors_result->data_seek(0);
                                        while ($doctor = $doctors_result->fetch_assoc()): 
                                        ?>
                                            <option value="<?= $doctor['doctor_id'] ?>" <?= $appointment['doctor_id'] == $doctor['doctor_id'] ? 'selected' : '' ?>>
                                                Dr. <?= htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']) ?> (<?= htmlspecialchars($doctor['speciality_name']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label for="appointment_date" class="block text-sm font-medium text-gray-700 mb-2">Appointment Date</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-calendar-alt text-gray-400"></i>
                                    </div>
                                    <input type="date" name="appointment_date" id="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= $appointment['appointment_date'] ?>" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required onchange="updateTimeSlots()">
                                </div>
                            </div>
                            
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-clipboard-check text-gray-400"></i>
                                    </div>
                                    <select name="status" id="status" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                                    <option value="completed" <?= $appointment['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="cancelled" <?= $appointment['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Available Time Slots</label>
                            <div id="time_slots_container">
                                <?php if (empty($available_slots)): ?>
                                    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4" role="alert">
                                        <p>No available time slots for the selected doctor on this date.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                        <?php foreach ($available_slots as $slot): ?>
                                            <label class="flex items-center p-3 border rounded-lg hover:bg-blue-50 cursor-pointer transition <?= $slot['is_current'] ? 'time-slot-selected' : '' ?>">
                                                <input type="radio" name="time_slot" value="<?= $slot['time'] ?>" class="mr-2 text-blue-600" required <?= $slot['is_current'] ? 'checked' : '' ?>>
                                                <span><?= $slot['formatted_time'] ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                            <div class="relative">
                                <div class="absolute top-3 left-3 flex items-center pointer-events-none">
                                    <i class="fas fa-sticky-note text-gray-400"></i>
                                </div>
                                <textarea name="notes" id="notes" rows="4" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?= htmlspecialchars($appointment['notes'] ?? '') ?></textarea>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <a href="admin_appointments.php" class="px-5 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                Cancel
                            </a>
                            <button type="submit" name="update_appointment" class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Update Appointment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Footer -->
            <footer class="bg-white p-4 border-t text-center text-sm text-gray-600">
                <p>© 2025 Clinic Management System. All rights reserved.</p>
            </footer>
        </div>
    </div>
</body>
</html>