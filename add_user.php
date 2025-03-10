<?php
session_start();
require_once 'connection.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php?error=Unauthorized+Access");
    exit();
}

// Fetch specialties for doctor dropdown
$specialties_query = "SELECT * FROM specialties";
$specialties_result = $conn->query($specialties_query);

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate inputs
    $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Password validation
    if ($password !== $confirm_password) {
        $error_message = "Passwords do not match";
    } else {
        // Hash the password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        try {
            // Start transaction
            $conn->begin_transaction();

            // Insert into users table
            $user_sql = "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("ssss", $username, $email, $password_hash, $role);
            $user_stmt->execute();
            $user_id = $conn->insert_id;

            // Role-specific table insertion
            if ($role === 'doctor') {
                $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
                $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
                $speciality_id = filter_input(INPUT_POST, 'speciality_id', FILTER_VALIDATE_INT);
                $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);

                $doctor_sql = "INSERT INTO doctors (user_id, first_name, last_name, speciality_id, phone) VALUES (?, ?, ?, ?, ?)";
                $doctor_stmt = $conn->prepare($doctor_sql);
                $doctor_stmt->bind_param("issis", $user_id, $first_name, $last_name, $speciality_id, $phone);
                $doctor_stmt->execute();
            } elseif ($role === 'patient') {
                $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
                $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
                $dob = filter_input(INPUT_POST, 'dob', FILTER_SANITIZE_STRING);
                $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
                $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
                $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);

                $patient_sql = "INSERT INTO patients (user_id, first_name, last_name, dob, gender, phone, address) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $patient_stmt = $conn->prepare($patient_sql);
                $patient_stmt->bind_param("issssss", $user_id, $first_name, $last_name, $dob, $gender, $phone, $address);
                $patient_stmt->execute();
            }

            // Commit transaction
            $conn->commit();

            // Set success message
            $success_message = "User created successfully!";

        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "User creation failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-xl mx-auto bg-white shadow-md rounded-lg p-8">
            <h2 class="text-2xl font-bold text-blue-600 mb-6 text-center">Add New User</h2>
            
            <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" x-data="userForm()">
                <!-- Role Selection -->
                <div class="mb-6">
                    <label class="block text-gray-700 mb-2">Select User Role</label>
                    <div class="grid grid-cols-3 gap-4">
                        <label class="block" x-bind:class="{'border-blue-500 bg-blue-50': role === 'admin'}" @click="role = 'admin'">
                            <input type="radio" name="role" value="admin" class="hidden" x-model="role" required>
                            <div class="p-4 border rounded-lg text-center cursor-pointer transition duration-300">
                                <i class="fas fa-user-shield text-3xl text-gray-600 mb-2"></i>
                                <span class="block">Admin</span>
                            </div>
                        </label>
                        <label class="block" x-bind:class="{'border-blue-500 bg-blue-50': role === 'doctor'}" @click="role = 'doctor'">
                            <input type="radio" name="role" value="doctor" class="hidden" x-model="role" required>
                            <div class="p-4 border rounded-lg text-center cursor-pointer transition duration-300">
                                <i class="fas fa-user-md text-3xl text-gray-600 mb-2"></i>
                                <span class="block">Doctor</span>
                            </div>
                        </label>
                        <label class="block" x-bind:class="{'border-blue-500 bg-blue-50': role === 'patient'}" @click="role = 'patient'">
                            <input type="radio" name="role" value="patient" class="hidden" x-model="role" required>
                            <div class="p-4 border rounded-lg text-center cursor-pointer transition duration-300">
                                <i class="fas fa-user-injured text-3xl text-gray-600 mb-2"></i>
                                <span class="block">Patient</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Common User Fields -->
                <div class="grid md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="username" class="block text-gray-700 mb-2">Username</label>
                        <input type="text" name="username" id="username" required 
                            class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="email" class="block text-gray-700 mb-2">Email</label>
                        <input type="email" name="email" id="email" required 
                            class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="password" class="block text-gray-700 mb-2">Password</label>
                        <input type="password" name="password" id="password" required 
                            class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-gray-700 mb-2">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" required 
                            class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <!-- Role-Specific Fields -->
                <template x-if="role === 'doctor'">
                    <div class="grid md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="first_name" class="block text-gray-700 mb-2">First Name</label>
                            <input type="text" name="first_name" id="first_name" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="last_name" class="block text-gray-700 mb-2">Last Name</label>
                            <input type="text" name="last_name" id="last_name" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="speciality" class="block text-gray-700 mb-2">Speciality</label>
                            <select name="speciality_id" id="speciality" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Speciality</option>
                                <?php 
                                // Reset the result pointer to the beginning
                                $specialties_result->data_seek(0);
                                while($specialty = $specialties_result->fetch_assoc()) {
                                    echo "<option value='{$specialty['speciality_id']}'>{$specialty['speciality_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <label for="doctor_phone" class="block text-gray-700 mb-2">Phone</label>
                            <input type="tel" name="phone" id="doctor_phone" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </template>

                <template x-if="role === 'patient'">
                    <div class="grid md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="first_name" class="block text-gray-700 mb-2">First Name</label>
                            <input type="text" name="first_name" id="first_name" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="last_name" class="block text-gray-700 mb-2">Last Name</label>
                            <input type="text" name="last_name" id="last_name" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="dob" class="block text-gray-700 mb-2">Date of Birth</label>
                            <input type="date" name="dob" id="dob" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="gender" class="block text-gray-700 mb-2">Gender</label>
                            <select name="gender" id="gender" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label for="patient_phone" class="block text-gray-700 mb-2">Phone</label>
                            <input type="tel" name="phone" id="patient_phone" required 
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="address" class="block text-gray-700 mb-2">Address</label>
                            <input type="text" name="address" id="address"
                                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </template>

                <div class="text-center mt-6">
                    <button type="submit" 
                        class="bg-blue-600 text-white px-8 py-3 rounded-full hover:bg-blue-700 transition duration-300 transform hover:scale-105">
                        Create User
                    </button>
                </div>
            </form>
            
            <div class="mt-6 text-center">
                <a href="admin_dashboard.php" class="text-blue-600 hover:underline">
                    Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <script>
        function userForm() {
            return {
                role: ''
            }
        }
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</body>
</html>