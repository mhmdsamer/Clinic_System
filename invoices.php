<?php
session_start();
require_once 'connection.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php?error=Unauthorized+Access");
    exit();
}

// Handle invoice generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_invoice'])) {
    $appointment_id = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $currency = filter_input(INPUT_POST, 'currency', FILTER_SANITIZE_STRING);

    if ($appointment_id && $amount && $currency) {
        // Get patient ID from the appointment
        $patient_query = "SELECT a.patient_id, a.appointment_date, p.first_name as patient_first_name, p.last_name as patient_last_name, 
                          d.first_name as doctor_first_name, d.last_name as doctor_last_name 
                          FROM appointments a 
                          JOIN patients p ON a.patient_id = p.patient_id 
                          JOIN doctors d ON a.doctor_id = d.doctor_id 
                          WHERE a.appointment_id = ?";
        $patient_stmt = $conn->prepare($patient_query);
        $patient_stmt->bind_param("i", $appointment_id);
        $patient_stmt->execute();
        $patient_result = $patient_stmt->get_result();

        if ($patient_result->num_rows > 0) {
            $appointment_data = $patient_result->fetch_assoc();

            // Insert invoice
            $insert_invoice_query = "INSERT INTO invoices (patient_id, appointment_id, amount, currency) VALUES (?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_invoice_query);
            $insert_stmt->bind_param("iids", $appointment_data['patient_id'], $appointment_id, $amount, $currency);

            if ($insert_stmt->execute()) {
                $invoice_id = $conn->insert_id;
                $success_message = "Invoice #$invoice_id generated successfully!";
            } else {
                $error_message = "Failed to generate invoice.";
            }
        } else {
            $error_message = "Invalid appointment selected.";
        }
    } else {
        $error_message = "Please fill in all invoice details.";
    }
}

// Handle updating invoice status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $invoice_id = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if ($invoice_id && in_array($status, ['paid', 'pending', 'cancelled'])) {
        $update_query = "UPDATE invoices SET payment_status = ? WHERE invoice_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("si", $status, $invoice_id);
        
        if ($update_stmt->execute()) {
            $success_message = "Invoice status updated successfully!";
        } else {
            $error_message = "Failed to update invoice status.";
        }
    }
}

// Fetch invoices with patient and appointment details
$payment_status = isset($_GET['status']) ? $_GET['status'] : null;
$status_filter = $payment_status ? "WHERE i.payment_status = ?" : "";

$invoices_query = "
    SELECT 
        i.invoice_id, 
        p.first_name, 
        p.last_name, 
        a.appointment_date, 
        i.amount, 
        i.payment_status, 
        i.currency,
        d.first_name as doctor_first_name,
        d.last_name as doctor_last_name,
        i.created_at
    FROM invoices i
    JOIN patients p ON i.patient_id = p.patient_id
    JOIN appointments a ON i.appointment_id = a.appointment_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    $status_filter
    ORDER BY i.created_at DESC
";

$invoices_stmt = $conn->prepare($invoices_query);
if ($payment_status) {
    $invoices_stmt->bind_param("s", $payment_status);
}
$invoices_stmt->execute();
$invoices_result = $invoices_stmt->get_result();

// Fetch available appointments without invoices
$available_appointments_query = "
    SELECT 
        a.appointment_id, 
        p.first_name as patient_first_name, 
        p.last_name as patient_last_name,
        d.first_name as doctor_first_name, 
        d.last_name as doctor_last_name,
        a.appointment_date
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    LEFT JOIN invoices i ON a.appointment_id = i.appointment_id
    WHERE i.appointment_id IS NULL
";
$available_appointments_result = $conn->query($available_appointments_query);

// Get invoice statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(amount) as total_amount,
        (SELECT COUNT(*) FROM invoices WHERE payment_status = 'paid') as paid_invoices,
        (SELECT COUNT(*) FROM invoices WHERE payment_status = 'pending') as pending_invoices,
        (SELECT COUNT(*) FROM invoices WHERE payment_status = 'cancelled') as cancelled_invoices,
        (SELECT SUM(amount) FROM invoices WHERE payment_status = 'paid') as paid_amount
    FROM invoices
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices Management - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    showGenerateInvoiceModal: false,
    showStatusUpdateModal: false,
    selectedInvoiceId: null,
    selectedInvoiceStatus: '',
    selectedAppointmentId: null,
    selectedPatientName: '',
    selectedDoctorName: '',
    selectedAppointmentDate: ''
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
                        <a href="invoices.php" class="flex items-center p-3 menu-item menu-item-active rounded-lg">
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
                        <h1 class="text-2xl font-bold text-gray-800">Invoices Management</h1>
                        <div class="flex items-center text-sm text-gray-500 mt-1">
                            <a href="admin_dashboard.php" class="hover:text-blue-600">Home</a>
                            <span class="mx-2">/</span>
                            <span class="text-gray-700">Invoices</span>
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
                <!-- Alerts for Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-sm mb-6" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-3 text-green-500"></i>
                            <p><?= htmlspecialchars($success_message) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-sm mb-6" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-3 text-red-500"></i>
                            <p><?= htmlspecialchars($error_message) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Total Invoices Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Total Invoices</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1"><?= $stats['total_invoices'] ?></p>
                                </div>
                                <div class="w-14 h-14 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600">
                                    <i class="fas fa-file-invoice text-2xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-blue-600 h-2 rounded-full" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Revenue Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Total Revenue</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1">$<?= number_format($stats['total_amount'] ?? 0, 2) ?></p>
                                </div>
                                <div class="w-14 h-14 bg-green-100 rounded-lg flex items-center justify-center text-green-600">
                                    <i class="fas fa-dollar-sign text-2xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 rounded-full bg-green-500 mr-2"></div>
                                    <span class="text-sm text-gray-600">Collected: $<?= number_format($stats['paid_amount'] ?? 0, 2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Invoices Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Pending Invoices</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1"><?= $stats['pending_invoices'] ?></p>
                                </div>
                                <div class="w-14 h-14 bg-yellow-100 rounded-lg flex items-center justify-center text-yellow-600">
                                    <i class="fas fa-clock text-2xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 rounded-full bg-yellow-500 mr-2"></div>
                                    <span class="text-sm text-gray-600">
                                        <?= $stats['pending_invoices'] ?> of <?= $stats['total_invoices'] ?> (<?= $stats['total_invoices'] > 0 ? round(($stats['pending_invoices'] / $stats['total_invoices']) * 100) : 0 ?>%)
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paid Invoices Card -->
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden stat-card border border-gray-100">
                        <div class="p-5">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Paid Invoices</p>
                                    <p class="text-3xl font-bold text-gray-800 mt-1"><?= $stats['paid_invoices'] ?></p>
                                </div>
                                <div class="w-14 h-14 bg-purple-100 rounded-lg flex items-center justify-center text-purple-600">
                                    <i class="fas fa-check-circle text-2xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 rounded-full bg-purple-500 mr-2"></div>
                                    <span class="text-sm text-gray-600">
                                        <?= $stats['paid_invoices'] ?> of <?= $stats['total_invoices'] ?> (<?= $stats['total_invoices'] > 0 ? round(($stats['paid_invoices'] / $stats['total_invoices']) * 100) : 0 ?>%)
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions and Status Filters -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                    <!-- Invoice Status Filters -->
                    <div class="md:col-span-3 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="flex justify-between items-center p-5 border-b">
                            <h3 class="text-lg font-semibold text-gray-800">Invoice Status</h3>
                            <div class="text-sm text-gray-500">Filter by status</div>
                        </div>
                        <div class="p-5">
                            <div class="flex flex-wrap gap-3">
                                <a href="invoices.php" class="px-4 py-2 rounded-lg text-center text-sm font-medium <?= !$payment_status ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?> transition">
                                    All Invoices
                                </a>
                                <a href="?status=pending" class="px-4 py-2 rounded-lg text-center text-sm font-medium <?= $payment_status === 'pending' ? 'bg-yellow-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?> transition">
                                    <i class="fas fa-clock mr-1"></i> Pending
                                </a>
                                <a href="?status=paid" class="px-4 py-2 rounded-lg text-center text-sm font-medium <?= $payment_status === 'paid' ? 'bg-green-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?> transition">
                                    <i class="fas fa-check-circle mr-1"></i> Paid
                                </a>
                                <a href="?status=cancelled" class="px-4 py-2 rounded-lg text-center text-sm font-medium <?= $payment_status === 'cancelled' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?> transition">
                                    <i class="fas fa-times-circle mr-1"></i> Cancelled
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="md:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-5 border-b">
                            <h3 class="text-lg font-semibold text-gray-800">Quick Actions</h3>
                        </div>
                        <div class="p-5">
                            <div class="grid grid-cols-2 gap-3">
                                <button @click="showGenerateInvoiceModal = true" class="quick-action flex flex-col items-center p-4 rounded-lg bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200">
                                    <div class="w-10 h-10 rounded-full bg-blue-500 text-white flex items-center justify-center mb-2">
                                        <i class="fas fa-plus"></i>
                                    </div>
                                    <span class="text-xs font-medium text-blue-700 text-center">New Invoice</span>
                                </button>
                                <a href="generate_report.php" class="quick-action flex flex-col items-center p-4 rounded-lg bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200">
                                    <div class="w-10 h-10 rounded-full bg-purple-500 text-white flex items-center justify-center mb-2">
                                        <i class="fas fa-chart-pie"></i>
                                    </div>
                                    <span class="text-xs font-medium text-purple-700 text-center">Reports</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoices Table -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="flex justify-between items-center p-5 border-b">
                        <h3 class="text-lg font-semibold text-gray-800">Invoice List</h3>
                        <div class="flex items-center space-x-2">
                            <div class="relative">
                                <input type="text" placeholder="Search invoices..." class="px-4 py-2 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <i class="fas fa-search absolute right-3 top-2.5 text-gray-400"></i>
                            </div>
                            <button @click="showGenerateInvoiceModal = true" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 transition flex items-center">
                                <i class="fas fa-plus mr-2"></i> New Invoice
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full whitespace-nowrap">
                            <thead>
                                <tr class="bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    <th class="px-6 py-4">Invoice ID</th>
                                    <th class="px-6 py-4">Patient</th>
                                    <th class="px-6 py-4">Doctor</th>
                                    <th class="px-6 py-4">Appointment Date</th>
                                    <th class="px-6 py-4 text-center">Amount</th>
                                    <th class="px-6 py-4 text-center">Status</th>
                                    <th class="px-6 py-4 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if ($invoices_result->num_rows > 0): ?>
                                    <?php while($invoice = $invoices_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-medium text-gray-900">#<?= $invoice['invoice_id'] ?></div>
                                                <div class="text-xs text-gray-500"><?= date('M d, Y', strtotime($invoice['created_at'])) ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                        <?= substr($invoice['first_name'], 0, 1) . substr($invoice['last_name'], 0, 1) ?>
                                                    </div>
                                                    <div class="ml-3">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?= htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-900">Dr. <?= htmlspecialchars($invoice['doctor_first_name'] . ' ' . $invoice['doctor_last_name']) ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-900"><?= date('M d, Y', strtotime($invoice['appointment_date'])) ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?= $invoice['currency'] == 'USD' ? '$' : '€' ?><?= number_format($invoice['amount'], 2) ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <?php 
                                                    $status_class = '';
                                                    $status_icon = '';
                                                    
                                                    switch($invoice['payment_status']) {
                                                        case 'paid':
                                                            $status_class = 'bg-green-100 text-green-800';
                                                            $status_icon = 'fas fa-check-circle text-green-500';
                                                            break;
                                                        case 'pending':
                                                            $status_class = 'bg-yellow-100 text-yellow-800';
                                                            $status_icon = 'fas fa-clock text-yellow-500';
                                                            break;
                                                        case 'cancelled':
                                                            $status_class = 'bg-red-100 text-red-800';
                                                            $status_icon = 'fas fa-times-circle text-red-500';
                                                            break;
                                                    }
                                                ?>
                                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $status_class ?>">
                                                    <i class="<?= $status_icon ?> mr-1"></i> <?= ucfirst($invoice['payment_status']) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-center">
                                                <div class="flex justify-center space-x-2">
                                                    <button @click="
                                                        selectedInvoiceId = <?= $invoice['invoice_id'] ?>;
                                                        selectedInvoiceStatus = '<?= $invoice['payment_status'] ?>';
                                                        showStatusUpdateModal = true;
                                                    " class="text-blue-600 hover:text-blue-900">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="print_invoice.php?id=<?= $invoice['invoice_id'] ?>" target="_blank" class="text-gray-600 hover:text-gray-900">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                    <a href="email_invoice.php?id=<?= $invoice['invoice_id'] ?>" class="text-green-600 hover:text-green-900">
                                                        <i class="fas fa-envelope"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                                            <i class="fas fa-file-invoice text-gray-300 text-4xl mb-3"></i>
                                            <p>No invoices found<?= $payment_status ? ' with ' . $payment_status . ' status' : '' ?>.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-6 py-4 border-t bg-gray-50 flex items-center justify-between">
                        <p class="text-sm text-gray-500">Showing <?= $invoices_result->num_rows ?> invoices</p>
                        <?php if ($invoices_result->num_rows > 0): ?>
                        <div class="flex space-x-1">
                            <button class="px-3 py-1 rounded text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50" disabled>
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="px-3 py-1 rounded text-sm bg-blue-600 border border-blue-600 text-white">1</button>
                            <button class="px-3 py-1 rounded text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50" disabled>
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Generate Invoice Modal -->
    <div class="fixed inset-0 z-50 overflow-y-auto" x-show="showGenerateInvoiceModal" x-cloak>
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            
            <!-- Modal Dialog -->
            <div class="inline-block w-full max-w-md p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-lg"
                 @click.away="showGenerateInvoiceModal = false">
                <div class="flex justify-between items-center pb-3 border-b">
                    <h3 class="text-lg font-medium text-gray-900">Generate New Invoice</h3>
                    <button @click="showGenerateInvoiceModal = false" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form method="POST" action="invoices.php" class="mt-4">
                    <div class="space-y-4">
                        <div>
                            <label for="appointment_id" class="block text-sm font-medium text-gray-700">Select Appointment</label>
                            <select 
                                name="appointment_id" 
                                id="appointment_id" 
                                required 
                                class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md"
                                @change="
                                    const selectedOption = $event.target.selectedOptions[0];
                                    selectedPatientName = selectedOption.getAttribute('data-patient');
                                    selectedDoctorName = selectedOption.getAttribute('data-doctor');
                                    selectedAppointmentDate = selectedOption.getAttribute('data-date');
                                "
                            >
                                <option value="">Select an appointment</option>
                                <?php if ($available_appointments_result->num_rows > 0): ?>
                                    <?php while($appointment = $available_appointments_result->fetch_assoc()): ?>
                                        <option 
                                            value="<?= $appointment['appointment_id'] ?>"
                                            data-patient="<?= htmlspecialchars($appointment['patient_first_name'] . ' ' . $appointment['patient_last_name']) ?>"
                                            data-doctor="Dr. <?= htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']) ?>"
                                            data-date="<?= date('M d, Y', strtotime($appointment['appointment_date'])) ?>"
                                        >
                                            Appointment #<?= $appointment['appointment_id'] ?> - 
                                            <?= htmlspecialchars($appointment['patient_first_name'] . ' ' . $appointment['patient_last_name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <option value="" disabled>No available appointments</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div x-show="selectedPatientName" class="bg-blue-50 p-3 rounded-lg">
                            <div class="text-sm">
                                <p><span class="font-medium">Patient:</span> <span x-text="selectedPatientName"></span></p>
                                <p><span class="font-medium">Doctor:</span> <span x-text="selectedDoctorName"></span></p>
                                <p><span class="font-medium">Date:</span> <span x-text="selectedAppointmentDate"></span></p>
                            </div>
                        </div>
                        
                        <div>
                            <label for="amount" class="block text-sm font-medium text-gray-700">Amount</label>
                            <div class="mt-1 relative rounded-md shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500 sm:text-sm">$</span>
                                </div>
                                <input type="number" name="amount" id="amount" required step="0.01" min="0"
                                       class="block w-full pl-7 pr-12 py-2 sm:text-sm border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="0.00">
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500 sm:text-sm" id="price-currency">USD</span>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <label for="currency" class="block text-sm font-medium text-gray-700">Currency</label>
                            <select name="currency" id="currency" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" @click="showGenerateInvoiceModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                            Cancel
                        </button>
                        <button type="submit" name="generate_invoice" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Generate Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="fixed inset-0 z-50 overflow-y-auto" x-show="showStatusUpdateModal" x-cloak>
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            
            <!-- Modal Dialog -->
            <div class="inline-block w-full max-w-md p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-lg"
                 @click.away="showStatusUpdateModal = false">
                <div class="flex justify-between items-center pb-3 border-b">
                    <h3 class="text-lg font-medium text-gray-900">Update Invoice Status</h3>
                    <button @click="showStatusUpdateModal = false" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form method="POST" action="invoices.php" class="mt-4">
                    <input type="hidden" name="invoice_id" :value="selectedInvoiceId">
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                        <select name="status" id="status" required class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md"
                                x-model="selectedInvoiceStatus">
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" @click="showStatusUpdateModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                            Cancel
                        </button>
                        <button type="submit" name="update_status" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Chart Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // This would be where you'd initialize charts if needed
        });
    </script>
</body>
</html>