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

// Handle status updates or appointment deletions if requested
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $appointment_id = $_GET['id'];
    
    if ($action === 'complete') {
        $update_query = "UPDATE appointments SET status = 'completed' WHERE appointment_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("i", $appointment_id);
        
        if ($update_stmt->execute()) {
            $message = "Appointment marked as completed successfully!";
        } else {
            $error = "Error updating appointment: " . $conn->error;
        }
    } elseif ($action === 'cancel') {
        $update_query = "UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("i", $appointment_id);
        
        if ($update_stmt->execute()) {
            $message = "Appointment cancelled successfully!";
        } else {
            $error = "Error cancelling appointment: " . $conn->error;
        }
    } elseif ($action === 'delete') {
        // First check if we can delete (no invoices attached)
        $check_query = "SELECT COUNT(*) as count FROM invoices WHERE appointment_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $appointment_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        
        if ($check_data['count'] > 0) {
            $error = "Cannot delete appointment because it has invoices attached.";
        } else {
            // Proceed with deletion
            $delete_query = "DELETE FROM appointments WHERE appointment_id = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("i", $appointment_id);
            
            if ($delete_stmt->execute()) {
                $message = "Appointment deleted successfully!";
            } else {
                $error = "Error deleting appointment: " . $conn->error;
            }
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$doctor_filter = $_GET['doctor'] ?? 0;
$specialty_filter = $_GET['specialty'] ?? 0;
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 month'));
$date_to = $_GET['date_to'] ?? date('Y-m-d', strtotime('+1 month'));
$search = $_GET['search'] ?? '';

// Construct the base query
$appointments_query = "
    SELECT 
        a.appointment_id, 
        a.appointment_date, 
        a.time_slot, 
        a.status,
        a.notes,
        p.patient_id,
        p.first_name as patient_first_name, 
        p.last_name as patient_last_name,
        d.doctor_id,
        d.first_name as doctor_first_name, 
        d.last_name as doctor_last_name, 
        s.speciality_id,
        s.speciality_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN specialties s ON d.speciality_id = s.speciality_id
    WHERE 1=1
";

// Apply filters
$params = [];
$types = "";

// Date range filter
$appointments_query .= " AND a.appointment_date BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;
$types .= "ss";

// Status filter
if ($status_filter !== 'all') {
    $appointments_query .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Doctor filter
if ($doctor_filter > 0) {
    $appointments_query .= " AND a.doctor_id = ?";
    $params[] = $doctor_filter;
    $types .= "i";
}

// Specialty filter
if ($specialty_filter > 0) {
    $appointments_query .= " AND d.speciality_id = ?";
    $params[] = $specialty_filter;
    $types .= "i";
}

// Search filter
if (!empty($search)) {
    $search_param = "%$search%";
    $appointments_query .= " AND (
        p.first_name LIKE ? OR 
        p.last_name LIKE ? OR 
        d.first_name LIKE ? OR 
        d.last_name LIKE ? OR
        a.notes LIKE ?
    )";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sssss";
}

// Order by date and time
$appointments_query .= " ORDER BY a.appointment_date, a.time_slot";

// Prepare and execute the query
$stmt = $conn->prepare($appointments_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$appointments_result = $stmt->get_result();

// Fetch all doctors for filter dropdown
$doctors_query = "
    SELECT d.doctor_id, d.first_name, d.last_name 
    FROM doctors d
    ORDER BY d.last_name, d.first_name
";
$doctors_result = $conn->query($doctors_query);

// Fetch all specialties for filter dropdown
$specialties_query = "SELECT * FROM specialties ORDER BY speciality_name";
$specialties_result = $conn->query($specialties_query);

// Count total appointments by status for dashboard
$status_counts = [
    'scheduled' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'total' => 0
];

$count_query = "
    SELECT status, COUNT(*) as count
    FROM appointments
    WHERE appointment_date BETWEEN ? AND ?
    GROUP BY status
";
$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param("ss", $date_from, $date_to);
$count_stmt->execute();
$count_result = $count_stmt->get_result();

while ($row = $count_result->fetch_assoc()) {
    $status_counts[$row['status']] = $row['count'];
    $status_counts['total'] += $row['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Appointments - Clinic Management System</title>
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
    </style>
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
                        <a href="#" class="flex items-center p-3 menu-item menu-item-active rounded-lg">
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
                        <h1 class="text-2xl font-bold text-gray-800">All Appointments</h1>
                        <div class="flex items-center text-sm text-gray-500 mt-1">
                            <a href="admin_dashboard.php" class="hover:text-blue-600">Home</a>
                            <span class="mx-2">/</span>
                            <span class="text-gray-700">Appointments</span>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <a href="admin_appointments.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow-sm transition duration-300 flex items-center">
                            <i class="fas fa-plus mr-2"></i>
                            New Appointment
                        </a>
                        <button class="p-2 bg-gray-100 rounded-full hover:bg-gray-200 text-gray-600 focus:outline-none">
                            <i class="far fa-bell"></i>
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
                <?php if ($message): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded-md shadow-sm" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-3 text-green-500"></i>
                            <p><?= $message ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded-md shadow-sm" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-3 text-red-500"></i>
                            <p><?= $error ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Status Dashboard Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="bg-white rounded-xl shadow-sm p-6 stat-card border border-gray-100">
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Appointments</p>
                                <p class="text-3xl font-bold text-gray-800 mt-1"><?= $status_counts['total'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-indigo-100 rounded-lg flex items-center justify-center text-indigo-600">
                                <i class="fas fa-calendar text-2xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-sm p-6 stat-card border border-gray-100">
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Scheduled</p>
                                <p class="text-3xl font-bold text-gray-800 mt-1"><?= $status_counts['scheduled'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-yellow-100 rounded-lg flex items-center justify-center text-yellow-600">
                                <i class="fas fa-clock text-2xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-sm p-6 stat-card border border-gray-100">
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Completed</p>
                                <p class="text-3xl font-bold text-gray-800 mt-1"><?= $status_counts['completed'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-green-100 rounded-lg flex items-center justify-center text-green-600">
                                <i class="fas fa-check-circle text-2xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-sm p-6 stat-card border border-gray-100">
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Cancelled</p>
                                <p class="text-3xl font-bold text-gray-800 mt-1"><?= $status_counts['cancelled'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-red-100 rounded-lg flex items-center justify-center text-red-600">
                                <i class="fas fa-times-circle text-2xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-filter mr-3 text-blue-600"></i>Filter Appointments
                        </h2>
                        <a href="admin_appointments.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow-sm transition duration-300 text-sm flex items-center">
                            <i class="fas fa-plus mr-2"></i>
                            New Appointment
                        </a>
                    </div>
                    
                    <form method="get" action="" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                            <select name="status" id="status" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="scheduled" <?= $status_filter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="doctor" class="block text-sm font-medium text-gray-700 mb-2">Doctor</label>
                            <select name="doctor" id="doctor" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150">
                                <option value="0">All Doctors</option>
                                <?php while ($doctor = $doctors_result->fetch_assoc()): ?>
                                    <option value="<?= $doctor['doctor_id'] ?>" <?= $doctor_filter == $doctor['doctor_id'] ? 'selected' : '' ?>>
                                        Dr. <?= htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="specialty" class="block text-sm font-medium text-gray-700 mb-2">Specialty</label>
                            <select name="specialty" id="specialty" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150">
                                <option value="0">All Specialties</option>
                                <?php while ($specialty = $specialties_result->fetch_assoc()): ?>
                                    <option value="<?= $specialty['speciality_id'] ?>" <?= $specialty_filter == $specialty['speciality_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($specialty['speciality_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                            <input type="date" name="date_from" id="date_from" value="<?= $date_from ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150">
                        </div>
                        
                        <div>
                            <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                            <input type="date" name="date_to" id="date_to" value="<?= $date_to ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150">
                        </div>
                        
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="text" name="search" id="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or notes..." class="w-full pl-10 pr-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150">
                            </div>
                        </div>
                        
                        <div class="md:col-span-3 flex justify-end space-x-3 mt-2">
                            <a href="admin_all_appointments.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition duration-150 text-sm flex items-center">
                                <i class="fas fa-undo mr-2"></i>Reset Filters
                            </a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow-sm transition duration-300 text-sm flex items-center">
                                <i class="fas fa-search mr-2"></i>Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Appointments Table -->
                <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-list-alt mr-3 text-blue-600"></i>Appointments List
                    </h2>
                    
                    <?php if ($appointments_result->num_rows > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr class="bg-gray-50">
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Patient</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Doctor</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Specialty</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php while ($appointment = $appointments_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-50 transition duration-150">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                #<?= $appointment['appointment_id'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-800">
                                                        <?= substr($appointment['patient_first_name'], 0, 1) . substr($appointment['patient_last_name'], 0, 1) ?>
                                                    </div>
                                                    <div class="ml-4">
                                                        <a href="admin_patient_details.php?id=<?= $appointment['patient_id'] ?>" class="text-sm font-medium text-gray-900 hover:text-blue-600">
                                                            <?= htmlspecialchars($appointment['patient_first_name'] . ' ' . $appointment['patient_last_name']) ?>
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <a href="admin_doctor_details.php?id=<?= $appointment['doctor_id'] ?>" class="text-sm text-gray-600 hover:text-blue-600">
                                                    Dr. <?= htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']) ?>
                                                </a>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="text-sm text-gray-600">
                                                    <?= htmlspecialchars($appointment['speciality_name']) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-600">
                                                    <?= date('M d, Y', strtotime($appointment['appointment_date'])) ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?= date('h:i A', strtotime($appointment['time_slot'])) ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($appointment['status'] === 'scheduled'): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                        <i class="fas fa-clock mr-1"></i> Scheduled
                                                    </span>
                                                <?php elseif ($appointment['status'] === 'completed'): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                        <i class="fas fa-check-circle mr-1"></i> Completed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                        <i class="fas fa-times-circle mr-1"></i> Cancelled
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-600 max-w-xs truncate">
                                                    <?= htmlspecialchars($appointment['notes'] ?? 'No notes') ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <div class="flex space-x-2">
                                                    <a href="admin_appointment_details.php?id=<?= $appointment['appointment_id'] ?>" 
                                                       class="text-blue-600 hover:text-blue-900 px-2 py-1 rounded-md hover:bg-blue-50">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    
                                                    <?php if ($appointment['status'] === 'scheduled'): ?>
                                                        <a href="?action=complete&id=<?= $appointment['appointment_id'] ?>&<?= http_build_query($_GET) ?>" 
                                                           class="text-green-600 hover:text-green-900 px-2 py-1 rounded-md hover:bg-green-50"
                                                           onclick="return confirm('Mark this appointment as completed?')">
                                                            <i class="fas fa-check-circle"></i>
                                                        </a>
                                                        
                                                        <a href="?action=cancel&id=<?= $appointment['appointment_id'] ?>&<?= http_build_query($_GET) ?>" 
                                                           class="text-red-600 hover:text-red-900 px-2 py-1 rounded-md hover:bg-red-50"
                                                           onclick="return confirm('Are you sure you want to cancel this appointment?')">
                                                            <i class="fas fa-times-circle"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="edit_appointment.php?id=<?= $appointment['appointment_id'] ?>" 
                                                       class="text-indigo-600 hover:text-indigo-900 px-2 py-1 rounded-md hover:bg-indigo-50">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <a href="?action=delete&id=<?= $appointment['appointment_id'] ?>&<?= http_build_query($_GET) ?>" 
                                                       class="text-red-600 hover:text-red-900 px-2 py-1 rounded-md hover:bg-red-50"
                                                       onclick="return confirm('Are you sure you want to delete this appointment? This action cannot be undone.')">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-yellow-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-yellow-700">
                                        No appointments found matching your criteria. Try adjusting your filters.
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Footer -->
                <div class="text-center text-gray-500 text-sm mt-8 pb-6">
                    &copy; <?= date('Y') ?> Clinic Management System. All rights reserved.
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize datepickers or other JS functionality if needed
        document.addEventListener('DOMContentLoaded', function() {
            // Any additional JavaScript can go here
        });
    </script>
</body>
</html>