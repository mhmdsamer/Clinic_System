<?php
session_start();
require_once 'connection.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php?error=Unauthorized+Access");
    exit();
}

// Handle Add Specialty
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_specialty'])) {
    $specialty_name = trim(filter_input(INPUT_POST, 'specialty_name', FILTER_SANITIZE_STRING));
    
    if (!empty($specialty_name)) {
        $check_sql = "SELECT * FROM specialties WHERE speciality_name = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $specialty_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            $insert_sql = "INSERT INTO specialties (speciality_name) VALUES (?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("s", $specialty_name);
            
            if ($insert_stmt->execute()) {
                $success_message = "Specialty added successfully!";
            } else {
                $error_message = "Error adding specialty.";
            }
        } else {
            $error_message = "Specialty already exists.";
        }
    } else {
        $error_message = "Specialty name cannot be empty.";
    }
}

// Handle Delete Specialty
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_specialty'])) {
    $specialty_id = filter_input(INPUT_POST, 'specialty_id', FILTER_VALIDATE_INT);
    
    if ($specialty_id) {
        // Check if specialty is in use by any doctors
        $check_use_sql = "SELECT COUNT(*) as doctor_count FROM doctors WHERE speciality_id = ?";
        $check_use_stmt = $conn->prepare($check_use_sql);
        $check_use_stmt->bind_param("i", $specialty_id);
        $check_use_stmt->execute();
        $check_use_result = $check_use_stmt->get_result()->fetch_assoc();
        
        if ($check_use_result['doctor_count'] > 0) {
            $error_message = "Cannot delete specialty. It is assigned to {$check_use_result['doctor_count']} doctor(s).";
        } else {
            $delete_sql = "DELETE FROM specialties WHERE speciality_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $specialty_id);
            
            if ($delete_stmt->execute()) {
                $success_message = "Specialty deleted successfully!";
            } else {
                $error_message = "Error deleting specialty.";
            }
        }
    }
}

// Fetch Specialties
$specialties_query = "
    SELECT 
        s.speciality_id, 
        s.speciality_name, 
        COUNT(d.doctor_id) as doctor_count
    FROM specialties s
    LEFT JOIN doctors d ON s.speciality_id = d.speciality_id
    GROUP BY s.speciality_id, s.speciality_name
    ORDER BY s.speciality_name
";
$specialties_result = $conn->query($specialties_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Specialties - Clinic Management System</title>
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
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50" x-data="{ 
    mobileMenuOpen: false, 
    showAddModal: false, 
    showDeleteModal: false, 
    selectedSpecialtyId: null,
    selectedSpecialtyName: '' 
}">
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
                        <a href="admin_appointments.php" class="flex items-center p-3 menu-item rounded-lg">
                            <i class="fas fa-calendar-alt mr-3 w-5 text-center"></i>Appointments
                        </a>
                    </li>
                    <p class="text-xs text-gray-400 px-3 mt-6 mb-2 uppercase">Medical</p>
                    <li>
                        <a href="specialties.php" class="flex items-center p-3 menu-item menu-item-active rounded-lg">
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
                        <h1 class="text-2xl font-bold text-gray-800">Medical Specialties</h1>
                        <div class="flex items-center text-sm text-gray-500 mt-1">
                            <a href="admin_dashboard.php" class="hover:text-blue-600">Home</a>
                            <span class="mx-2">/</span>
                            <span class="text-gray-700">Medical Specialties</span>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <button 
                            @click="showAddModal = true" 
                            class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center"
                        >
                            <i class="fas fa-plus mr-2"></i>Add Specialty
                        </button>
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
                <?php if (isset($success_message)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-sm" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <p><?= htmlspecialchars($success_message) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-sm" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <p><?= htmlspecialchars($error_message) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Specialties Card -->
                <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                    <div class="flex justify-between items-center p-5 border-b">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 mr-3">
                                <i class="fas fa-stethoscope"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800">Medical Specialties Management</h3>
                        </div>
                        <button 
                            @click="showAddModal = true" 
                            class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center text-sm md:hidden"
                        >
                            <i class="fas fa-plus mr-2"></i>Add
                        </button>
                    </div>
                    <div class="p-5 overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600 border-b">ID</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600 border-b">Specialty Name</th>
                                    <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600 border-b">Doctors Count</th>
                                    <th class="px-4 py-3 text-center text-sm font-semibold text-gray-600 border-b">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($specialties_result->num_rows > 0): ?>
                                    <?php while($specialty = $specialties_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-4 py-3 border-b text-sm text-gray-600">
                                                <?= $specialty['speciality_id'] ?>
                                            </td>
                                            <td class="px-4 py-3 border-b">
                                                <div class="flex items-center">
                                                    <span class="font-medium text-gray-800"><?= htmlspecialchars($specialty['speciality_name']) ?></span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 border-b text-center">
                                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $specialty['doctor_count'] > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                                    <?= $specialty['doctor_count'] ?> doctor<?= $specialty['doctor_count'] != 1 ? 's' : '' ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 border-b text-center">
                                                <button 
                                                    @click="selectedSpecialtyId = <?= $specialty['speciality_id'] ?>; 
                                                           selectedSpecialtyName = '<?= htmlspecialchars($specialty['speciality_name']) ?>'; 
                                                           showDeleteModal = true"
                                                    class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50"
                                                    title="Delete Specialty"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-gray-500 border-b">
                                            <div class="flex flex-col items-center">
                                                <i class="fas fa-stethoscope text-4xl text-gray-300 mb-3"></i>
                                                <p class="text-lg">No specialties found</p>
                                                <p class="text-sm text-gray-400 mt-1">Add a new specialty to get started</p>
                                                <button 
                                                    @click="showAddModal = true" 
                                                    class="mt-4 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm"
                                                >
                                                    <i class="fas fa-plus mr-2"></i>Add New Specialty
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Footer -->
                <div class="text-center text-gray-500 text-xs mt-8 pb-6">
                    <p>© 2025 Clinic_System. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Specialty Modal -->
    <div 
        x-show="showAddModal" 
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        x-cloak
    >
        <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4 transform transition-all">
            <div class="flex justify-between items-center mb-5">
                <h2 class="text-xl font-bold text-gray-800">Add New Medical Specialty</h2>
                <button @click="showAddModal = false" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form action="specialties.php" method="POST">
                <div class="mb-5">
                    <label for="specialty_name" class="block text-sm font-medium text-gray-700 mb-2">Specialty Name</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-stethoscope text-gray-400"></i>
                        </div>
                        <input 
                            type="text" 
                            name="specialty_name" 
                            id="specialty_name" 
                            required 
                            class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter specialty name"
                        >
                    </div>
                </div>
                <div class="flex justify-end space-x-3">
                    <button 
                        type="button" 
                        @click="showAddModal = false" 
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 focus:outline-none transition"
                    >
                        Cancel
                    </button>
                    <button 
                        type="submit" 
                        name="add_specialty" 
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none transition"
                    >
                        <i class="fas fa-plus mr-2"></i>Add Specialty
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Specialty Modal -->
    <div 
        x-show="showDeleteModal" 
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        x-cloak
    >
        <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4 transform transition-all">
            <div class="flex justify-between items-center mb-5">
                <h2 class="text-xl font-bold text-red-600">Confirm Delete</h2>
                <button @click="showDeleteModal = false" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="bg-red-50 text-red-600 p-4 rounded-lg mb-5">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle mr-3 text-xl"></i>
                    <div>
                        <p class="font-medium">Are you sure you want to delete this specialty?</p>
                        <p class="text-sm text-red-500 mt-1">This action cannot be undone.</p>
                    </div>
                </div>
            </div>
            <p class="mb-5 text-gray-600">
                You are about to delete the specialty 
                <span x-text="selectedSpecialtyName" class="font-semibold text-gray-800"></span>.
                Doctors assigned to this specialty will need to be reassigned.
            </p>
            <form action="specialties.php" method="POST">
                <input type="hidden" name="specialty_id" :value="selectedSpecialtyId">
                <div class="flex justify-end space-x-3">
                    <button 
                        type="button" 
                        @click="showDeleteModal = false" 
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 focus:outline-none transition"
                    >
                        Cancel
                    </button>
                    <button 
                        type="submit" 
                        name="delete_specialty" 
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 focus:outline-none transition"
                    >
                        <i class="fas fa-trash mr-2"></i>Delete Specialty
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>