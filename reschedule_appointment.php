<?php
session_start();
require_once 'connection.php';

// Ensure only logged-in patients can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

// Get the appointment ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: homepage.php?error=Invalid+Appointment");
    exit();
}

$appointment_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Verify this appointment belongs to the logged-in patient
$verify_query = "
    SELECT a.*, p.patient_id, d.doctor_id, d.first_name AS doctor_first_name, 
           d.last_name AS doctor_last_name, s.speciality_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN specialties s ON d.speciality_id = s.speciality_id
    JOIN users u ON p.user_id = u.user_id
    WHERE a.appointment_id = ? AND u.user_id = ? AND a.status = 'scheduled'
";
$verify_stmt = $conn->prepare($verify_query);
$verify_stmt->bind_param("ii", $appointment_id, $user_id);
$verify_stmt->execute();
$result = $verify_stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: homepage.php?error=Appointment+Not+Found");
    exit();
}

$appointment = $result->fetch_assoc();
$doctor_id = $appointment['doctor_id'];
$patient_id = $appointment['patient_id'];
$current_date = $appointment['appointment_date'];
$current_time = $appointment['time_slot'];

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

// Process form submission
$error_message = "";
$success_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reschedule'])) {
        $new_date = $_POST['appointment_date'];
        $new_time = $_POST['time_slot'];
        
        // Validate date is in the future
        if (strtotime($new_date) < strtotime(date('Y-m-d'))) {
            $error_message = "Please select a future date.";
        } else {
            // Check if the selected time slot is available
            $availability_query = "
                SELECT * FROM doctor_availability
                WHERE doctor_id = ? AND day_of_week = ?
                AND ? BETWEEN start_time AND end_time
            ";
            
            // Get the day of week for the selected date
            $day_of_week = date('l', strtotime($new_date));
            
            $availability_stmt = $conn->prepare($availability_query);
            $availability_stmt->bind_param("iss", $doctor_id, $day_of_week, $new_time);
            $availability_stmt->execute();
            $availability_result = $availability_stmt->get_result();
            
            // Check if the doctor is available on the selected day and time
            if ($availability_result->num_rows === 0) {
                $error_message = "The doctor is not available at the selected date and time.";
            } else {
                // Check if the time slot is already booked
                $booking_query = "
                    SELECT * FROM appointments
                    WHERE doctor_id = ? AND appointment_date = ? AND time_slot = ?
                    AND appointment_id != ? AND status = 'scheduled'
                ";
                $booking_stmt = $conn->prepare($booking_query);
                $booking_stmt->bind_param("issi", $doctor_id, $new_date, $new_time, $appointment_id);
                $booking_stmt->execute();
                $booking_result = $booking_stmt->get_result();
                
                if ($booking_result->num_rows > 0) {
                    $error_message = "This time slot is already booked. Please select another time.";
                } else {
                    // Update the appointment
                    $update_query = "
                        UPDATE appointments
                        SET appointment_date = ?, time_slot = ?
                        WHERE appointment_id = ?
                    ";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("ssi", $new_date, $new_time, $appointment_id);
                    
                    if ($update_stmt->execute()) {
                        $success_message = "Your appointment has been successfully rescheduled.";
                        
                        // Update the appointment variable for display
                        $appointment['appointment_date'] = $new_date;
                        $appointment['time_slot'] = $new_time;
                    } else {
                        $error_message = "Failed to reschedule the appointment. Please try again.";
                    }
                }
            }
        }
    }
}

// Fetch doctor's availability
$availability_query = "
    SELECT * FROM doctor_availability
    WHERE doctor_id = ?
    ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
";
$availability_stmt = $conn->prepare($availability_query);
$availability_stmt->bind_param("i", $doctor_id);
$availability_stmt->execute();
$availability_result = $availability_stmt->get_result();

// Store availability data for JavaScript
$availability_data = [];
while ($row = $availability_result->fetch_assoc()) {
    $availability_data[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reschedule Appointment - Clinic Management System</title>
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
                        <i class="fas fa-calendar-alt mr-3"></i>Reschedule Appointment
                    </span>
                </h1>
                <a href="homepage.php" class="bg-white action-btn text-purple-600 px-6 py-3 rounded-full shadow-lg hover:bg-purple-50 transition flex items-center">
                    <i class="fas fa-home mr-2"></i>Back to Dashboard
                </a>
            </div>

            <?php if ($error_message): ?>
            <div class="glass-card p-4 mb-8 border-l-4 border-red-500" role="alert">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-red-700 font-medium"><?= $error_message ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
            <div class="glass-card p-4 mb-8 border-l-4 border-green-500" role="alert">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-500 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-green-700 font-medium"><?= $success_message ?></p>
                        <p class="mt-2">
                            <a href="homepage.php" class="text-green-700 underline font-medium">Return to dashboard</a>
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Current Appointment Details Card -->
            <div class="glass-card p-8 mb-8 relative overflow-hidden">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-info-circle text-purple-600 mr-3"></i>Current Appointment Details
                </h2>
                <div class="grid md:grid-cols-2 gap-8">
                    <div class="bg-purple-50 rounded-lg p-5">
                        <p class="text-gray-500 text-sm mb-1">Doctor</p>
                        <p class="text-xl font-bold text-purple-600">
                            Dr. <?= htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']) ?>
                        </p>
                        <p class="text-gray-600 mt-1">
                            <i class="fas fa-stethoscope mr-1 text-purple-500"></i>
                            <?= htmlspecialchars($appointment['speciality_name']) ?>
                        </p>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-5">
                        <p class="text-gray-500 text-sm mb-1">Scheduled For</p>
                        <p class="text-xl font-bold text-purple-600">
                            <?= date('F d, Y', strtotime($appointment['appointment_date'])) ?>
                        </p>
                        <p class="text-gray-600 mt-1">
                            <i class="fas fa-clock mr-1 text-purple-500"></i>
                            <?= date('h:i A', strtotime($appointment['time_slot'])) ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Reschedule Form Section -->
            <div class="glass-card p-8 mb-8" x-data="{ selectedDate: '<?= $appointment['appointment_date'] ?>' }">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-calendar-alt text-blue-500 mr-3"></i>Reschedule Appointment
                </h2>
                <form method="POST" action="">
                    <div class="grid md:grid-cols-2 gap-8 mb-8">
                        <div>
                            <label for="appointment_date" class="block text-gray-700 font-semibold mb-2">
                                <i class="far fa-calendar mr-2 text-purple-500"></i>New Appointment Date
                            </label>
                            <input 
                                type="date" 
                                id="appointment_date" 
                                name="appointment_date" 
                                x-model="selectedDate"
                                min="<?= date('Y-m-d') ?>" 
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600 transition" 
                                required
                                value="<?= $appointment['appointment_date'] ?>"
                            >
                        </div>
                        
                        <div>
                            <label for="time_slot" class="block text-gray-700 font-semibold mb-2">
                                <i class="far fa-clock mr-2 text-purple-500"></i>New Time Slot
                            </label>
                            <select 
                                id="time_slot" 
                                name="time_slot" 
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600 transition" 
                                required
                                x-bind:disabled="!selectedDate"
                            >
                                <?php
                                // Javascript will populate this based on the selected date and doctor availability
                                ?>
                                <option value="<?= $appointment['time_slot'] ?>" selected>
                                    <?= date('h:i A', strtotime($appointment['time_slot'])) ?> (Current)
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end mt-8">
                        <div class="flex space-x-4">
                            <a href="homepage.php" class="bg-gray-200 text-gray-700 px-6 py-3 rounded-full hover:bg-gray-300 transition action-btn">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                            <button type="submit" name="reschedule" class="bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-3 rounded-full hover:from-purple-700 hover:to-blue-600 transition action-btn">
                                <i class="fas fa-calendar-check mr-2"></i>Reschedule Appointment
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Doctor Availability Section -->
            <div class="glass-card p-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-clock text-green-500 mr-3"></i>Doctor's Availability
                </h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white rounded-lg overflow-hidden">
                        <thead class="bg-purple-50">
                            <tr>
                                <th class="py-3 px-6 text-left text-xs font-medium text-gray-600 uppercase tracking-wider border-b">
                                    Day
                                </th>
                                <th class="py-3 px-6 text-left text-xs font-medium text-gray-600 uppercase tracking-wider border-b">
                                    Available Hours
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($availability_data as $availability): ?>
                            <tr class="hover:bg-purple-50 transition-colors">
                                <td class="py-4 px-6 text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($availability['day_of_week']) ?>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-900">
                                    <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full">
                                        <?= date('h:i A', strtotime($availability['start_time'])) ?> - 
                                        <?= date('h:i A', strtotime($availability['end_time'])) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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

    <script>
        // Store doctor availability data for JavaScript use
        const doctorAvailability = <?= json_encode($availability_data) ?>;
        
        // Function to generate time slots based on doctor availability
        function generateTimeSlots(date) {
            const dayOfWeek = new Date(date).toLocaleDateString('en-US', { weekday: 'long' });
            const timeSlotSelect = document.getElementById('time_slot');
            const currentValue = timeSlotSelect.value;
            
            // Clear existing options except the first one (if it's a "select" placeholder)
            while (timeSlotSelect.options.length > 0) {
                timeSlotSelect.remove(0);
            }
            
            // Find if doctor is available on selected day
            const availability = doctorAvailability.find(a => a.day_of_week === dayOfWeek);
            
            if (!availability) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Doctor not available on this day';
                option.disabled = true;
                option.selected = true;
                timeSlotSelect.appendChild(option);
                return;
            }
            
            // Generate hourly time slots within the doctor's availability
            const startTime = new Date(`2000-01-01T${availability.start_time}`);
            const endTime = new Date(`2000-01-01T${availability.end_time}`);
            
            // Subtract one hour to not include the end time
            endTime.setHours(endTime.getHours() - 1);
            
            for (let time = new Date(startTime); time <= endTime; time.setHours(time.getHours() + 1)) {
                const timeStr = time.toTimeString().substring(0, 5);
                const formattedTime = new Date(`2000-01-01T${timeStr}`).toLocaleTimeString('en-US', { 
                    hour: 'numeric', 
                    minute: 'numeric', 
                    hour12: true 
                });
                
                const option = document.createElement('option');
                option.value = timeStr;
                option.textContent = formattedTime;
                
                // If this is the current appointment time, mark it
                if (timeStr === '<?= $appointment['time_slot'] ?>') {
                    option.textContent += ' (Current)';
                    option.selected = true;
                }
                
                timeSlotSelect.appendChild(option);
            }
            
            // If no options were added
            if (timeSlotSelect.options.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'No available time slots';
                option.disabled = true;
                option.selected = true;
                timeSlotSelect.appendChild(option);
            }
        }
        
        // Initialize time slots based on selected date
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('appointment_date');
            
            // Generate time slots based on the selected date
            generateTimeSlots(dateInput.value);
            
            // Update time slots when date changes
            dateInput.addEventListener('change', function() {
                generateTimeSlots(this.value);
            });
        });
    </script>
</body>
</html>