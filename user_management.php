<?php
session_start();
require_once 'connection.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php?error=Unauthorized+Access");
    exit();
}

// Handle user deletion
if (isset($_GET['delete']) && isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    
    try {
        // Start transaction
        $conn->begin_transaction();

        // Delete user (cascading delete will remove related records)
        $delete_sql = "DELETE FROM users WHERE user_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $user_id);
        $delete_stmt->execute();

        // Commit transaction
        $conn->commit();

        header("Location: user_management.php?success=User+deleted+successfully");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: user_management.php?error=Failed+to+delete+user");
        exit();
    }
}

// Fetch users with their additional details
$users_query = "
    SELECT 
        u.user_id, 
        u.username, 
        u.email, 
        u.role,
        COALESCE(d.first_name, p.first_name) as first_name,
        COALESCE(d.last_name, p.last_name) as last_name,
        COALESCE(d.phone, p.phone) as phone,
        s.speciality_name
    FROM users u
    LEFT JOIN doctors d ON u.user_id = d.user_id
    LEFT JOIN patients p ON u.user_id = p.user_id
    LEFT JOIN specialties s ON d.speciality_id = s.speciality_id
    ORDER BY u.role, u.username
";
$users_result = $conn->query($users_query);

// Get user statistics
$user_stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM users WHERE role = 'doctor') as total_doctors,
        (SELECT COUNT(*) FROM users WHERE role = 'patient') as total_patients,
        (SELECT COUNT(*) FROM users WHERE role = 'admin') as total_admins
";
$user_stats_result = $conn->query($user_stats_query);
$user_stats = $user_stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Clinic Management System</title>
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
                        <a href="user_management.php" class="flex items-center p-3 menu-item menu-item-active rounded-lg">
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
                        <h1 class="text-2xl font-bold text-gray-800">User Management</h1>
                        <div class="flex items-center text-sm text-gray-500 mt-1">
                            <a href="admin_dashboard.php" class="hover:text-blue-600">Home</a>
                            <span class="mx-2">/</span>
                            <span class="text-gray-700">User Management</span>
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
                <!-- Display success or error messages -->
                <?php
                if (isset($_GET['success'])) {
                    echo '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6" role="alert">' . 
                        htmlspecialchars($_GET['success']) . 
                        '</div>';
                }
                if (isset($_GET['error'])) {
                    echo '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">' . 
                        htmlspecialchars($_GET['error']) . 
                        '</div>';
                }
                ?>

                <!-- User Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <!-- Total Users Card -->
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
                        </div>
                    </div>

                    <!-- Doctors Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Doctors</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1"><?= $user_stats['total_doctors'] ?></p>
                                </div>
                                <div class="w-14 h-14 bg-green-100 rounded-lg flex items-center justify-center text-green-600">
                                    <i class="fas fa-user-md text-2xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Patients Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Patients</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1"><?= $user_stats['total_patients'] ?></p>
                                </div>
                                <div class="w-14 h-14 bg-purple-100 rounded-lg flex items-center justify-center text-purple-600">
                                    <i class="fas fa-hospital-user text-2xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Admins Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Admins</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1"><?= $user_stats['total_admins'] ?></p>
                                </div>
                                <div class="w-14 h-14 bg-yellow-100 rounded-lg flex items-center justify-center text-yellow-600">
                                    <i class="fas fa-user-shield text-2xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Management Table -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="flex justify-between items-center p-5 border-b">
                        <h3 class="text-lg font-semibold text-gray-800">User List</h3>
                        <a href="add_user.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition flex items-center">
                            <i class="fas fa-plus mr-2"></i>Add New User
                        </a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                                    <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                                    <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Specialty</th>
                                    <th class="p-4 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php while($user = $users_result->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($user['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($user['email']) ?></td>
                                    <td class="p-4 whitespace-nowrap">
                                        <span class="
                                            <?= 
                                                $user['role'] === 'admin' ? 'bg-blue-100 text-blue-800' : 
                                                ($user['role'] === 'doctor' ? 'bg-green-100 text-green-800' : 'bg-purple-100 text-purple-800')
                                            ?> 
                                            px-2 py-1 rounded-full text-xs uppercase font-semibold"
                                        >
                                            <?= htmlspecialchars($user['role']) ?>
                                        </span>
                                    </td>
                                    <td class="p-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></td>
                                    <td class="p-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($user['phone'] ?? 'N/A') ?></td>
                                    <td class="p-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($user['speciality_name'] ?? 'N/A') ?></td>
                                    <td class="p-4 whitespace-nowrap text-center text-sm">
                                        <div class="flex justify-center space-x-3">
                                            <a href="edit_user.php?user_id=<?= $user['user_id'] ?>" 
                                            class="p-2 bg-blue-100 rounded-full text-blue-600 hover:bg-blue-200 transition">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="user_management.php?delete=1&user_id=<?= $user['user_id'] ?>" 
                                            onclick="return confirm('Are you sure you want to delete this user?');"
                                            class="p-2 bg-red-100 rounded-full text-red-600 hover:bg-red-200 transition">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-5 border-b">
                        <h3 class="text-lg font-semibold text-gray-800">Quick Actions</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <a href="add_user.php?role=doctor" class="quick-action flex flex-col items-center p-4 rounded-lg bg-gradient-to-br from-green-50 to-green-100 border border-green-200">
                                <div class="w-12 h-12 rounded-full bg-green-500 text-white flex items-center justify-center mb-3">
                                    <i class="fas fa-user-md"></i>
                                </div>
                                <span class="text-sm font-medium text-green-700 text-center">Add New Doctor</span>
                            </a>
                            <a href="add_user.php?role=patient" class="quick-action flex flex-col items-center p-4 rounded-lg bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200">
                                <div class="w-12 h-12 rounded-full bg-purple-500 text-white flex items-center justify-center mb-3">
                                    <i class="fas fa-hospital-user"></i>
                                </div>
                                <span class="text-sm font-medium text-purple-700 text-center">Add New Patient</span>
                            </a>
                            <a href="add_user.php?role=admin" class="quick-action flex flex-col items-center p-4 rounded-lg bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200">
                                <div class="w-12 h-12 rounded-full bg-blue-500 text-white flex items-center justify-center mb-3">
                                    <i class="fas fa-user-shield"></i>
                                </div>
                                <span class="text-sm font-medium text-blue-700 text-center">Add New Admin</span>
                            </a>
                            
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
</body>
</html>