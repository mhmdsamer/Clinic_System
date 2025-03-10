<?php
session_start();
require_once 'connection.php';

// Ensure only logged-in patients can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Fetch patient details
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

// Check if patient exists
if (!$patient) {
    session_destroy();
    header("Location: login_process.php?error=Patient+Not+Found");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $medical_history = trim($_POST['medical_history']);
    
    // Validate inputs
    $is_valid = true;
    
    if (empty($first_name) || empty($last_name) || empty($phone) || empty($dob) || empty($gender) || empty($email)) {
        $error_message = "Please fill all required fields";
        $is_valid = false;
    } 
    
    // Check if phone is already used by another patient
    if ($phone !== $patient['phone']) {
        $check_phone_query = "SELECT patient_id FROM patients WHERE phone = ? AND patient_id != ?";
        $check_phone_stmt = $conn->prepare($check_phone_query);
        $check_phone_stmt->bind_param("si", $phone, $patient['patient_id']);
        $check_phone_stmt->execute();
        $check_phone_result = $check_phone_stmt->get_result();
        
        if ($check_phone_result->num_rows > 0) {
            $error_message = "Phone number already in use";
            $is_valid = false;
        }
    }
    
    // Check if email is already used by another user
    if ($email !== $patient['email']) {
        $check_email_query = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
        $check_email_stmt = $conn->prepare($check_email_query);
        $check_email_stmt->bind_param("si", $email, $user_id);
        $check_email_stmt->execute();
        $check_email_result = $check_email_stmt->get_result();
        
        if ($check_email_result->num_rows > 0) {
            $error_message = "Email already in use";
            $is_valid = false;
        }
    }
    
    // Check password if it's not empty
    if (!empty($password)) {
        if (strlen($password) < 6) {
            $error_message = "Password must be at least 6 characters";
            $is_valid = false;
        } elseif ($password !== $confirm_password) {
            $error_message = "Passwords do not match";
            $is_valid = false;
        }
    }
    
    // If form is valid, update the user
    if ($is_valid) {
        // Start transaction
        $conn->begin_transaction();
        try {
            // Update patient table
            $update_patient_query = "
                UPDATE patients 
                SET first_name = ?, last_name = ?, phone = ?, address = ?, dob = ?, gender = ?, medical_history = ?
                WHERE patient_id = ?
            ";
            $update_patient_stmt = $conn->prepare($update_patient_query);
            $update_patient_stmt->bind_param("sssssssi", 
                $first_name, $last_name, $phone, $address, $dob, $gender, $medical_history, $patient['patient_id']
            );
            $update_patient_stmt->execute();
            
            // Update users table
            if (!empty($password)) {
                // Update email and password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $update_user_query = "UPDATE users SET email = ?, password_hash = ? WHERE user_id = ?";
                $update_user_stmt = $conn->prepare($update_user_query);
                $update_user_stmt->bind_param("ssi", $email, $password_hash, $user_id);
            } else {
                // Update just email
                $update_user_query = "UPDATE users SET email = ? WHERE user_id = ?";
                $update_user_stmt = $conn->prepare($update_user_query);
                $update_user_stmt->bind_param("si", $email, $user_id);
            }
            $update_user_stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Set success message
            $success_message = "Profile updated successfully!";
            
            // Refresh patient data
            $patient_stmt->execute();
            $patient_result = $patient_stmt->get_result();
            $patient = $patient_result->fetch_assoc();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error updating profile: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - Clinic Management System</title>
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
        
        .form-input {
            @apply mt-1 block w-full rounded-lg border-gray-300 bg-white bg-opacity-90 shadow-sm focus:border-purple-500 focus:ring-purple-500 focus:ring-2;
        }
        
        .section-header {
            @apply bg-purple-50 p-4 flex justify-between items-center cursor-pointer rounded-t-lg border-b border-purple-100;
        }
        
        .input-group {
            @apply relative mt-1;
        }
        
        .input-icon {
            @apply absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer text-gray-400;
        }
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
                        <i class="fas fa-user-cog mr-3"></i>Profile Settings
                    </span>
                </h1>
                <a href="homepage.php" class="bg-white action-btn text-purple-600 px-6 py-3 rounded-full shadow-lg hover:bg-purple-50 transition flex items-center">
                    <i class="fas fa-home mr-2"></i>Back to Dashboard
                </a>
            </div>
            
            <?php if (!empty($success_message)): ?>
            <div class="glass-card p-4 mb-6 border-l-4 border-green-500 bg-green-50 bg-opacity-80">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-green-700 font-medium"><?= $success_message ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="glass-card p-4 mb-6 border-l-4 border-red-500 bg-red-50 bg-opacity-80">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-red-700 font-medium"><?= $error_message ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Patient Information Card -->
            <div class="glass-card p-8 mb-8 relative overflow-hidden">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-user-circle text-purple-600 mr-3"></i>Current Information
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
            
            <form action="profile_settings.php" method="POST" x-data="{ showPassword: false, showConfirmPassword: false }">
                <!-- Personal Information Section -->
                <div class="glass-card mb-8" x-data="{ open: true }">
                    <div class="section-header" @click="open = !open">
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-user text-blue-500 mr-3"></i>Personal Information
                        </h2>
                        <button type="button" class="text-purple-500 w-8 h-8 flex items-center justify-center rounded-full bg-purple-100 transition-all duration-300" 
                                :class="{'transform rotate-180': open}">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    
                    <div class="p-6" x-show="open">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- First Name -->
                            <div>
                                <label for="first_name" class="block text-sm font-medium text-gray-700">First Name*</label>
                                <div class="input-group">
                                    <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($patient['first_name']) ?>" required
                                        class="form-input pl-3 pr-10">
                                    <div class="input-icon">
                                        <i class="fas fa-user text-purple-400"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Last Name -->
                            <div>
                                <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name*</label>
                                <div class="input-group">
                                    <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($patient['last_name']) ?>" required
                                        class="form-input pl-3 pr-10">
                                    <div class="input-icon">
                                        <i class="fas fa-user text-purple-400"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Date of Birth -->
                            <div>
                                <label for="dob" class="block text-sm font-medium text-gray-700">Date of Birth*</label>
                                <div class="input-group">
                                    <input type="date" name="dob" id="dob" value="<?= htmlspecialchars($patient['dob']) ?>" required
                                        class="form-input pl-3 pr-10">
                                    <div class="input-icon">
                                        <i class="fas fa-calendar-alt text-purple-400"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Gender -->
                            <div>
                                <label for="gender" class="block text-sm font-medium text-gray-700">Gender*</label>
                                <select name="gender" id="gender" required
                                        class="form-input">
                                    <option value="male" <?= $patient['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= $patient['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                                    <option value="other" <?= $patient['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            
                            <!-- Phone -->
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number*</label>
                                <div class="input-group">
                                    <input type="tel" name="phone" id="phone" value="<?= htmlspecialchars($patient['phone']) ?>" required
                                        class="form-input pl-3 pr-10">
                                    <div class="input-icon">
                                        <i class="fas fa-phone text-purple-400"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Address -->
                            <div>
                                <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                                <div class="input-group">
                                    <input type="text" name="address" id="address" value="<?= htmlspecialchars($patient['address'] ?? '') ?>"
                                        class="form-input pl-3 pr-10">
                                    <div class="input-icon">
                                        <i class="fas fa-home text-purple-400"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Account Information Section -->
                <div class="glass-card mb-8" x-data="{ open: true }">
                    <div class="section-header" @click="open = !open">
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-lock text-green-500 mr-3"></i>Account Information
                        </h2>
                        <button type="button" class="text-purple-500 w-8 h-8 flex items-center justify-center rounded-full bg-purple-100 transition-all duration-300" 
                                :class="{'transform rotate-180': open}">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    
                    <div class="p-6" x-show="open">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Username (Read Only) -->
                            <div>
                                <label for="username" class="block text-sm font-medium text-gray-700">Username (cannot be changed)</label>
                                <div class="input-group">
                                    <input type="text" id="username" value="<?= htmlspecialchars($patient['username']) ?>" readonly
                                        class="form-input pl-3 pr-10 bg-gray-100">
                                    <div class="input-icon">
                                        <i class="fas fa-user-tag text-gray-400"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Email -->
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email*</label>
                                <div class="input-group">
                                    <input type="email" name="email" id="email" value="<?= htmlspecialchars($patient['email']) ?>" required
                                        class="form-input pl-3 pr-10">
                                    <div class="input-icon">
                                        <i class="fas fa-envelope text-purple-400"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Password -->
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700">New Password (leave blank to keep current)</label>
                                <div class="input-group">
                                    <input :type="showPassword ? 'text' : 'password'" name="password" id="password"
                                        class="form-input pl-3 pr-10">
                                    <div class="input-icon" @click="showPassword = !showPassword">
                                        <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
                            </div>
                            
                            <!-- Confirm Password -->
                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                <div class="input-group">
                                    <input :type="showConfirmPassword ? 'text' : 'password'" name="confirm_password" id="confirm_password"
                                        class="form-input pl-3 pr-10">
                                    <div class="input-icon" @click="showConfirmPassword = !showConfirmPassword">
                                        <i :class="showConfirmPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Medical Information Section -->
                <div class="glass-card mb-8" x-data="{ open: true }">
                    <div class="section-header" @click="open = !open">
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-notes-medical text-red-500 mr-3"></i>Medical Information
                        </h2>
                        <button type="button" class="text-purple-500 w-8 h-8 flex items-center justify-center rounded-full bg-purple-100 transition-all duration-300" 
                                :class="{'transform rotate-180': open}">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    
                    <div class="p-6" x-show="open">
                        <!-- Medical History -->
                        <div>
                            <label for="medical_history" class="block text-sm font-medium text-gray-700">Medical History</label>
                            <textarea name="medical_history" id="medical_history" rows="5"
                                class="form-input"><?= htmlspecialchars($patient['medical_history'] ?? '') ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">Please include any allergies, chronic conditions, or previous surgeries</p>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-center mt-8 mb-12">
                    <button type="submit" class="px-8 py-4 bg-gradient-to-r from-purple-600 to-blue-500 text-white font-bold rounded-full shadow-lg action-btn hover:from-purple-700 hover:to-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                </div>
            </form>
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