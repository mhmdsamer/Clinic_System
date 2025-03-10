<?php
session_start();
require_once 'connection.php';

// Ensure only logged-in patients can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

// Fetch patient details
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

$patient_id = $patient['patient_id'];

// Fetch medical records - organized by appointment
$medical_records_query = "
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.notes,
        a.status,
        d.first_name AS doctor_first_name,
        d.last_name AS doctor_last_name,
        s.speciality_name
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN specialties s ON d.speciality_id = s.speciality_id
    WHERE a.patient_id = ? AND a.status = 'completed' AND a.notes IS NOT NULL
    ORDER BY a.appointment_date DESC
";
$records_stmt = $conn->prepare($medical_records_query);
$records_stmt->bind_param("i", $patient_id);
$records_stmt->execute();
$records_result = $records_stmt->get_result();

// Fetch patient's general medical history
$medical_history = $patient['medical_history'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Records - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }
        .nav-glass {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
        }
        .action-btn {
            transition: all 0.3s ease;
        }
        .action-btn:hover {
            transform: translateY(-3px);
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
                        <a href="homepage.php" class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-600 to-blue-500">
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
            <!-- Header Section -->
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-extrabold text-white">
                    <span class="bg-clip-text text-transparent bg-gradient-to-r from-white to-purple-200">
                        <i class="fas fa-file-medical mr-3"></i>Your Medical Records
                    </span>
                </h1>
                <a href="homepage.php" class="bg-white action-btn text-purple-600 px-6 py-3 rounded-full shadow-lg hover:bg-purple-50 transition flex items-center">
                    <i class="fas fa-home mr-2"></i>Back to Dashboard
                </a>
            </div>
            
            <!-- Patient Information Card -->
            <div class="glass-card p-8 mb-8 relative overflow-hidden">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-user-circle text-purple-600 mr-3"></i>Patient Information
                </h2>
                <div class="grid md:grid-cols-3 gap-8">
                    <div class="bg-purple-50 rounded-lg p-5">
                        <p class="text-gray-500 text-sm mb-1">Full Name</p>
                        <p class="text-xl font-bold text-purple-600"><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></p>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-5">
                        <p class="text-gray-500 text-sm mb-1">Date of Birth</p>
                        <p class="text-xl font-bold text-purple-600">
                            <?= date('M d, Y', strtotime($patient['dob'])) ?> 
                            <span class="text-base font-normal text-gray-600">(<?= date_diff(date_create($patient['dob']), date_create('today'))->y ?> years)</span>
                        </p>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-5">
                        <p class="text-gray-500 text-sm mb-1">Gender</p>
                        <p class="text-xl font-bold text-purple-600"><?= ucfirst(htmlspecialchars($patient['gender'])) ?></p>
                    </div>
                </div>
            </div>

            <!-- Medical History Section -->
            <div class="glass-card p-8 mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-history text-green-500 mr-3"></i>Medical History
                </h2>
                
                <div class="bg-purple-50 rounded-lg p-6">
                    <?php if (!empty($medical_history)): ?>
                        <div class="prose max-w-none">
                            <?= nl2br(htmlspecialchars($medical_history)) ?>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center">
                            <div class="w-12 h-12 flex-shrink-0 bg-yellow-100 rounded-full flex items-center justify-center mr-4">
                                <i class="fas fa-info-circle text-2xl text-yellow-500"></i>
                            </div>
                            <p class="text-gray-600">No medical history on file. Please contact your doctor to update your medical history.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Medical Records From Appointments Section -->
            <section>
                <h2 class="text-2xl font-bold text-white mb-6 flex items-center">
                    <i class="fas fa-clipboard-list mr-3"></i>Visit Records
                </h2>

                <?php if ($records_result->num_rows > 0): ?>
                    <div class="grid md:grid-cols-1 gap-6">
                        <?php while($record = $records_result->fetch_assoc()): ?>
                            <div x-data="{ open: false }" class="glass-card overflow-hidden">
                                <!-- Record Header -->
                                <div class="p-6 flex justify-between items-center cursor-pointer" @click="open = !open">
                                    <div class="flex items-center">
                                        <div class="w-14 h-14 flex-shrink-0 bg-blue-100 rounded-full flex items-center justify-center mr-4">
                                            <i class="fas fa-calendar-check text-2xl text-blue-500"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-xl font-bold text-gray-800">
                                                <?= date('F d, Y', strtotime($record['appointment_date'])) ?>
                                            </h3>
                                            <p class="text-gray-600">
                                                <i class="fas fa-user-md mr-1 text-purple-500"></i>
                                                Dr. <?= htmlspecialchars($record['doctor_first_name'] . ' ' . $record['doctor_last_name']) ?> 
                                                <span class="mx-2">•</span>
                                                <i class="fas fa-stethoscope mr-1 text-purple-500"></i>
                                                <?= htmlspecialchars($record['speciality_name']) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <button class="w-10 h-10 flex items-center justify-center rounded-full bg-purple-100 text-purple-600 transition-all duration-300" 
                                            :class="{'transform rotate-180': open}">
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                </div>
                                
                                <!-- Record Details (collapsible) -->
                                <div class="border-t border-gray-200" x-show="open" x-cloak>
                                    <div class="p-6">
                                        <h4 class="font-semibold text-gray-700 mb-3 flex items-center">
                                            <i class="fas fa-clipboard-list text-purple-500 mr-2"></i>Doctor's Notes:
                                        </h4>
                                        <div class="bg-purple-50 p-5 rounded-lg mb-4">
                                            <?= nl2br(htmlspecialchars($record['notes'])) ?>
                                        </div>
                                        
                                        <div class="flex justify-end mt-4">
                                            <button class="bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-3 rounded-full action-btn" 
                                                    @click="window.print()">
                                                <i class="fas fa-print mr-2"></i>Print Record
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="glass-card p-8 text-center">
                        <div class="w-20 h-20 bg-yellow-100 rounded-full mx-auto flex items-center justify-center mb-4">
                            <i class="fas fa-file-medical-alt text-4xl text-yellow-500"></i>
                        </div>
                        <p class="text-gray-700 text-lg mb-4">No visit records found.</p>
                        <p class="text-gray-600 mb-6">Records will appear here after your completed appointments.</p>
                        <a href="appointments.php" class="inline-block bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-3 rounded-full hover:from-purple-700 hover:to-blue-600 transition action-btn">
                            <i class="fas fa-calendar-plus mr-2"></i>Book an Appointment
                        </a>
                    </div>
                <?php endif; ?>
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