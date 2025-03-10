<?php
session_start();
require_once 'connection.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php?error=Unauthorized+Access");
    exit();
}

// Initialize variables
$user_id = 0;
$username = '';
$email = '';
$role = '';
$first_name = '';
$last_name = '';
$phone = '';
$speciality_id = 0;
$dob = '';
$gender = '';
$address = '';
$medical_history = '';
$specialties = [];

// Fetch specialties for doctor selection
$specialties_query = "SELECT speciality_id, speciality_name FROM specialties";
$specialties_result = $conn->query($specialties_query);
while ($row = $specialties_result->fetch_assoc()) {
    $specialties[] = $row;
}

// Check if user_id is provided
if (isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    
    // Fetch user data
    $user_query = "
        SELECT 
            u.user_id, 
            u.username, 
            u.email, 
            u.role,
            COALESCE(d.first_name, p.first_name) as first_name,
            COALESCE(d.last_name, p.last_name) as last_name,
            COALESCE(d.phone, p.phone) as phone,
            d.speciality_id,
            p.dob,
            p.gender,
            p.address,
            p.medical_history
        FROM users u
        LEFT JOIN doctors d ON u.user_id = d.user_id
        LEFT JOIN patients p ON u.user_id = p.user_id
        WHERE u.user_id = ?
    ";
    
    $stmt = $conn->prepare($user_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $username = $user['username'];
        $email = $user['email'];
        $role = $user['role'];
        $first_name = $user['first_name'];
        $last_name = $user['last_name'];
        $phone = $user['phone'];
        $speciality_id = $user['speciality_id'] ?? 0;
        $dob = $user['dob'] ?? '';
        $gender = $user['gender'] ?? '';
        $address = $user['address'] ?? '';
        $medical_history = $user['medical_history'] ?? '';
    } else {
        header("Location: user_management.php?error=User+not+found");
        exit();
    }
} else {
    header("Location: user_management.php?error=No+user+specified");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get common user data
    $username = $_POST['username'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $phone = $_POST['phone'];
    
    // Get role-specific data
    $speciality_id = isset($_POST['speciality_id']) ? intval($_POST['speciality_id']) : null;
    $dob = isset($_POST['dob']) ? $_POST['dob'] : null;
    $gender = isset($_POST['gender']) ? $_POST['gender'] : null;
    $address = isset($_POST['address']) ? $_POST['address'] : null;
    $medical_history = isset($_POST['medical_history']) ? $_POST['medical_history'] : null;
    
    // Optional password change
    $new_password = $_POST['new_password'] ?? '';
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Update user table
        $update_user_sql = "UPDATE users SET username = ?, email = ?, role = ? WHERE user_id = ?";
        $update_user_stmt = $conn->prepare($update_user_sql);
        $update_user_stmt->bind_param("sssi", $username, $email, $role, $user_id);
        $update_user_stmt->execute();
        
        // Update password if provided
        if (!empty($new_password)) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_pwd_sql = "UPDATE users SET password_hash = ? WHERE user_id = ?";
            $update_pwd_stmt = $conn->prepare($update_pwd_sql);
            $update_pwd_stmt->bind_param("si", $password_hash, $user_id);
            $update_pwd_stmt->execute();
        }
        
        // Handle role-specific updates
        if ($role === 'doctor') {
            // Check if doctor record exists
            $check_doctor_sql = "SELECT doctor_id FROM doctors WHERE user_id = ?";
            $check_doctor_stmt = $conn->prepare($check_doctor_sql);
            $check_doctor_stmt->bind_param("i", $user_id);
            $check_doctor_stmt->execute();
            $doctor_result = $check_doctor_stmt->get_result();
            
            if ($doctor_result->num_rows > 0) {
                // Update existing doctor record
                $update_doctor_sql = "UPDATE doctors SET first_name = ?, last_name = ?, speciality_id = ?, phone = ? WHERE user_id = ?";
                $update_doctor_stmt = $conn->prepare($update_doctor_sql);
                $update_doctor_stmt->bind_param("ssisi", $first_name, $last_name, $speciality_id, $phone, $user_id);
                $update_doctor_stmt->execute();
            } else {
                // Insert new doctor record
                $insert_doctor_sql = "INSERT INTO doctors (user_id, first_name, last_name, speciality_id, phone) VALUES (?, ?, ?, ?, ?)";
                $insert_doctor_stmt = $conn->prepare($insert_doctor_sql);
                $insert_doctor_stmt->bind_param("issis", $user_id, $first_name, $last_name, $speciality_id, $phone);
                $insert_doctor_stmt->execute();
            }
            
            // Remove patient record if exists (role change)
            $delete_patient_sql = "DELETE FROM patients WHERE user_id = ?";
            $delete_patient_stmt = $conn->prepare($delete_patient_sql);
            $delete_patient_stmt->bind_param("i", $user_id);
            $delete_patient_stmt->execute();
        } elseif ($role === 'patient') {
            // Check if patient record exists
            $check_patient_sql = "SELECT patient_id FROM patients WHERE user_id = ?";
            $check_patient_stmt = $conn->prepare($check_patient_sql);
            $check_patient_stmt->bind_param("i", $user_id);
            $check_patient_stmt->execute();
            $patient_result = $check_patient_stmt->get_result();
            
            if ($patient_result->num_rows > 0) {
                // Update existing patient record
                $update_patient_sql = "UPDATE patients SET first_name = ?, last_name = ?, dob = ?, gender = ?, phone = ?, address = ?, medical_history = ? WHERE user_id = ?";
                $update_patient_stmt = $conn->prepare($update_patient_sql);
                $update_patient_stmt->bind_param("sssssssi", $first_name, $last_name, $dob, $gender, $phone, $address, $medical_history, $user_id);
                $update_patient_stmt->execute();
            } else {
                // Insert new patient record
                $insert_patient_sql = "INSERT INTO patients (user_id, first_name, last_name, dob, gender, phone, address, medical_history) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $insert_patient_stmt = $conn->prepare($insert_patient_sql);
                $insert_patient_stmt->bind_param("isssssss", $user_id, $first_name, $last_name, $dob, $gender, $phone, $address, $medical_history);
                $insert_patient_stmt->execute();
            }
            
            // Remove doctor record if exists (role change)
            $delete_doctor_sql = "DELETE FROM doctors WHERE user_id = ?";
            $delete_doctor_stmt = $conn->prepare($delete_doctor_sql);
            $delete_doctor_stmt->bind_param("i", $user_id);
            $delete_doctor_stmt->execute();
        } elseif ($role === 'admin') {
            // Admin doesn't need additional records, remove any doctor/patient records
            $delete_doctor_sql = "DELETE FROM doctors WHERE user_id = ?";
            $delete_doctor_stmt = $conn->prepare($delete_doctor_sql);
            $delete_doctor_stmt->bind_param("i", $user_id);
            $delete_doctor_stmt->execute();
            
            $delete_patient_sql = "DELETE FROM patients WHERE user_id = ?";
            $delete_patient_stmt = $conn->prepare($delete_patient_sql);
            $delete_patient_stmt->bind_param("i", $user_id);
            $delete_patient_stmt->execute();
        }
        
        // Commit transaction
        $conn->commit();
        
        header("Location: user_management.php?success=User+updated+successfully");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Failed to update user: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="bg-white shadow-md rounded-lg">
            <div class="flex justify-between items-center p-6 border-b">
                <h1 class="text-2xl font-bold text-gray-800">Edit User</h1>
                <a href="user_management.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Users
                </a>
            </div>
            
            <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative m-4" role="alert">
                <?= htmlspecialchars($error_message) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Common fields -->
                    <div class="space-y-4">
                        <h2 class="text-lg font-semibold text-gray-700 border-b pb-2">User Information</h2>
                        
                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                            <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="new_password" class="block text-sm font-medium text-gray-700">New Password (leave blank to keep current)</label>
                            <input type="password" id="new_password" name="new_password"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                            <select id="role" name="role" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    onchange="toggleRoleFields()">
                                <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="doctor" <?= $role === 'doctor' ? 'selected' : '' ?>>Doctor</option>
                                <option value="patient" <?= $role === 'patient' ? 'selected' : '' ?>>Patient</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($first_name) ?>" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($last_name) ?>" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700">Phone</label>
                            <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <!-- Doctor specific fields -->
                    <div id="doctor_fields" class="space-y-4 <?= $role !== 'doctor' ? 'hidden' : '' ?>">
                        <h2 class="text-lg font-semibold text-gray-700 border-b pb-2">Doctor Information</h2>
                        
                        <div>
                            <label for="speciality_id" class="block text-sm font-medium text-gray-700">Specialty</label>
                            <select id="speciality_id" name="speciality_id" 
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Select Specialty</option>
                                <?php foreach ($specialties as $specialty): ?>
                                <option value="<?= $specialty['speciality_id'] ?>" <?= $speciality_id == $specialty['speciality_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($specialty['speciality_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Patient specific fields -->
                    <div id="patient_fields" class="space-y-4 <?= $role !== 'patient' ? 'hidden' : '' ?>">
                        <h2 class="text-lg font-semibold text-gray-700 border-b pb-2">Patient Information</h2>
                        
                        <div>
                            <label for="dob" class="block text-sm font-medium text-gray-700">Date of Birth</label>
                            <input type="date" id="dob" name="dob" value="<?= htmlspecialchars($dob) ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="gender" class="block text-sm font-medium text-gray-700">Gender</label>
                            <select id="gender" name="gender"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Female</option>
                                <option value="other" <?= $gender === 'other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                            <textarea id="address" name="address" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"><?= htmlspecialchars($address) ?></textarea>
                        </div>
                        
                        <div>
                            <label for="medical_history" class="block text-sm font-medium text-gray-700">Medical History</label>
                            <textarea id="medical_history" name="medical_history" rows="5"
                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"><?= htmlspecialchars($medical_history) ?></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function toggleRoleFields() {
            const role = document.getElementById('role').value;
            const doctorFields = document.getElementById('doctor_fields');
            const patientFields = document.getElementById('patient_fields');
            
            if (role === 'doctor') {
                doctorFields.classList.remove('hidden');
                patientFields.classList.add('hidden');
                document.getElementById('speciality_id').setAttribute('required', 'required');
                document.getElementById('dob').removeAttribute('required');
                document.getElementById('gender').removeAttribute('required');
            } else if (role === 'patient') {
                doctorFields.classList.add('hidden');
                patientFields.classList.remove('hidden');
                document.getElementById('speciality_id').removeAttribute('required');
                document.getElementById('dob').setAttribute('required', 'required');
                document.getElementById('gender').setAttribute('required', 'required');
            } else {
                doctorFields.classList.add('hidden');
                patientFields.classList.add('hidden');
                document.getElementById('speciality_id').removeAttribute('required');
                document.getElementById('dob').removeAttribute('required');
                document.getElementById('gender').removeAttribute('required');
            }
        }
    </script>
</body>
</html>