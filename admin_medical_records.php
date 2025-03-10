<?php
session_start();
require_once 'connection.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php?error=Unauthorized+Access");
    exit();
}

// Handle form submission for updating medical records
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_record'])) {
    $patient_id = $_POST['patient_id'];
    $medical_history = $_POST['medical_history'];
    
    // Update the medical record
    $update_query = "UPDATE patients SET medical_history = ? WHERE patient_id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $medical_history, $patient_id);
    
    if ($stmt->execute()) {
        $success_message = "Medical record updated successfully!";
    } else {
        $error_message = "Error updating medical record: " . $conn->error;
    }
    
    $stmt->close();
}

// Get all patients with their medical records
$patients_query = "
    SELECT 
        p.patient_id, 
        p.first_name, 
        p.last_name, 
        p.dob, 
        p.gender, 
        p.phone, 
        p.address, 
        p.medical_history,
        (SELECT COUNT(*) FROM appointments WHERE patient_id = p.patient_id) as appointment_count
    FROM 
        patients p
    ORDER BY 
        p.last_name, p.first_name
";
$patients_result = $conn->query($patients_query);

// If viewing a specific patient's record
$selected_patient = null;
if (isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
    $patient_query = "
        SELECT 
            p.patient_id, 
            p.first_name, 
            p.last_name, 
            p.dob, 
            p.gender, 
            p.phone, 
            p.address, 
            p.medical_history,
            u.email
        FROM 
            patients p
        LEFT JOIN 
            users u ON p.user_id = u.user_id
        WHERE 
            p.patient_id = ?
    ";
    $stmt = $conn->prepare($patient_query);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $selected_patient = $result->fetch_assoc();
    
    // Get patient appointment history
    $appointments_query = "
        SELECT 
            a.appointment_id, 
            a.appointment_date, 
            a.time_slot, 
            a.notes, 
            a.status,
            d.first_name as doctor_first_name, 
            d.last_name as doctor_last_name,
            s.speciality_name
        FROM 
            appointments a
        JOIN 
            doctors d ON a.doctor_id = d.doctor_id
        JOIN 
            specialties s ON d.speciality_id = s.speciality_id
        WHERE 
            a.patient_id = ?
        ORDER BY 
            a.appointment_date DESC, a.time_slot DESC
    ";
    $stmt = $conn->prepare($appointments_query);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $appointments_result = $stmt->get_result();
}
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
                        <a href="admin_medical_records.php" class="flex items-center p-3 menu-item menu-item-active rounded-lg">
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
                        <h1 class="text-2xl font-bold text-gray-800">Medical Records</h1>
                        <div class="flex items-center text-sm text-gray-500 mt-1">
                            <a href="admin_dashboard.php" class="hover:text-blue-600">Home</a>
                            <span class="mx-2">/</span>
                            <span class="text-gray-700">Medical Records</span>
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

            <!-- Success/Error Messages -->
            <?php if(isset($success_message)): ?>
                <div class="mx-6 mt-4 p-4 bg-green-100 text-green-700 rounded-md">
                    <?= $success_message ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($error_message)): ?>
                <div class="mx-6 mt-4 p-4 bg-red-100 text-red-700 rounded-md">
                    <?= $error_message ?>
                </div>
            <?php endif; ?>

            <!-- Dashboard Content -->
            <div class="p-4 pt-20 md:pt-6 md:p-6 space-y-6">
                <!-- Patient Directory and Medical Record -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Patient List -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5 border-b border-gray-100">
                            <div class="flex justify-between items-center">
                                <h3 class="text-lg font-semibold text-gray-800">Patient Directory</h3>
                                <span class="text-sm text-blue-600">
                                    <?php 
                                    $patients_count = $patients_result ? mysqli_num_rows($patients_result) : 0;
                                    echo $patients_count . ' patients';
                                    // Reset the result pointer to use in the list below
                                    if($patients_result) mysqli_data_seek($patients_result, 0);
                                    ?>
                                </span>
                            </div>
                            <div class="mt-3">
                                <input type="text" id="searchPatient" class="w-full p-2 border rounded-lg focus:outline-none focus:ring focus:border-blue-300" placeholder="Search patients..." onkeyup="searchPatients()">
                            </div>
                        </div>
                        <div class="overflow-y-auto" style="max-height: calc(100vh - 250px);">
                            <ul id="patientList" class="divide-y divide-gray-100">
                                <?php if($patients_result && $patients_result->num_rows > 0): ?>
                                    <?php while($patient = $patients_result->fetch_assoc()): ?>
                                    <li class="patient-item">
                                        <a href="?patient_id=<?= $patient['patient_id'] ?>" class="block p-4 hover:bg-blue-50 transition <?= isset($_GET['patient_id']) && $_GET['patient_id'] == $patient['patient_id'] ? 'bg-blue-50' : '' ?>">
                                            <div class="flex items-center">
                                                <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mr-3">
                                                    <?= substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1) ?>
                                                </div>
                                                <div>
                                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($patient['first_name']) ?> <?= htmlspecialchars($patient['last_name']) ?></div>
                                                    <div class="text-sm text-gray-600 flex items-center">
                                                        <span class="mr-2"><?= date('M d, Y', strtotime($patient['dob'])) ?></span>
                                                        <span class="text-xs px-2 py-0.5 bg-gray-100 rounded-full"><?= ucfirst($patient['gender']) ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-2 text-xs text-gray-500 flex items-center">
                                                <i class="fas fa-calendar-check mr-1"></i>
                                                <?= $patient['appointment_count'] ?> appointment(s)
                                            </div>
                                        </a>
                                    </li>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <li class="text-center py-6 text-gray-500">No patients found</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- Patient Detail & Medical Record -->
                    <div class="md:col-span-2">
                        <?php if($selected_patient): ?>
                        <!-- Patient Info Card -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100 mb-6">
                            <div class="p-5">
                                <div class="flex justify-between items-start">
                                    <div class="flex items-center">
                                        <div class="w-14 h-14 rounded-full bg-blue-600 text-white flex items-center justify-center mr-4 text-xl">
                                            <?= substr($selected_patient['first_name'], 0, 1) . substr($selected_patient['last_name'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <h2 class="text-xl font-bold text-gray-800">
                                                <?= htmlspecialchars($selected_patient['first_name']) ?> <?= htmlspecialchars($selected_patient['last_name']) ?>
                                            </h2>
                                            <p class="text-sm text-gray-600">
                                                Patient ID: <?= $selected_patient['patient_id'] ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                                        Active
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                                    <div>
                                        <p class="text-xs text-gray-500 uppercase">Date of Birth</p>
                                        <p class="font-medium"><?= date('F d, Y', strtotime($selected_patient['dob'])) ?></p>
                                        <p class="text-sm text-gray-600"><?= date_diff(date_create($selected_patient['dob']), date_create('today'))->y ?> years old</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 uppercase">Gender</p>
                                        <p class="font-medium"><?= ucfirst($selected_patient['gender']) ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 uppercase">Phone</p>
                                        <p class="font-medium"><?= htmlspecialchars($selected_patient['phone']) ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 uppercase">Email</p>
                                        <p class="font-medium"><?= htmlspecialchars($selected_patient['email'] ?? 'No email provided') ?></p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <p class="text-xs text-gray-500 uppercase">Address</p>
                                        <p class="font-medium"><?= htmlspecialchars($selected_patient['address'] ?? 'No address provided') ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-5 py-3 border-t border-gray-100">
                                <div class="flex space-x-2">
                                    <a href="admin_appointments.php?patient_id=<?= $selected_patient['patient_id'] ?>" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center">
                                        <i class="fas fa-calendar-plus mr-1"></i> Schedule Appointment
                                    </a>
                                    <span class="text-gray-300">|</span>
                                    <a href="invoices.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center">
                                        <i class="fas fa-file-invoice mr-1"></i> Create Invoice
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Medical Record Form -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                            <div class="p-5 border-b border-gray-100">
                                <div class="flex justify-between items-center">
                                    <h3 class="text-lg font-semibold text-gray-800">Medical History</h3>
                                    <div class="flex space-x-2">
                                        <button type="button" id="printRecord" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded flex items-center">
                                            <i class="fas fa-print mr-1"></i> Print
                                        </button>
                                        <button type="button" id="exportRecord" class="text-sm bg-blue-50 hover:bg-blue-100 text-blue-700 py-1 px-3 rounded flex items-center">
                                            <i class="fas fa-file-export mr-1"></i> Export
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST">
                                <input type="hidden" name="patient_id" value="<?= $selected_patient['patient_id'] ?>">
                                
                                <div class="p-5">
                                    <textarea 
                                        name="medical_history" 
                                        rows="12" 
                                        class="w-full p-3 border rounded-lg focus:outline-none focus:ring focus:border-blue-300"
                                        placeholder="Enter patient medical history, conditions, allergies, and other important medical information..."
                                    ><?= htmlspecialchars($selected_patient['medical_history'] ?? '') ?></textarea>
                                </div>
                                
                                <div class="bg-gray-50 px-5 py-4 border-t border-gray-100 flex justify-end">
                                    <button type="submit" name="update_record" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring shadow-sm">
                                        <i class="fas fa-save mr-2"></i> Save Medical Record
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Patient Appointment History -->
                        <?php if(isset($appointments_result) && $appointments_result->num_rows > 0): ?>
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100 mt-6">
                            <div class="p-5 border-b border-gray-100">
                                <div class="flex justify-between items-center">
                                    <h3 class="text-lg font-semibold text-gray-800">Appointment History</h3>
                                    <a href="admin_all_appointments.php?patient_id=<?= $selected_patient['patient_id'] ?>" class="text-sm text-blue-600 hover:underline">View All</a>
                                </div>
                            </div>
                            <div class="p-5">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead>
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Doctor</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Specialty</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php while($appointment = $appointments_result->fetch_assoc()): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <div class="font-medium text-gray-900"><?= date('M d, Y', strtotime($appointment['appointment_date'])) ?></div>
                                                    <div class="text-sm text-gray-500"><?= $appointment['time_slot'] ?></div>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">Dr. <?= $appointment['doctor_first_name'] ?> <?= $appointment['doctor_last_name'] ?></div>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900"><?= $appointment['speciality_name'] ?></div>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <?php 
                                                    $status_class = '';
                                                    switch($appointment['status']) {
                                                        case 'completed':
                                                            $status_class = 'bg-green-100 text-green-800';
                                                            break;
                                                        case 'cancelled':
                                                            $status_class = 'bg-red-100 text-red-800';
                                                            break;
                                                        case 'scheduled':
                                                            $status_class = 'bg-blue-100 text-blue-800';
                                                            break;
                                                        default:
                                                            $status_class = 'bg-gray-100 text-gray-800';
                                                    }
                                                    ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $status_class ?>">
                                                        <?= ucfirst($appointment['status']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php else: ?>
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100 flex flex-col items-center justify-center p-12">
                            <div class="w-24 h-24 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mb-4">
                                <i class="fas fa-user-md text-4xl"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-800 mb-2">Select a Patient</h3>
                            <p class="text-gray-600 text-center mb-6">Please select a patient from the list to view and update their medical records.</p>
                            <a href="admin_dashboard.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring shadow-sm">
                                Return to Dashboard
                            </a>
                        </div>
                        <?php endif; ?>
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
        function searchPatients() {
            // Get the search input value
            const searchValue = document.getElementById('searchPatient').value.toLowerCase();
            
            // Get all patient items
            const patientItems = document.querySelectorAll('.patient-item');
            
            // Loop through patient items and filter based on search input
            patientItems.forEach(item => {
                const patientName = item.querySelector('.font-medium').textContent.toLowerCase();
                if (patientName.includes(searchValue)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Handle Print button
        document.addEventListener('DOMContentLoaded', function() {
            const printButton = document.getElementById('printRecord');
            if (printButton) {
                printButton.addEventListener('click', function() {
                    window.print();
                });
            }
            
            // Export functionality could be added here
            const exportButton = document.getElementById('exportRecord');
            if (exportButton) {
                exportButton.addEventListener('click', function() {
                    alert('Export functionality would be implemented here');
                });
            }
        });
    </script>
</body>
</html>