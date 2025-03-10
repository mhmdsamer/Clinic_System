<?php
session_start();
require_once 'connection.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php?error=Unauthorized+Access");
    exit();
}

// Dashboard Statistics Queries
$total_users_query = "
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM users WHERE role = 'doctor') as total_doctors,
        (SELECT COUNT(*) FROM users WHERE role = 'patient') as total_patients
";
$total_users_result = $conn->query($total_users_query);
$user_stats = $total_users_result->fetch_assoc();

$recent_appointments_query = "
    SELECT 
        a.appointment_id, 
        p.first_name as patient_first_name, 
        p.last_name as patient_last_name,
        d.first_name as doctor_first_name, 
        d.last_name as doctor_last_name,
        a.appointment_date, 
        a.time_slot, 
        a.status
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    ORDER BY a.created_at DESC
    LIMIT 5
";
$recent_appointments_result = $conn->query($recent_appointments_query);

$invoice_stats_query = "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(amount) as total_revenue,
        (SELECT COUNT(*) FROM invoices WHERE payment_status = 'pending') as pending_invoices
    FROM invoices
";
$invoice_stats_result = $conn->query($invoice_stats_query);
$invoice_stats = $invoice_stats_result->fetch_assoc();

$specialties_query = "SELECT * FROM specialties";
$specialties_result = $conn->query($specialties_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZJVS/gpW6BzT9YyIqzZTY5sXl/sA1rZVfNWJ5D4IbRSxIk2XYsM36X6gKDe5" crossorigin="anonymous">

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
                        <a href="#dashboard" class="flex items-center p-3 menu-item menu-item-active rounded-lg">
                            <i class="fas fa-home mr-3 w-5 text-center"></i>Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="user_management.php" class="flex items-center p-3 menu-item rounded-lg">
                            <i class="fas fa-users mr-3 w-5 text-center"></i>User Management
                        </a>
                    </li>
                    <li>
                        <a href="admin_appointments.php" class="flex items-center p-3 menu-item rounded-lg">
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
                        <h1 class="text-2xl font-bold text-gray-800">Dashboard</h1>
                        <div class="flex items-center text-sm text-gray-500 mt-1">
                            <a href="#" class="hover:text-blue-600">Home</a>
                            <span class="mx-2">/</span>
                            <span class="text-gray-700">Dashboard</span>
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
                <!-- Overview Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Users Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Total Users</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1"><?= $user_stats['total_users'] ?></p>
                                </div>
                                <div class="w-14 h-14 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600">
                                    <i class="fas fa-users text-2xl"></i>
                                </div>
                            </div>
                            <div class="flex mt-4 space-x-4">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 rounded-full bg-green-500 mr-2"></div>
                                    <span class="text-sm text-gray-600"><?= $user_stats['total_doctors'] ?> Doctors</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="w-3 h-3 rounded-full bg-purple-500 mr-2"></div>
                                    <span class="text-sm text-gray-600"><?= $user_stats['total_patients'] ?> Patients</span>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-5 py-3 border-t">
                            <a href="user_management.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center">
                                View Details
                                <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Appointments Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Appointments</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1">
                                        <?php 
                                        $appointments_count = mysqli_num_rows($recent_appointments_result);
                                        echo $appointments_count; 
                                        // Reset the result pointer to use in the list below
                                        mysqli_data_seek($recent_appointments_result, 0);
                                        ?>
                                    </p>
                                </div>
                                <div class="w-14 h-14 bg-green-100 rounded-lg flex items-center justify-center text-green-600">
                                    <i class="fas fa-calendar-check text-2xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <?php if($appointments_count > 0): ?>
                                    <div class="text-xs text-gray-500 uppercase font-semibold mb-2">Recent Bookings</div>
                                    <?php $counter = 0; while($appointment = $recent_appointments_result->fetch_assoc()): 
                                        if($counter < 2): // Show only 2 appointments for better UI
                                        $counter++;
                                    ?>
                                        <div class="flex justify-between items-center text-sm py-1">
                                            <div class="flex items-center">
                                                <div class="w-7 h-7 rounded-full bg-blue-100 text-blue-800 flex items-center justify-center mr-2 text-xs">
                                                    <?= substr($appointment['patient_first_name'], 0, 1) . substr($appointment['patient_last_name'], 0, 1) ?>
                                                </div>
                                                <span class="font-medium truncate max-w-[150px]">
                                                    <?= $appointment['patient_first_name'] ?> <?= $appointment['patient_last_name'] ?>
                                                </span>
                                            </div>
                                            <div class="text-gray-500 text-xs"><?= $appointment['appointment_date'] ?></div>
                                        </div>
                                    <?php endif; endwhile; ?>
                                <?php else: ?>
                                    <p class="text-gray-500 text-sm">No recent appointments</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-5 py-3 border-t">
                            <a href="admin_appointments.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center">
                                Manage Appointments
                                <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Invoices Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Total Revenue</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1">$<?= number_format($invoice_stats['total_revenue'], 0) ?></p>
                                </div>
                                <div class="w-14 h-14 bg-purple-100 rounded-lg flex items-center justify-center text-purple-600">
                                    <i class="fas fa-file-invoice-dollar text-2xl"></i>
                                </div>
                            </div>
                            <div class="flex mt-4 space-x-4">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 rounded-full bg-blue-500 mr-2"></div>
                                    <span class="text-sm text-gray-600"><?= $invoice_stats['total_invoices'] ?> Invoices</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="w-3 h-3 rounded-full bg-red-500 mr-2"></div>
                                    <span class="text-sm text-gray-600"><?= $invoice_stats['pending_invoices'] ?> Pending</span>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-5 py-3 border-t">
                            <a href="invoices.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center">
                                View Invoices
                                <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions and Specialties -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                    <!-- Medical Specialties -->
                    <div class="md:col-span-3 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="flex justify-between items-center p-5 border-b">
                            <h3 class="text-lg font-semibold text-gray-800">Medical Specialties</h3>
                            <a href="specialties.php" class="text-sm text-blue-600 hover:underline">Manage</a>
                        </div>
                        <div class="p-5">
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                <?php 
                                $specialty_count = 0;
                                while($specialty = $specialties_result->fetch_assoc()): 
                                    $specialty_count++;
                                    $colors = [
                                        'bg-blue-100 text-blue-800', 
                                        'bg-green-100 text-green-800', 
                                        'bg-purple-100 text-purple-800',
                                        'bg-indigo-100 text-indigo-800',
                                        'bg-pink-100 text-pink-800',
                                        'bg-yellow-100 text-yellow-800'
                                    ];
                                    $color_class = $colors[$specialty_count % count($colors)];
                                ?>
                                <div class="<?= $color_class ?> p-3 rounded-lg text-center text-sm font-medium">
                                    <?= htmlspecialchars($specialty['speciality_name']) ?>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="md:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-5 border-b">
                            <h3 class="text-lg font-semibold text-gray-800">Quick Actions</h3>
                        </div>
                        <div class="p-5">
                            <div class="grid grid-cols-3 gap-3">
                                <a href="add_user.php" class="quick-action flex flex-col items-center p-4 rounded-lg bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200">
                                    <div class="w-10 h-10 rounded-full bg-blue-500 text-white flex items-center justify-center mb-2">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <span class="text-xs font-medium text-blue-700 text-center">Add User</span>
                                </a>
                                <a href="admin_appointments.php" class="quick-action flex flex-col items-center p-4 rounded-lg bg-gradient-to-br from-green-50 to-green-100 border border-green-200">
                                    <div class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center mb-2">
                                        <i class="fas fa-calendar-plus"></i>
                                    </div>
                                    <span class="text-xs font-medium text-green-700 text-center">New Appt</span>
                                </a>
                                <a href="invoices.php" class="quick-action flex flex-col items-center p-4 rounded-lg bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200">
                                    <div class="w-10 h-10 rounded-full bg-purple-500 text-white flex items-center justify-center mb-2">
                                        <i class="fas fa-file-invoice"></i>
                                    </div>
                                    <span class="text-xs font-medium text-purple-700 text-center">Create Invoice</span>
                                </a>
                            </div>
                            <div class="mt-4">
                                <a href="#" class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium text-center flex items-center justify-center transition">
                                    <i class="fas fa-chart-bar mr-2"></i>
                                    Generate Reports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="text-center text-gray-500 text-xs mt-8 pb-6">
                    <p>© 2025 Clinic_System. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Optional: Add dynamic charts or interactive elements here
    </script>
</body>
</html>