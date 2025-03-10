<?php
session_start();
require_once 'connection.php';

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=Please+login+to+continue");
    exit();
}

// Check if invoice ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: invoices.php?error=Invalid+invoice+ID");
    exit();
}

$invoice_id = (int)$_GET['id'];

// Get invoice details with related patient, doctor and appointment information
$invoice_query = "
    SELECT 
        i.invoice_id, 
        i.amount, 
        i.currency,
        i.payment_status,
        i.created_at,
        p.patient_id,
        p.first_name as patient_first_name, 
        p.last_name as patient_last_name,
        p.phone as patient_phone,
        p.address as patient_address,
        a.appointment_id,
        a.appointment_date,
        a.time_slot as appointment_time,
        a.notes,
        d.first_name as doctor_first_name,
        d.last_name as doctor_last_name,
        s.speciality_name as specialty
    FROM invoices i
    JOIN patients p ON i.patient_id = p.patient_id
    JOIN appointments a ON i.appointment_id = a.appointment_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN specialties s ON d.speciality_id = s.speciality_id
    WHERE i.invoice_id = ?
";

$stmt = $conn->prepare($invoice_query);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: invoices.php?error=Invoice+not+found");
    exit();
}

$invoice = $result->fetch_assoc();

// Get user email from users table based on patient's user_id
$email_query = "
    SELECT u.email
    FROM users u
    JOIN patients p ON u.user_id = p.user_id
    WHERE p.patient_id = ?
";

$stmt = $conn->prepare($email_query);
$stmt->bind_param("i", $invoice['patient_id']);
$stmt->execute();
$email_result = $stmt->get_result();
$user_data = $email_result->fetch_assoc();

// Add email to invoice data
$invoice['patient_email'] = $user_data['email'] ?? 'N/A';

// Get clinic information
$clinic_info = [
    'name' => 'Clinic Management System',
    'address' => 'UAE, DUBAI',
    'phone' => '+971 0000000000',
    'email' => 'info@clinicsystem.com',
    'website' => 'www.clinicsystem.com',
    'logo' => 'assets/img/logo.png'
];

// Format currency symbol
$currency_symbol = $invoice['currency'] === 'USD' ? '$' : '€';

// Calculate invoice due date (14 days from creation)
$created_date = new DateTime($invoice['created_at']);
$due_date = clone $created_date;
$due_date->modify('+14 days');

// Generate invoice number with prefix and leading zeros
$formatted_invoice_id = 'INV-' . str_pad($invoice['invoice_id'], 6, '0', STR_PAD_LEFT);

// Calculate tax (assuming 10% tax)
$tax_rate = 0.10;
$tax_amount = $invoice['amount'] * $tax_rate;
$subtotal = $invoice['amount'] - $tax_amount;

// Format dates
$invoice_date = date('F d, Y', strtotime($invoice['created_at']));
$formatted_due_date = $due_date->format('F d, Y');
$appointment_date = date('F d, Y', strtotime($invoice['appointment_date']));
$appointment_time = date('h:i A', strtotime($invoice['appointment_time']));

// Status styling
$status_color = '';
switch($invoice['payment_status']) {
    case 'paid':
        $status_color = '#28a745';
        break;
    case 'pending':
        $status_color = '#ffc107';
        break;
    case 'cancelled':
        $status_color = '#dc3545';
        break;
}

// Generate a random transaction ID for paid invoices
$transaction_id = '';
if ($invoice['payment_status'] === 'paid') {
    $transaction_id = strtoupper(substr(md5(uniqid(rand(), true)), 0, 10));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= $invoice['invoice_id'] ?> - <?= $clinic_info['name'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print {
                display: none !important;
            }
            .print-container {
                max-width: 100%;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
            .page-break {
                page-break-after: always;
            }
        }
        .invoice-header {
            border-bottom: 2px solid #e2e8f0;
        }
        .invoice-footer {
            border-top: 2px solid #e2e8f0;
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
            text-transform: uppercase;
            display: inline-block;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Print Controls -->
    <div class="no-print bg-white py-4 px-6 shadow-md fixed top-0 w-full z-10 flex justify-between items-center">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Invoice #<?= $invoice['invoice_id'] ?></h1>
        </div>
        <div class="flex space-x-2">
            <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 transition">
                <i class="fas fa-print mr-2"></i> Print Invoice
            </button>
            <button onclick="window.location.href='invoices.php'" class="bg-gray-600 text-white px-4 py-2 rounded shadow hover:bg-gray-700 transition">
                <i class="fas fa-arrow-left mr-2"></i> Back to Invoices
            </button>
        </div>
    </div>
    
    <!-- Print Margin Spacer -->
    <div class="no-print h-20"></div>
    
    <!-- Invoice Content -->
    <div class="max-w-4xl mx-auto my-10 bg-white shadow-lg rounded-lg overflow-hidden print-container">
        <!-- Invoice Header -->
        <div class="p-8 invoice-header">
            <div class="flex justify-between items-start">
                <!-- Clinic Info -->
                <div>
                    <h1 class="text-2xl font-bold text-gray-800"><?= $clinic_info['name'] ?></h1>
                    <p class="text-gray-600"><?= $clinic_info['address'] ?></p>
                    <p class="text-gray-600"><?= $clinic_info['phone'] ?></p>
                    <p class="text-gray-600"><?= $clinic_info['email'] ?></p>
                    <p class="text-gray-600"><?= $clinic_info['website'] ?></p>
                </div>
                
                <!-- Invoice Info -->
                <div class="text-right">
                    <h2 class="text-3xl font-bold text-gray-800 mb-2">INVOICE</h2>
                    <p class="text-xl font-semibold text-gray-700"><?= $formatted_invoice_id ?></p>
                    <div class="mt-4">
                        <span class="status-badge" style="background-color: <?= $status_color ?>; color: white;">
                            <?= strtoupper($invoice['payment_status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bill To / Invoice Details -->
        <div class="p-8 bg-gray-50">
            <div class="flex justify-between">
                <!-- Bill To -->
                <div>
                    <h3 class="text-gray-800 font-semibold mb-2">BILL TO:</h3>
                    <p class="font-bold text-gray-800"><?= htmlspecialchars($invoice['patient_first_name'] . ' ' . $invoice['patient_last_name']) ?></p>
                    <p class="text-gray-600"><?= htmlspecialchars($invoice['patient_address'] ?? 'N/A') ?></p>
                    <p class="text-gray-600">Email: <?= htmlspecialchars($invoice['patient_email'] ?? 'N/A') ?></p>
                    <p class="text-gray-600">Phone: <?= htmlspecialchars($invoice['patient_phone'] ?? 'N/A') ?></p>
                </div>
                
                <!-- Invoice Details -->
                <div class="text-right">
                    <div class="mb-2">
                        <span class="text-gray-600">Invoice Date:</span>
                        <span class="font-semibold text-gray-800 ml-2"><?= $invoice_date ?></span>
                    </div>
                    <div class="mb-2">
                        <span class="text-gray-600">Due Date:</span>
                        <span class="font-semibold text-gray-800 ml-2"><?= $formatted_due_date ?></span>
                    </div>
                    <?php if ($invoice['payment_status'] === 'paid'): ?>
                    <div class="mb-2">
                        <span class="text-gray-600">Payment Date:</span>
                        <span class="font-semibold text-gray-800 ml-2"><?= $invoice_date ?></span>
                    </div>
                    <div>
                        <span class="text-gray-600">Transaction ID:</span>
                        <span class="font-semibold text-gray-800 ml-2"><?= $transaction_id ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Invoice Details -->
        <div class="p-8">
            <h3 class="text-xl font-bold text-gray-800 mb-4">Service Details</h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-100 text-left">
                            <th class="py-3 px-4 text-gray-700 font-semibold">Description</th>
                            <th class="py-3 px-4 text-gray-700 font-semibold">Date</th>
                            <th class="py-3 px-4 text-gray-700 font-semibold">Doctor</th>
                            <th class="py-3 px-4 text-gray-700 font-semibold text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr>
                            <td class="py-4 px-4">
                                <p class="font-medium text-gray-800">Medical Consultation</p>
                                <p class="text-gray-600 text-sm"><?= htmlspecialchars($invoice['notes'] ?? 'General consultation') ?></p>
                            </td>
                            <td class="py-4 px-4 text-gray-700">
                                <?= $appointment_date ?><br>
                                <span class="text-sm text-gray-500"><?= $appointment_time ?></span>
                            </td>
                            <td class="py-4 px-4 text-gray-700">
                                Dr. <?= htmlspecialchars($invoice['doctor_first_name'] . ' ' . $invoice['doctor_last_name']) ?><br>
                                <span class="text-sm text-gray-500"><?= htmlspecialchars($invoice['specialty'] ?? 'Specialist') ?></span>
                            </td>
                            <td class="py-4 px-4 text-right text-gray-700 font-medium">
                                <?= $currency_symbol ?><?= number_format($subtotal, 2) ?>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="py-3 px-4 text-right font-medium text-gray-700">Subtotal</td>
                            <td class="py-3 px-4 text-right font-medium text-gray-700"><?= $currency_symbol ?><?= number_format($subtotal, 2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="py-3 px-4 text-right font-medium text-gray-700">Tax (10%)</td>
                            <td class="py-3 px-4 text-right font-medium text-gray-700"><?= $currency_symbol ?><?= number_format($tax_amount, 2) ?></td>
                        </tr>
                        <tr class="bg-gray-50">
                            <td colspan="3" class="py-3 px-4 text-right font-bold text-gray-800">Total</td>
                            <td class="py-3 px-4 text-right font-bold text-gray-800 text-lg"><?= $currency_symbol ?><?= number_format($invoice['amount'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <!-- Payment Information -->
        <div class="p-8 bg-gray-50">
            <h3 class="text-xl font-bold text-gray-800 mb-4">Payment Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Payment Methods -->
                <div>
                    <h4 class="font-semibold text-gray-700 mb-2">Payment Methods</h4>
                    <p class="text-gray-600 mb-2">We accept the following payment methods:</p>
                    <div class="flex space-x-2 text-2xl text-gray-600 mb-4">
                        <i class="fab fa-cc-visa"></i>
                        <i class="fab fa-cc-mastercard"></i>
                        <i class="fab fa-cc-amex"></i>
                        <i class="fab fa-cc-paypal"></i>
                    </div>
                    
                    <h4 class="font-semibold text-gray-700 mb-2">Bank Details</h4>
                    <p class="text-gray-600">Bank: Medical City Bank</p>
                    <p class="text-gray-600">Account Name: Clinic Management System</p>
                    <p class="text-gray-600">Account Number: 1234567890</p>
                    <p class="text-gray-600">Routing Number: 987654321</p>
                </div>
                
                <!-- Terms & Notes -->
                <div>
                    <h4 class="font-semibold text-gray-700 mb-2">Terms & Conditions</h4>
                    <ul class="list-disc list-inside text-gray-600 space-y-1 mb-4">
                        <li>Payment is due within 14 days from invoice date</li>
                        <li>Late payments may incur a 5% late fee</li>
                        <li>Please include invoice number in payment reference</li>
                    </ul>
                    
                    <h4 class="font-semibold text-gray-700 mb-2">Notes</h4>
                    <p class="text-gray-600">Thank you for choosing our clinic. If you have any questions about this invoice, please contact our billing department at billing@clinicsystem.com</p>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="p-8 text-center text-gray-600 text-sm invoice-footer">
            <p>&copy; <?= date('Y') ?> <?= $clinic_info['name'] ?> - All Rights Reserved.</p>
            <p class="mt-1">This is a computer-generated invoice. No signature required.</p>
        </div>
    </div>
    
    <!-- Print Margin Spacer -->
    <div class="no-print h-20"></div>
</body>
</html>