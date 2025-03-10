<?php
session_start();
require_once 'connection.php';

// Ensure only logged-in patients can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

// Fetch patient details - use user_id instead of patient_id from session
$user_id = $_SESSION['user_id'];
$patient_query = "
    SELECT p.*, u.username, u.email 
    FROM patients p
    JOIN users u ON p.user_id = u.user_id
    WHERE u.user_id = ?
";
$patient_stmt = $conn->prepare($patient_query);
$patient_stmt->bind_param("i", $user_id);
$patient_stmt->execute();
$patient_result = $patient_stmt->get_result();
$patient = $patient_result->fetch_assoc();

// Double-check patient exists
if (!$patient) {
    session_destroy();
    header("Location: login_process.php?error=Patient+Not+Found");
    exit();
}

// Now safely use patient_id for subsequent queries
$patient_id = $patient['patient_id'];

// Fetch upcoming appointments - MODIFIED: removed LIMIT to get all upcoming appointments
$upcoming_appointments_query = "
    SELECT 
        a.appointment_id, 
        a.appointment_date, 
        a.time_slot, 
        d.first_name AS doctor_first_name, 
        d.last_name AS doctor_last_name,
        s.speciality_name
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN specialties s ON d.speciality_id = s.speciality_id
    WHERE a.patient_id = ? AND a.status = 'scheduled' AND a.appointment_date >= CURDATE()
    ORDER BY a.appointment_date, a.time_slot
";
$upcoming_stmt = $conn->prepare($upcoming_appointments_query);
$upcoming_stmt->bind_param("i", $patient_id);
$upcoming_stmt->execute();
$upcoming_result = $upcoming_stmt->get_result();

// Fetch past appointments - MODIFIED: Only get completed appointments
$past_appointments_query = "
    SELECT 
        a.appointment_id, 
        a.appointment_date, 
        a.time_slot, 
        a.status,
        d.first_name AS doctor_first_name, 
        d.last_name AS doctor_last_name,
        s.speciality_name
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN specialties s ON d.speciality_id = s.speciality_id
    WHERE a.patient_id = ? AND a.status = 'completed'
    ORDER BY a.appointment_date DESC
    LIMIT 3
";
$past_stmt = $conn->prepare($past_appointments_query);
$past_stmt->bind_param("i", $patient_id);
$past_stmt->execute();
$past_result = $past_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZJVS/gpW6BzT9YyIqzZTY5sXl/sA1rZVfNWJ5D4IbRSxIk2XYsM36X6gKDe5" crossorigin="anonymous">

    
    <style>
        .gradient-bg {
            background: linear-gradient(120deg, #5e60ce 0%, #6930c3 50%, #5390d9 100%);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        .glass-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }
        .nav-glass {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
        }
        .profile-ring {
            background: conic-gradient(#6930c3 0%, #5e60ce 50%, #5390d9 100%);
            padding: 4px;
            border-radius: 50%;
        }
        .action-btn {
            transition: all 0.3s ease;
        }
        .action-btn:hover {
            transform: translateY(-3px);
        }
        .status-bubble {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(104, 58, 183, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(104, 58, 183, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(104, 58, 183, 0);
            }
        }
        .ribbon {
            position: absolute;
            top: 0;
            right: 0;
            width: 120px;
            height: 120px;
            overflow: hidden;
        }
        .ribbon-text {
            position: absolute;
            display: block;
            width: 180px;
            padding: 8px 0;
            background-color: #6930c3;
            box-shadow: 0 5px 10px rgba(0,0,0,.1);
            color: #fff;
            font-size: 13px;
            text-transform: uppercase;
            text-align: center;
            right: -40px;
            top: 30px;
            transform: rotate(45deg);
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen gradient-bg">
        <nav class="nav-glass sticky top-0 z-50 shadow-md border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center">
                        <a href="#" class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-600 to-blue-500">
                            <i class="fas fa-hospital-user mr-2"></i>CMS Patient Portal
                        </a>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div x-data="{ dropdownOpen: false }" class="relative">
                            <button @click="dropdownOpen = !dropdownOpen" class="flex items-center text-gray-700 focus:outline-none">
                                <span class="mr-2 font-medium">
                                    <i class="fas fa-user-circle mr-2 text-purple-600"></i><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>
                                </span>
                                <i class="fas fa-chevron-down text-sm"></i>
                            </button>
                            <div x-show="dropdownOpen" @click.away="dropdownOpen = false" x-cloak
                                 class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                                <a href="profile_settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-purple-100">
                                    <i class="fas fa-user-cog mr-2"></i>Profile Settings
                                </a>
                                <a href="medical_records.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-purple-100">
                                    <i class="fas fa-file-medical mr-2"></i>Medical Records
                                </a>
                                <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-100">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <main class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
            <!-- Quick Summary Stats -->
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-extrabold text-white">
                    <span class="bg-clip-text text-transparent bg-gradient-to-r from-white to-purple-200">Welcome back, <?= htmlspecialchars($patient['first_name']) ?>!</span>
                </h1>
                <a href="appointments.php" class="bg-white action-btn text-purple-600 px-6 py-3 rounded-full shadow-lg hover:bg-purple-50 transition flex items-center">
                    <i class="fas fa-calendar-plus mr-2"></i>New Appointment
                </a>
            </div>
            
            <div class="grid md:grid-cols-3 gap-8">
                <!-- Patient Profile Card -->
                <div class="glass-card p-8 relative overflow-hidden">
                    <div class="text-center">
                        <div class="profile-ring w-32 h-32 mx-auto mb-6">
                            <div class="w-full h-full bg-white rounded-full flex items-center justify-center">
                                <i class="fas fa-user-circle text-6xl text-purple-600"></i>
                            </div>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">
                            <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>
                        </h2>
                        <p class="text-gray-600 mb-6 flex items-center justify-center">
                            <i class="fas fa-envelope mr-2"></i><?= htmlspecialchars($patient['email']) ?>
                        </p>
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div class="bg-purple-50 rounded-lg p-3">
                                <span class="block text-xl font-bold text-purple-600"><?= htmlspecialchars($patient['gender']) ?></span>
                                <span class="text-gray-600 text-sm">Gender</span>
                            </div>
                            <div class="bg-purple-50 rounded-lg p-3">
                                <span class="block text-xl font-bold text-purple-600">
                                    <?= date_diff(date_create($patient['dob']), date_create('today'))->y ?>
                                </span>
                                <span class="text-gray-600 text-sm">Age</span>
                            </div>
                            <div class="bg-purple-50 rounded-lg p-3">
                                <span class="block text-xl font-bold text-purple-600"><?= htmlspecialchars($patient['phone']) ?></span>
                                <span class="text-gray-600 text-sm">Phone</span>
                            </div>
                        </div>
                        <a href="profile_settings.php" class="mt-6 inline-block bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-2 rounded-full action-btn">
                            <i class="fas fa-user-edit mr-2"></i>Edit Profile
                        </a>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <a href="appointments.php" class="glass-card p-6 flex items-center action-btn">
                        <div class="w-16 h-16 flex-shrink-0 bg-green-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-calendar-plus text-3xl text-green-500"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-800">Book Appointment</h3>
                            <p class="text-gray-600 mt-1">Schedule a new consultation with a doctor</p>
                        </div>
                    </a>

                    <a href="medical_records.php" class="glass-card p-6 flex items-center action-btn">
                        <div class="w-16 h-16 flex-shrink-0 bg-blue-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-file-medical text-3xl text-blue-500"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-800">Medical Records</h3>
                            <p class="text-gray-600 mt-1">View your medical history and documents</p>
                        </div>
                    </a>

                    <a href="profile_settings.php" class="glass-card p-6 flex items-center action-btn">
                        <div class="w-16 h-16 flex-shrink-0 bg-purple-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-user-cog text-3xl text-purple-500"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-800">Profile Settings</h3>
                            <p class="text-gray-600 mt-1">Update your personal information</p>
                        </div>
                    </a>

                    <a href="#" class="glass-card p-6 flex items-center action-btn">
                        <div class="w-16 h-16 flex-shrink-0 bg-indigo-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-comment-medical text-3xl text-indigo-500"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-800">Message Doctor</h3>
                            <p class="text-gray-600 mt-1">Communicate with your healthcare provider</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Upcoming Appointments -->
            <section class="mt-12">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-white">
                        <i class="fas fa-calendar-check mr-3"></i>Your Upcoming Appointments
                    </h2>
                    <a href="appointments.php" class="bg-white text-purple-600 px-4 py-2 rounded-full hover:bg-purple-50 transition action-btn flex items-center">
                        <i class="fas fa-calendar mr-2"></i>View All
                    </a>
                </div>
                
                <div class="grid md:grid-cols-3 gap-6">
                    <?php if ($upcoming_result->num_rows > 0): ?>
                        <?php 
                        $count = 0;
                        while($appointment = $upcoming_result->fetch_assoc()): 
                            // Show a maximum of 3 appointments in the grid
                            if($count < 3):
                                // Calculate if the appointment is today
                                $is_today = $appointment['appointment_date'] == date('Y-m-d');
                        ?>
                            <div class="glass-card p-6 relative">
                                <?php if($is_today): ?>
                                <div class="ribbon">
                                    <span class="ribbon-text">Today</span>
                                </div>
                                <?php endif; ?>
                                <div class="flex items-center mb-4">
                                    <div class="w-12 h-12 flex-shrink-0 bg-green-100 rounded-full flex items-center justify-center mr-4 <?= $is_today ? 'pulse-animation' : '' ?>">
                                        <i class="fas fa-calendar-alt text-2xl text-green-500"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-800">
                                            <?= date('D, M d, Y', strtotime($appointment['appointment_date'])) ?>
                                        </h3>
                                        <p class="text-gray-600">
                                            <?= date('h:i A', strtotime($appointment['time_slot'])) ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="border-t border-gray-200 pt-4">
                                    <div class="flex items-center mb-2">
                                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                            <i class="fas fa-user-md text-blue-500"></i>
                                        </div>
                                        <p class="text-gray-700 font-medium">
                                            Dr. <?= htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']) ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center mb-3">
                                        <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                                            <i class="fas fa-stethoscope text-purple-500"></i>
                                        </div>
                                        <p class="text-gray-700 font-medium">
                                            <?= htmlspecialchars($appointment['speciality_name']) ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-4 flex space-x-2">
                                    <a href="reschedule_appointment.php?id=<?= $appointment['appointment_id'] ?>" class="bg-amber-500 text-white px-4 py-2 rounded-full text-sm hover:bg-amber-600 flex-1 text-center action-btn flex items-center justify-center">
                                        <i class="fas fa-clock mr-2"></i>Reschedule
                                    </a>
                                    <a href="cancel_appointment.php?id=<?= $appointment['appointment_id'] ?>" class="bg-red-500 text-white px-4 py-2 rounded-full text-sm hover:bg-red-600 flex-1 text-center action-btn flex items-center justify-center">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                </div>
                            </div>
                        <?php 
                            $count++;
                            endif;
                        endwhile; 
                        
                        // If there are more appointments than we displayed, show a note
                        if ($upcoming_result->num_rows > 3):
                        ?>
                            <div class="glass-card p-6 flex flex-col justify-center items-center text-center md:col-span-3">
                                <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-info-circle text-3xl text-blue-500"></i>
                                </div>
                                <p class="text-gray-700 text-lg mb-4">
                                    You have <span class="font-bold text-purple-600"><?= $upcoming_result->num_rows - 3 ?></span> more upcoming appointments.
                                </p>
                                <a href="appointments.php" class="bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-3 rounded-full hover:from-purple-700 hover:to-blue-600 transition action-btn">
                                    <i class="fas fa-eye mr-2"></i>View All Appointments
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="glass-card p-8 md:col-span-3 text-center">
                            <div class="w-20 h-20 bg-gray-100 rounded-full mx-auto flex items-center justify-center mb-4">
                                <i class="fas fa-calendar-times text-4xl text-gray-400"></i>
                            </div>
                            <p class="text-gray-600 text-lg mb-4">You don't have any upcoming appointments</p>
                            <a href="appointments.php" class="inline-block bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-3 rounded-full hover:from-purple-700 hover:to-blue-600 transition action-btn">
                                <i class="fas fa-calendar-plus mr-2"></i>Book an Appointment
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Past Appointments - MODIFIED: Only show completed appointments -->
            <section class="mt-12 mb-10">
                <h2 class="text-2xl font-bold text-white mb-6">
                    <i class="fas fa-check-circle mr-3"></i>Completed Appointments
                </h2>
                <div class="grid md:grid-cols-3 gap-6">
                    <?php if ($past_result->num_rows > 0): ?>
                        <?php while($appointment = $past_result->fetch_assoc()): ?>
                            <div class="glass-card p-6">
                                <div class="flex items-center mb-4">
                                    <div class="w-12 h-12 flex-shrink-0 bg-green-100 rounded-full flex items-center justify-center mr-4">
                                        <i class="fas fa-check-circle text-2xl text-green-500"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-800">
                                            <?= date('D, M d, Y', strtotime($appointment['appointment_date'])) ?>
                                        </h3>
                                        <p class="text-gray-600">
                                            <?= date('h:i A', strtotime($appointment['time_slot'])) ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="border-t border-gray-200 pt-4">
                                    <div class="flex items-center mb-2">
                                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                            <i class="fas fa-user-md text-blue-500"></i>
                                        </div>
                                        <p class="text-gray-700 font-medium">
                                            Dr. <?= htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']) ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center mb-3">
                                        <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                                            <i class="fas fa-stethoscope text-purple-500"></i>
                                        </div>
                                        <p class="text-gray-700 font-medium">
                                            <?= htmlspecialchars($appointment['speciality_name']) ?>
                                        </p>
                                    </div>
                                    <div class="bg-gray-50 rounded-lg p-3 mt-2">
                                        <p class="text-gray-700 flex items-center">
                                            <span class="status-bubble bg-green-500"></span>
                                            <span class="font-medium">Status:</span> 
                                            <span class="ml-2 text-green-600">Completed</span>
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <a href="medical_records.php?appointment=<?= $appointment['appointment_id'] ?>" class="bg-blue-500 text-white px-4 py-2 rounded-full text-sm hover:bg-blue-600 w-full text-center block action-btn">
                                        <i class="fas fa-file-medical mr-2"></i>View Medical Notes
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="glass-card p-6 md:col-span-3 text-center">
                            <div class="w-20 h-20 bg-gray-100 rounded-full mx-auto flex items-center justify-center mb-4">
                                <i class="fas fa-history text-4xl text-gray-400"></i>
                            </div>
                            <p class="text-gray-600">No completed appointments yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
        
        <footer class="bg-gray-800 text-white py-6 mt-12">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <div class="mb-4 md:mb-0">
                        <p class="text-sm">© 2025 Clinic Management System. All rights reserved.</p>
                    </div>
                    <div class="flex space-x-4">
                        <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</body>
</html>