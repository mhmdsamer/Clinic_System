<?php
session_start();
require_once 'connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;

    // Prepare SQL to prevent SQL injection
    $sql = "SELECT user_id, username, password_hash, role FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Verify password
        if (password_verify($password, $user['password_hash'])) {
            // Password is correct, start a new session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Set remember me cookie if selected
            if ($remember) {
                $token = bin2hex(random_bytes(16)); // Generate a random token
                $expiry = time() + (30 * 24 * 60 * 60); // 30 days

                // Store token in database (you'd typically have a separate tokens table)
                $token_sql = "INSERT INTO user_tokens (user_id, token, expiry) VALUES (?, ?, ?)";
                $token_stmt = $conn->prepare($token_sql);
                $token_stmt->bind_param("iss", $user['user_id'], $token, date('Y-m-d H:i:s', $expiry));
                $token_stmt->execute();

                // Set cookie
                setcookie('remember_user', $token, $expiry, '/', '', true, true);
            }

            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header("Location: admin_dashboard.php");
                    break;
                case 'doctor':
                    header("Location: doctor_dashboard.php");
                    break;
                case 'patient':
                    header("Location: homepage.php");
                    break;
                default:
                    header("Location: login_process.php?error=Invalid+role");
            }
            exit();
        } else {
            // Invalid password
            header("Location: login_process.php?error=Invalid+username+or+password");
            exit();
        }
    } else {
        // User not found
        header("Location: login_process.php?error=Invalid+username+or+password");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Management System - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gradient-to-br from-blue-100 to-blue-300 min-h-screen flex items-center justify-center">
    <div class="container mx-auto px-4">
        <div class="bg-white shadow-2xl rounded-xl overflow-hidden max-w-md mx-auto" x-data="loginForm()">
            <div class="p-8">
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-bold text-blue-600">Clinic Management System</h2>
                    <p class="text-gray-600 mt-2">Welcome Back!</p>
                </div>

                <?php
                // Check for login error or signup success
                if (isset($_GET['error'])) {
                    $error_message = htmlspecialchars($_GET['error']);
                    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4' role='alert'>
                            <span class='block sm:inline'>$error_message</span>
                          </div>";
                }

                if (isset($_GET['signup']) && $_GET['signup'] == 'success') {
                    echo "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4' role='alert'>
                            <span class='block sm:inline'>Account created successfully. Please log in.</span>
                          </div>";
                }
                ?>

                <form action="login_process.php" method="POST" class="space-y-6">
                    <div>
                        <label for="username" class="block text-gray-700 mb-2">Username</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center">
                                <i class="fas fa-user text-gray-400"></i>
                            </span>
                            <input type="text" name="username" id="username" required 
                                class="w-full pl-10 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Enter your username">
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-gray-700 mb-2">Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center">
                                <i class="fas fa-lock text-gray-400"></i>
                            </span>
                            <input :type="showPassword ? 'text' : 'password'" name="password" id="password" required 
                                class="w-full pl-10 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Enter your password">
                            <span class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer" @click="togglePasswordVisibility">
                                <i x-show="!showPassword" class="fas fa-eye text-gray-400"></i>
                                <i x-show="showPassword" class="fas fa-eye-slash text-gray-400"></i>
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input type="checkbox" id="remember" name="remember" 
                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="remember" class="ml-2 block text-sm text-gray-900">
                                Remember me
                            </label>
                        </div>
                        <div>
                            <a href="forgot_password.php" class="text-sm text-blue-600 hover:text-blue-800">
                                Forgot Password?
                            </a>
                        </div>
                    </div>

                    <div>
                        <button type="submit" 
                            class="w-full bg-blue-600 text-white px-4 py-3 rounded-full hover:bg-blue-700 transition duration-300 transform hover:scale-105">
                            Login
                        </button>
                    </div>
                </form>

                <div class="mt-6 text-center">
                    <p class="text-gray-600">
                        Don't have an account? 
                        <a href="signup_process.php" class="text-blue-600 hover:text-blue-800 font-semibold">
                            Sign Up
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function loginForm() {
            return {
                showPassword: false,
                togglePasswordVisibility() {
                    this.showPassword = !this.showPassword;
                }
            }
        }
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</body>
</html>