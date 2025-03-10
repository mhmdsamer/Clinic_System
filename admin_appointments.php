<?php
session_start();
require_once 'connection.php';

// Ensure only admins can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login_process.php?error=Unauthorized+Access");
    exit();
}

// Initialize variables
$message = '';
$error = '';

// Process appointment booking form
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['book_appointment'])) {
    $patient_id = $_POST['patient_id'];
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

// Fetch all patients
$patients_query = "
    SELECT p.patient_id, p.first_name, p.last_name, u.email
    FROM patients p
    JOIN users u ON p.user_id = u.user_id
    ORDER BY p.last_name, p.first_name
";
$patients_result = $conn->query($patients_query);

// Fetch all specialties
$specialties_query = "SELECT * FROM specialties ORDER BY speciality_name";
$specialties_result = $conn->query($specialties_query);

// Default variables
$selected_patient = $_GET['patient'] ?? 0;
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

// Fetch all appointments for the admin view
$appointments_query = "
    SELECT 
        a.appointment_id, 
        a.appointment_date, 
        a.time_slot, 
        a.status,
        a.notes,
        p.first_name as patient_first_name, 
        p.last_name as patient_last_name,
        d.first_name as doctor_first_name, 
        d.last_name as doctor_last_name, 
        s.speciality_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN specialties s ON d.speciality_id = s.speciality_id
    WHERE a.status = 'scheduled' AND a.appointment_date >= CURDATE()
    ORDER BY a.appointment_date, a.time_slot
    LIMIT 10
";
$appointments_result = $conn->query($appointments_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Appointment Management - Clinic Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZJVS/gpW6BzT9YyIqzZTY5sXl/sA1rZVfNWJ5D4IbRSxIk2XYsM36X6gKDe5" crossorigin="anonymous">

    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #3f51b5 0%, #1a237e 100%);
        }
        .card-hover:hover {
            transform: scale(1.02);
            box-shadow: 0 10px 20px rgba(0,0,0,0.12);
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen gradient-bg">
        <nav class="bg-white shadow-md">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center">
                        <a href="admin_dashboard.php" class="text-2xl font-bold text-indigo-600">
                            <i class="fas fa-clinic-medical mr-2"></i>CMS Admin Portal
                        </a>
                    </div>
                    <div class="flex items-center space-x-4">
                        <a href="admin_dashboard.php" class="text-gray-700 hover:text-indigo-600">
                            <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                        </a>
                        <a href="logout.php" class="bg-red-500 text-white px-4 py-2 rounded-lg hover:bg-red-600 transition">
                            <i class="fas fa-sign-out-alt mr-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <main class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row md:space-x-6">
                <!-- Main content -->
                <div class="md:w-2/3">
                    <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
                        <h1 class="text-2xl font-bold text-gray-800 mb-6">
                            <i class="fas fa-calendar-plus mr-3 text-indigo-600"></i>Admin Appointment Booking
                        </h1>
                        
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
                        
                        <form method="get" action="" class="mb-8">
                            <div class="grid md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="patient" class="block text-gray-700 font-medium mb-2">Select Patient</label>
                                    <select name="patient" id="patient" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="this.form.submit()">
                                        <option value="0">Select a Patient</option>
                                        <?php while ($patient = $patients_result->fetch_assoc()): ?>
                                            <option value="<?= $patient['patient_id'] ?>" <?= $selected_patient == $patient['patient_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?> (<?= htmlspecialchars($patient['email']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="specialty" class="block text-gray-700 font-medium mb-2">Select Specialty</label>
                                    <select name="specialty" id="specialty" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="this.form.submit()">
                                        <option value="0">All Specialties</option>
                                        <?php 
                                        // Reset pointer to beginning of result set
                                        $specialties_result->data_seek(0);
                                        while ($specialty = $specialties_result->fetch_assoc()): 
                                        ?>
                                            <option value="<?= $specialty['speciality_id'] ?>" <?= $selected_specialty == $specialty['speciality_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($specialty['speciality_name']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="doctor" class="block text-gray-700 font-medium mb-2">Select Doctor</label>
                                    <select name="doctor" id="doctor" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="this.form.submit()">
                                        <option value="0">Select a Doctor</option>
                                        <?php while ($doctor = $doctors_result->fetch_assoc()): ?>
                                            <option value="<?= $doctor['doctor_id'] ?>" <?= $selected_doctor == $doctor['doctor_id'] ? 'selected' : '' ?>>
                                                Dr. <?= htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']) ?> (<?= htmlspecialchars($doctor['speciality_name']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="date" class="block text-gray-700 font-medium mb-2">Select Date</label>
                                    <input type="date" name="date" id="date" min="<?= date('Y-m-d') ?>" value="<?= $selected_date ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="this.form.submit()">
                                </div>
                            </div>
                        </form>
                        
                        <?php if ($selected_patient > 0 && $selected_doctor > 0 && !empty($selected_date)): ?>
                            <?php if (empty($available_slots)): ?>
                                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4" role="alert">
                                    <p>No available time slots for the selected doctor on this date.</p>
                                </div>
                            <?php else: ?>
                                <form method="post" action="" class="border-t pt-6">
                                    <input type="hidden" name="patient_id" value="<?= $selected_patient ?>">
                                    <input type="hidden" name="doctor_id" value="<?= $selected_doctor ?>">
                                    <input type="hidden" name="appointment_date" value="<?= $selected_date ?>">
                                    
                                    <div class="mb-4">
                                        <label class="block text-gray-700 font-medium mb-2">Available Time Slots</label>
                                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                            <?php foreach ($available_slots as $slot): ?>
                                                <label class="flex items-center p-3 border rounded-lg hover:bg-indigo-50 cursor-pointer">
                                                    <input type="radio" name="time_slot" value="<?= $slot['time'] ?>" class="mr-2 text-indigo-600" required>
                                                    <span><?= $slot['formatted_time'] ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="notes" class="block text-gray-700 font-medium mb-2">Notes (Optional)</label>
                                        <textarea name="notes" id="notes" rows="3" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Add any notes about this appointment"></textarea>
                                    </div>
                                    
                                    <div class="text-right">
                                        <button type="submit" name="book_appointment" class="bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700 transition transform hover:scale-105">
                                            <i class="fas fa-calendar-check mr-2"></i>Book Appointment
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($selected_patient > 0 && $selected_doctor > 0): ?>
                            <div class="text-center p-6 bg-gray-50 rounded-lg">
                                <p class="text-gray-600">Please select a date to view available time slots.</p>
                            </div>
                        <?php elseif ($selected_patient > 0 && !empty($selected_date)): ?>
                            <div class="text-center p-6 bg-gray-50 rounded-lg">
                                <p class="text-gray-600">Please select a doctor to view available time slots.</p>
                            </div>
                        <?php elseif ($selected_doctor > 0 && !empty($selected_date)): ?>
                            <div class="text-center p-6 bg-gray-50 rounded-lg">
                                <p class="text-gray-600">Please select a patient to book an appointment.</p>
                            </div>
                        <?php else: ?>
                            <div class="text-center p-6 bg-gray-50 rounded-lg">
                                <p class="text-gray-600">Please select a patient, doctor, and date to book an appointment.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recent Appointments -->
                    <div class="bg-white rounded-lg shadow-lg p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-bold text-gray-800">
                                <i class="fas fa-calendar-alt mr-2 text-indigo-600"></i>Recent Appointments
                            </h2>
                            <a href="admin_all_appointments.php" class="text-indigo-600 hover:text-indigo-800">
                                View All <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                        
                        <?php if ($appointments_result->num_rows > 0): ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Patient</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Doctor</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Specialty</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php while ($appointment = $appointments_result->fetch_assoc()): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($appointment['patient_first_name'] . ' ' . $appointment['patient_last_name']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    Dr. <?= htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= date('M d, Y', strtotime($appointment['appointment_date'])) ?> at
                                                    <?= date('h:i A', strtotime($appointment['time_slot'])) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= htmlspecialchars($appointment['speciality_name']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                        <?= ucfirst($appointment['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <a href="edit_appointment.php?id=<?= $appointment['appointment_id'] ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="cancel_appointment.php?id=<?= $appointment['appointment_id'] ?>&admin=true" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to cancel this appointment?');">
                                                        <i class="fas fa-times-circle"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center p-6 bg-gray-50 rounded-lg">
                                <p class="text-gray-600">No upcoming appointments scheduled.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                
                    
                    <!-- Doctor Availability Summary -->
                    <?php if ($selected_specialty > 0 && !empty($availability_summary)): ?>
                    <div class="bg-white rounded-lg shadow-lg p-6 sticky top-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">
                            <i class="fas fa-user-md mr-2 text-indigo-600"></i>Doctor Availability
                        </h2>
                        
                        <?php foreach ($availability_summary as $doctor_id => $doctor): ?>
                            <div class="mb-4 p-4 border rounded-lg card-hover bg-gray-50">
                                <h3 class="font-bold text-gray-800"><?= htmlspecialchars($doctor['name']) ?></h3>
                                <p class="text-indigo-600 text-sm mb-2"><?= htmlspecialchars($doctor['specialty']) ?></p>
                                
                                <h4 class="font-medium text-gray-700 mt-3 mb-1">Available Hours:</h4>
                                <ul class="text-sm space-y-1">
                                    <?php foreach ($doctor['schedule'] as $schedule): ?>
                                        <li class="flex items-start">
                                            <span class="font-medium w-24"><?= $schedule['day'] ?>:</span>
                                            <span><?= $schedule['hours'] ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                
                                <div class="mt-3 text-center">
                                    <a href="?patient=<?= $selected_patient ?>&specialty=<?= $selected_specialty ?>&doctor=<?= $doctor_id ?>&date=<?= $selected_date ?>" class="inline-block bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition text-sm">
                                        <i class="fas fa-calendar-plus mr-1"></i>Select this Doctor
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>