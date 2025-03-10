<?php
session_start();
require_once 'connection.php';

// Ensure only logged-in patients can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

// Get patient information
$user_id = $_SESSION['user_id'];
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

if (!$patient) {
    session_destroy();
    header("Location: login_process.php?error=Patient+Not+Found");
    exit();
}

$patient_id = $patient['patient_id'];
$message = '';
$error = '';

// Process appointment booking form
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['book_appointment'])) {
    $doctor_id = $_POST['doctor_id'];
    $appointment_date = $_POST['appointment_date'];
    $time_slot = $_POST['time_slot'];
    $notes = $_POST['notes'] ?? '';
    
    // Validate the selected time slot is available
    $availability_check = "
        SELECT COUNT(*) as count
        FROM appointments 
        WHERE doctor_id = ? 
        AND appointment_date = ? 
        AND time_slot = ?
        AND status = 'scheduled'
    ";
    $check_stmt = $conn->prepare($availability_check);
    $check_stmt->bind_param("iss", $doctor_id, $appointment_date, $time_slot);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_data = $check_result->fetch_assoc();
    
    if ($check_data['count'] > 0) {
        $error = "This time slot is already booked. Please select another time.";
    } else {
        // Insert new appointment
        $insert_query = "
            INSERT INTO appointments 
            (patient_id, doctor_id, appointment_date, time_slot, notes, status) 
            VALUES (?, ?, ?, ?, ?, 'scheduled')
        ";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("iisss", $patient_id, $doctor_id, $appointment_date, $time_slot, $notes);
        
        if ($insert_stmt->execute()) {
            $message = "Appointment booked successfully!";
        } else {
            $error = "Error booking appointment: " . $conn->error;
        }
    }
}

// Fetch all specialties
$specialties_query = "SELECT * FROM specialties ORDER BY speciality_name";
$specialties_result = $conn->query($specialties_query);

// Default variables
$selected_specialty = $_GET['specialty'] ?? 0;
$selected_doctor = $_GET['doctor'] ?? 0;
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Fetch doctors based on specialty filter
$doctors_query = "
    SELECT d.doctor_id, d.first_name, d.last_name, s.speciality_name 
    FROM doctors d
    JOIN specialties s ON d.speciality_id = s.speciality_id
";

if ($selected_specialty > 0) {
    $doctors_query .= " WHERE d.speciality_id = " . intval($selected_specialty);
}

$doctors_query .= " ORDER BY d.last_name, d.first_name";
$doctors_result = $conn->query($doctors_query);

// Function to generate available time slots based on doctor's availability
function getAvailableTimeSlots($conn, $doctor_id, $date) {
    // Get day of week
    $day_of_week = date('l', strtotime($date));
    
    // Get doctor's availability for that day
    $availability_query = "
        SELECT start_time, end_time
        FROM doctor_availability
        WHERE doctor_id = ? AND day_of_week = ?
    ";
    $availability_stmt = $conn->prepare($availability_query);
    $availability_stmt->bind_param("is", $doctor_id, $day_of_week);
    $availability_stmt->execute();
    $availability_result = $availability_stmt->get_result();
    
    if ($availability_result->num_rows === 0) {
        return []; // Doctor not available on this day
    }
    
    $availability = $availability_result->fetch_assoc();
    $start_time = strtotime($availability['start_time']);
    $end_time = strtotime($availability['end_time']);
    
    // Generate 30-minute slots
    $time_slots = [];
    $slot_duration = 30 * 60; // 30 minutes in seconds
    
    for ($time = $start_time; $time < $end_time; $time += $slot_duration) {
        $slot_time = date('H:i:s', $time);
        
        // Check if this slot is already booked
        $booked_query = "
            SELECT COUNT(*) as is_booked
            FROM appointments
            WHERE doctor_id = ? 
            AND appointment_date = ? 
            AND time_slot = ? 
            AND status = 'scheduled'
        ";
        $booked_stmt = $conn->prepare($booked_query);
        $booked_stmt->bind_param("iss", $doctor_id, $date, $slot_time);
        $booked_stmt->execute();
        $booked_result = $booked_stmt->get_result();
        $is_booked = $booked_result->fetch_assoc()['is_booked'];
        
        if ($is_booked == 0) {
            $time_slots[] = [
                'time' => $slot_time,
                'formatted_time' => date('h:i A', $time)
            ];
        }
    }
    
    return $time_slots;
}

// Get available time slots if doctor and date are selected
$available_slots = [];
if ($selected_doctor > 0 && !empty($selected_date)) {
    $available_slots = getAvailableTimeSlots($conn, $selected_doctor, $selected_date);
}

// Get doctor availability summary for the sidebar
$availability_summary = [];
if ($selected_specialty > 0) {
    $availability_query = "
        SELECT 
            d.doctor_id, 
            d.first_name, 
            d.last_name, 
            s.speciality_name,
            da.day_of_week, 
            TIME_FORMAT(da.start_time, '%h:%i %p') as start_time_formatted, 
            TIME_FORMAT(da.end_time, '%h:%i %p') as end_time_formatted
        FROM doctors d
        JOIN specialties s ON d.speciality_id = s.speciality_id
        JOIN doctor_availability da ON d.doctor_id = da.doctor_id
        WHERE d.speciality_id = ?
        ORDER BY 
            FIELD(da.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
            d.last_name, 
            d.first_name
    ";
    $availability_stmt = $conn->prepare($availability_query);
    $availability_stmt->bind_param("i", $selected_specialty);
    $availability_stmt->execute();
    $availability_result = $availability_stmt->get_result();
    
    while ($row = $availability_result->fetch_assoc()) {
        if (!isset($availability_summary[$row['doctor_id']])) {
            $availability_summary[$row['doctor_id']] = [
                'name' => "Dr. {$row['first_name']} {$row['last_name']}",
                'specialty' => $row['speciality_name'],
                'schedule' => []
            ];
        }
        
        $availability_summary[$row['doctor_id']]['schedule'][] = [
            'day' => $row['day_of_week'],
            'hours' => "{$row['start_time_formatted']} - {$row['end_time_formatted']}"
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment - Clinic Management System</title>
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
                        <i class="fas fa-calendar-plus mr-3"></i>Book an Appointment
                    </span>
                </h1>
                <a href="homepage.php" class="bg-white action-btn text-purple-600 px-6 py-3 rounded-full shadow-lg hover:bg-purple-50 transition flex items-center">
                    <i class="fas fa-home mr-2"></i>Back to Dashboard
                </a>
            </div>
            
            <div class="flex flex-col md:flex-row md:space-x-6">
                <!-- Main content -->
                <div class="md:w-2/3">
                    <!-- Booking Form Section -->
                    <div class="glass-card p-8 mb-6">
                        <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                            <i class="fas fa-calendar-check text-purple-600 mr-3"></i>Schedule Appointment
                        </h2>
                        
                        <?php if ($error): ?>
                            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                                <p><?= $error ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($message): ?>
                            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert">
                                <p><?= $message ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <form method="get" action="" class="mb-6">
                            <div class="grid md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label for="specialty" class="block text-gray-500 text-sm mb-1">Select Specialty</label>
                                    <select name="specialty" id="specialty" class="w-full px-4 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 bg-purple-50" onchange="this.form.submit()">
                                        <option value="0">All Specialties</option>
                                        <?php while ($specialty = $specialties_result->fetch_assoc()): ?>
                                            <option value="<?= $specialty['speciality_id'] ?>" <?= $selected_specialty == $specialty['speciality_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($specialty['speciality_name']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="doctor" class="block text-gray-500 text-sm mb-1">Select Doctor</label>
                                    <select name="doctor" id="doctor" class="w-full px-4 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 bg-purple-50" onchange="this.form.submit()">
                                        <option value="0">Select a Doctor</option>
                                        <?php while ($doctor = $doctors_result->fetch_assoc()): ?>
                                            <option value="<?= $doctor['doctor_id'] ?>" <?= $selected_doctor == $doctor['doctor_id'] ? 'selected' : '' ?>>
                                                Dr. <?= htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']) ?> (<?= htmlspecialchars($doctor['speciality_name']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label for="date" class="block text-gray-500 text-sm mb-1">Select Date</label>
                                <input type="date" name="date" id="date" min="<?= date('Y-m-d') ?>" value="<?= $selected_date ?>" class="w-full px-4 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 bg-purple-50" onchange="this.form.submit()">
                            </div>
                        </form>
                        
                        <?php if ($selected_doctor > 0 && !empty($selected_date)): ?>
                            <?php if (empty($available_slots)): ?>
                                <div class="bg-yellow-100 p-5 rounded-lg border-l-4 border-yellow-500 mb-4">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-exclamation-triangle text-yellow-500 text-xl"></i>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-yellow-700">No available time slots for the selected doctor on this date.</p>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <form method="post" action="" class="border-t border-gray-200 pt-6">
                                    <input type="hidden" name="doctor_id" value="<?= $selected_doctor ?>">
                                    <input type="hidden" name="appointment_date" value="<?= $selected_date ?>">
                                    
                                    <div class="mb-6">
                                        <label class="block text-gray-700 font-medium mb-3">Available Time Slots</label>
                                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                            <?php foreach ($available_slots as $slot): ?>
                                                <label class="flex items-center p-3 border rounded-lg hover:bg-purple-50 cursor-pointer">
                                                    <input type="radio" name="time_slot" value="<?= $slot['time'] ?>" class="mr-2 text-purple-600" required>
                                                    <span><?= $slot['formatted_time'] ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-6">
                                        <label for="notes" class="block text-gray-700 font-medium mb-2">Reason for Visit (Optional)</label>
                                        <textarea name="notes" id="notes" rows="3" class="w-full px-4 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 bg-purple-50" placeholder="Describe your symptoms or reason for visit"></textarea>
                                    </div>
                                    
                                    <div class="text-right">
                                        <button type="submit" name="book_appointment" class="bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-3 rounded-full shadow-lg hover:from-purple-700 hover:to-blue-600 action-btn">
                                            <i class="fas fa-calendar-check mr-2"></i>Book Appointment
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($selected_doctor > 0): ?>
                            <div class="text-center p-6 bg-purple-50 rounded-lg">
                                <p class="text-gray-600">Please select a date to view available time slots.</p>
                            </div>
                        <?php elseif (!empty($selected_date)): ?>
                            <div class="text-center p-6 bg-purple-50 rounded-lg">
                                <p class="text-gray-600">Please select a doctor to view available time slots.</p>
                            </div>
                        <?php else: ?>
                            <div class="text-center p-6 bg-purple-50 rounded-lg">
                                <p class="text-gray-600">Please select a specialty, doctor, and date to book an appointment.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- My Upcoming Appointments Section -->
                    <div class="glass-card p-8">
                        <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                            <i class="fas fa-calendar-alt text-blue-500 mr-3"></i>My Upcoming Appointments
                        </h2>
                        
                        <?php
                        // Fetch upcoming appointments
                        $upcoming_query = "
                            SELECT 
                                a.appointment_id, 
                                a.appointment_date, 
                                a.time_slot, 
                                a.notes,
                                d.first_name, 
                                d.last_name, 
                                s.speciality_name
                            FROM appointments a
                            JOIN doctors d ON a.doctor_id = d.doctor_id
                            JOIN specialties s ON d.speciality_id = s.speciality_id
                            WHERE a.patient_id = ? AND a.status = 'scheduled' AND a.appointment_date >= CURDATE()
                            ORDER BY a.appointment_date, a.time_slot
                        ";
                        $upcoming_stmt = $conn->prepare($upcoming_query);
                        $upcoming_stmt->bind_param("i", $patient_id);
                        $upcoming_stmt->execute();
                        $upcoming_result = $upcoming_stmt->get_result();
                        ?>
                        
                        <?php if ($upcoming_result->num_rows > 0): ?>
                            <div class="grid md:grid-cols-1 gap-6">
                                <?php while($appointment = $upcoming_result->fetch_assoc()): ?>
                                    <div x-data="{ open: false }" class="bg-purple-50 rounded-lg overflow-hidden border border-purple-100">
                                        <!-- Appointment Header -->
                                        <div class="p-6 flex justify-between items-center cursor-pointer" @click="open = !open">
                                            <div class="flex items-center">
                                                <div class="w-14 h-14 flex-shrink-0 bg-blue-100 rounded-full flex items-center justify-center mr-4">
                                                    <i class="fas fa-calendar-check text-2xl text-blue-500"></i>
                                                </div>
                                                <div>
                                                    <h3 class="text-xl font-bold text-gray-800">
                                                        <?= date('F d, Y', strtotime($appointment['appointment_date'])) ?> at <?= date('h:i A', strtotime($appointment['time_slot'])) ?>
                                                    </h3>
                                                    <p class="text-gray-600">
                                                        <i class="fas fa-user-md mr-1 text-purple-500"></i>
                                                        Dr. <?= htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']) ?> 
                                                        <span class="mx-2">•</span>
                                                        <i class="fas fa-stethoscope mr-1 text-purple-500"></i>
                                                        <?= htmlspecialchars($appointment['speciality_name']) ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <button class="w-10 h-10 flex items-center justify-center rounded-full bg-purple-100 text-purple-600 transition-all duration-300" 
                                                    :class="{'transform rotate-180': open}">
                                                <i class="fas fa-chevron-down"></i>
                                            </button>
                                        </div>
                                        
                                        <!-- Appointment Details (collapsible) -->
                                        <div class="border-t border-purple-100" x-show="open" x-cloak>
                                            <div class="p-6">
                                                <h4 class="font-semibold text-gray-700 mb-3 flex items-center">
                                                    <i class="fas fa-clipboard-list text-purple-500 mr-2"></i>Notes:
                                                </h4>
                                                <div class="bg-white p-5 rounded-lg mb-4">
                                                    <?= !empty($appointment['notes']) ? nl2br(htmlspecialchars($appointment['notes'])) : 'No notes provided' ?>
                                                </div>
                                                
                                                <div class="flex justify-end mt-4">
                                                    <a href="cancel_appointment.php?id=<?= $appointment['appointment_id'] ?>" class="bg-red-500 text-white px-6 py-3 rounded-full action-btn" onclick="return confirm('Are you sure you want to cancel this appointment?');">
                                                        <i class="fas fa-times-circle mr-2"></i>Cancel Appointment
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="glass-card p-8 text-center bg-gray-50">
                                <div class="w-20 h-20 bg-yellow-100 rounded-full mx-auto flex items-center justify-center mb-4">
                                    <i class="fas fa-calendar-alt text-4xl text-yellow-500"></i>
                                </div>
                                <p class="text-gray-700 text-lg mb-4">No upcoming appointments found.</p>
                                <p class="text-gray-600 mb-6">Your scheduled appointments will appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Sidebar - Doctor Availability Summary -->
                <div class="md:w-1/3 mt-6 md:mt-0">
                    <div class="glass-card p-8 sticky top-24">
                        <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                            <i class="fas fa-user-md text-purple-600 mr-3"></i>Doctor Availability
                        </h2>
                        
                        <?php if (empty($availability_summary)): ?>
                            <div class="text-center p-6 bg-purple-50 rounded-lg">
                                <p class="text-gray-600">Select a specialty to view doctor availability</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($availability_summary as $doctor_id => $doctor): ?>
                                <div class="mb-6 p-6 bg-purple-50 rounded-lg border-l-4 border-purple-500">
                                    <h3 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($doctor['name']) ?></h3>
                                    <p class="text-purple-600 text-sm mb-4"><?= htmlspecialchars($doctor['specialty']) ?></p>
                                    
                                    <h4 class="font-medium text-gray-700 mb-2">Available Hours:</h4>
                                    <ul class="text-sm space-y-2 mb-4">
                                        <?php foreach ($doctor['schedule'] as $schedule): ?>
                                            <li class="flex items-start">
                                                <span class="font-medium w-24"><?= $schedule['day'] ?>:</span>
                                                <span><?= $schedule['hours'] ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    
                                    <div class="mt-4 text-center">
                                        <a href="?specialty=<?= $selected_specialty ?>&doctor=<?= $doctor_id ?>&date=<?= $selected_date ?>" class="bg-gradient-to-r from-purple-600 to-blue-500 text-white px-5 py-2 rounded-full action-btn inline-block">
                                            <i class="fas fa-calendar-plus mr-1"></i>Book with this Doctor
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div class="mt-6 p-6 bg-blue-50 rounded-lg border-l-4 border-blue-500">
                            <h3 class="font-bold text-blue-800 mb-3"><i class="fas fa-info-circle mr-2"></i>Booking Information</h3>
                            <ul class="text-sm text-blue-700 space-y-3">
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle mr-2 mt-1 text-blue-500"></i>
                                    <span>Appointments are 30 minutes long</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle mr-2 mt-1 text-blue-500"></i>
                                    <span>Please arrive 15 minutes before your appointment</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle mr-2 mt-1 text-blue-500"></i>
                                    <span>Bring your insurance card and ID</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle mr-2 mt-1 text-blue-500"></i>
                                    <span>You can cancel up to 24 hours before your appointment</span>
                                </li>
                            </ul>
                        </div>
                    </div>
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
</body>
</html>