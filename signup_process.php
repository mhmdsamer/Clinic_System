<?php
require_once 'connection.php';

// Fetch specialties before processing the form
$specialties = [];
try {
    $specialty_query = "SELECT speciality_id, speciality_name FROM specialties ORDER BY speciality_name";
    $specialty_result = $conn->query($specialty_query);
    
    if ($specialty_result) {
        while ($row = $specialty_result->fetch_assoc()) {
            $specialties[] = $row;
        }
    }
} catch (Exception $e) {
    // Log error or handle it appropriately
    error_log("Error fetching specialties: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate inputs
    $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Password validation
    if ($password !== $confirm_password) {
        die("Passwords do not match");
    }

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
            $doctor_stmt->bind_param("issss", $user_id, $first_name, $last_name, $speciality_id, $phone);
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

        // Redirect to login or dashboard
        header("Location: login_process.php?signup=success");
        exit();

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        die("Registration failed: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Management System - Signup</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gradient-to-br from-blue-100 to-blue-300 min-h-screen flex items-center justify-center">
    <div class="container mx-auto px-4">
        <div class="bg-white shadow-2xl rounded-xl overflow-hidden max-w-2xl mx-auto" x-data="signupForm()">
            <div class="p-8">
                <h2 class="text-3xl font-bold text-center text-blue-600 mb-6">Clinic Management System</h2>
                <h3 class="text-xl text-center text-gray-700 mb-8">Create Your Account</h3>

                <form action="signup_process.php" method="POST" class="space-y-6">
                    <!-- Role Selection -->
                    <div class="grid grid-cols-3 gap-4">
                        <label class="block" x-bind:class="{'border-blue-500 bg-blue-50': role === 'admin'}" @click="role = 'admin'">
                            <input type="radio" name="role" value="admin" class="hidden" x-model="role">
                            <div class="p-4 border rounded-lg text-center cursor-pointer transition duration-300">
                                <i class="fas fa-user-shield text-3xl text-gray-600 mb-2"></i>
                                <span class="block">Admin</span>
                            </div>
                        </label>
                        <label class="block" x-bind:class="{'border-blue-500 bg-blue-50': role === 'doctor'}" @click="role = 'doctor'">
                            <input type="radio" name="role" value="doctor" class="hidden" x-model="role">
                            <div class="p-4 border rounded-lg text-center cursor-pointer transition duration-300">
                                <i class="fas fa-user-md text-3xl text-gray-600 mb-2"></i>
                                <span class="block">Doctor</span>
                            </div>
                        </label>
                        <label class="block" x-bind:class="{'border-blue-500 bg-blue-50': role === 'patient'}" @click="role = 'patient'">
                            <input type="radio" name="role" value="patient" class="hidden" x-model="role">
                            <div class="p-4 border rounded-lg text-center cursor-pointer transition duration-300">
                                <i class="fas fa-user-injured text-3xl text-gray-600 mb-2"></i>
                                <span class="block">Patient</span>
                            </div>
                        </label>
                    </div>

                    <!-- Common User Fields -->
                    <div class="grid md:grid-cols-2 gap-4">
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
                        <div class="grid md:grid-cols-2 gap-4">
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
                                    <?php foreach ($specialties as $specialty): ?>
                                        <option value="<?php echo htmlspecialchars($specialty['speciality_id']); ?>">
                                            <?php echo htmlspecialchars($specialty['speciality_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
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
                        <div class="grid md:grid-cols-2 gap-4">
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

                    <div class="text-center">
                        <button type="submit" 
                            class="bg-blue-600 text-white px-8 py-3 rounded-full hover:bg-blue-700 transition duration-300 transform hover:scale-105">
                            Create Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function signupForm() {
            return {
                role: '',
            }
        }
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</body>
</html>