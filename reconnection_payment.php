<?php
session_start();
require_once 'config/dbconnection.php';
require_once 'includes/customer_header.php';
require_once 'includes/classes/admin-class.php';

$admins = new Admins($dbh);

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'employer') {
    header('Location: login.php');
    exit();
}

// Ensure customer ID is present
if (!isset($_GET['customer']) && !isset($_POST['customer'])) {
    header('Location: disconnected_clients.php');
    exit();
}

$customer_id = $_GET['customer'] ?? $_POST['customer'];
$customer = $admins->getDisconnectedCustomerInfo($customer_id);

if (!$customer) {
    header('Location: disconnected_clients.php');
    exit();
}

// Initialize billing variables
$outstanding_balance = 0;
$overdue_consumption = 0;
$total_due = 0;
$monthly_fee = 0;
$days_in_month = 30;
$daily_rate = 0;
$overdue_days = 0;
$due_date_formatted = '';
$today_formatted = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // On form submission, retrieve billing details from session
    if (isset($_SESSION['reconnection_details']) && $_SESSION['reconnection_details']['customer_id'] == $customer_id) {
        $details = $_SESSION['reconnection_details'];
        $outstanding_balance = $details['outstanding_balance'];
        $overdue_consumption = $details['overdue_consumption'];
        $total_due = $details['total_due'];
        $monthly_fee = $details['monthly_fee'];
        $daily_rate = $details['daily_rate'];
        $overdue_days = $details['overdue_days'];
        $due_date_formatted = $details['due_date'];
        $today_formatted = $details['today'];
    } else {
        // If session data is missing, redirect to prevent errors
        $_SESSION['error'] = 'Your session expired. Please try again.';
        header('Location: reconnection_payment.php?customer=' . $customer_id);
        exit();
    }

    $employer_id = $_SESSION['user_id'];
    $amount = (float)$_POST['amount'];
    $reference_number = $_POST['reference_number'];
    $payment_method = $_POST['payment_method'];
    $payment_option = $_POST['payment_option'];
    $screenshot = isset($_FILES['screenshot']) ? $_FILES['screenshot'] : null;
    $payment_date = $_POST['payment_date'];
    $payment_time = $_POST['payment_time'];
    
    // Validate payment amount based on selected option from session data
    $required_amount = 0;
    switch ($payment_option) {
        case 'outstanding':
            $required_amount = $outstanding_balance;
            break;
        case 'overdue':
            $required_amount = $overdue_consumption;
            break;
        case 'both':
            $required_amount = $total_due;
            break;
    }
    
    $tolerance = 0.01; // 1 cent tolerance for floating point
    
    if (abs($amount - $required_amount) > $tolerance) {
        $error_message = "Payment amount must be exactly ₱" . number_format($required_amount, 2) . " for the selected option. You entered: ₱" . number_format($amount, 2);
    } elseif ($amount > ($total_due * 3)) {
        $error_message = "Payment amount seems too high. Please verify the amount.";
    } else {
        // Process payment
        if ($admins->processReconnectionPayment($customer_id, $employer_id, $amount, $reference_number, $payment_method, $screenshot, $payment_date, $payment_time)) {
            unset($_SESSION['reconnection_details']); // Clear session data on success
            $_SESSION['success'] = 'Reconnection request submitted successfully and is pending approval.';
            header('Location: disconnected_clients.php');
            exit();
        } else {
            $error_message = "Failed to process reconnection request. Please try again.";
        }
    }
} else {
    // On page load, calculate and store billing details in session
    $package = $admins->getPackageInfo($customer->package_id);
    $monthly_fee = $package ? (float)$package->fee : 0;

    $due_date = new DateTime($customer->due_date);
    $today = new DateTime();
    $due_date_formatted = $due_date->format('M j, Y');
    $today_formatted = $today->format('M j, Y');

    if ($due_date > $today) {
        $overdue_days = 0;
    } else {
        $interval = $due_date->diff($today);
        $overdue_days = $interval->days;
    }

    $daily_rate = $monthly_fee / $days_in_month;
    $overdue_consumption = $overdue_days * $daily_rate;

    // Get outstanding balance from disconnected_payments table
    $request = $dbh->prepare("
        SELECT COALESCE(SUM(balance), 0) as total_outstanding 
        FROM disconnected_payments 
        WHERE customer_id = ? AND status = 'Unpaid'
    ");
    if ($request->execute([$customer->original_id])) {
        $result = $request->fetch();
        $outstanding_balance = (float)$result->total_outstanding;
    }

    if ($outstanding_balance == 0 && isset($customer->balance)) {
        $outstanding_balance = (float)$customer->balance;
    }

    $total_due = $outstanding_balance + $overdue_consumption;

    // Store details in session
    $_SESSION['reconnection_details'] = [
        'customer_id' => $customer_id,
        'outstanding_balance' => $outstanding_balance,
        'overdue_consumption' => $overdue_consumption,
        'total_due' => $total_due,
        'monthly_fee' => $monthly_fee,
        'daily_rate' => $daily_rate,
        'overdue_days' => $overdue_days,
        'due_date' => $due_date_formatted,
        'today' => $today_formatted,
    ];
}

// Default payment option for display
$payment_option = $_POST['payment_option'] ?? 'both';
$calculated_amount = $total_due;

// When the page is reloaded with an error, set the calculated amount to what the user entered
if (isset($error_message) && isset($_POST['amount'])) {
    $calculated_amount = (float)$_POST['amount'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reconnection Payment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/reconnection.css"> <!-- Custom CSS for this page -->
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #06d6a0;
            --danger: #ef476f;
            --warning: #ffd166;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            color: var(--dark);
            min-height: 100vh;
        }
        
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-bottom: none;
            padding: 1.5rem 2rem;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .customer-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .customer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            margin-right: 1rem;
        }
        
        .customer-info h4 {
            margin: 0;
            font-weight: 600;
        }
        
        .customer-info p {
            margin: 0;
            color: var(--gray);
        }
        
        .breakdown-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary);
        }
        
        .total-due-card {
            background: linear-gradient(135deg, #e7f3ff 0%, #d4e7ff 100%);
            border: 2px solid var(--primary);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .calculation-steps {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .step-item {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .step-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .step-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: var(--primary);
            font-weight: bold;
        }
        
        .step-content {
            flex: 1;
        }
        
        .payment-option-card {
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .payment-option-card:hover {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .payment-option-card.selected {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.1);
        }
        
        .payment-option-card input[type="radio"] {
            margin-right: 1rem;
            transform: scale(1.2);
        }
        
        .payment-option-content {
            flex: 1;
        }
        
        .payment-option-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .payment-option-description {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .payment-option-amount {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .form-control {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
            border-color: var(--primary);
        }
        
        .btn {
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border: none;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #651a98 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .btn-secondary {
            background: var(--light);
            color: var(--dark);
            border: 1px solid var(--light-gray);
        }
        
        .btn-secondary:hover {
            background: var(--light-gray);
            color: var(--dark);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem 1.5rem;
        }
        
        .alert-danger {
            background: rgba(239, 71, 111, 0.1);
            color: var(--danger);
        }
        
        .alert-success {
            background: rgba(6, 214, 160, 0.1);
            color: #06a17a;
        }
        
        .file-upload-area {
            border: 2px dashed var(--light-gray);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
            background: var(--light);
        }
        
        .file-upload-area:hover {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }
        
        .file-upload-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        .amount-display {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .is-invalid {
            border-color: var(--danger) !important;
        }
        
        .invalid-feedback {
            display: block;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.875em;
            color: var(--danger);
        }
        
        .section-title {
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--light-gray);
            color: var(--dark);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .card-body {
                padding: 1.5rem;
            }
            
            .customer-header {
                flex-direction: column;
                text-align: center;
            }
            
            .customer-avatar {
                margin-right: 0;
                margin-bottom: 1rem;
            }
            
            .payment-option-card {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .payment-option-amount {
                margin-top: 0.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .card-body {
                padding: 1rem;
            }
            
            .step-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .step-icon {
                margin-right: 0;
                margin-bottom: 0.5rem;
            }
        }
        
        /* Smart TV optimizations */
        @media (min-width: 1920px) {
            .main-container {
                max-width: 1400px;
            }
            
            .card-body {
                padding: 3rem;
            }
            
            .form-control, .btn {
                padding: 1rem 1.5rem;
                font-size: 1.1rem;
            }
            
            .section-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
<main class="cd-main-content">
<div class="container main-container py-4">
    <div class="row justify-content-center">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-plug me-2"></i>Reconnection Payment</h3>
                </div>
                <div class="card-body">
                    <!-- Customer Info -->
                    <div class="customer-header">
                        <div class="customer-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="customer-info">
                            <h4><?php echo htmlspecialchars($customer->full_name); ?></h4>
                            <p>Customer ID: <?php echo htmlspecialchars($customer_id); ?></p>
                        </div>
                    </div>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Payment Summary -->
                    <div class="total-due-card">
                        <h4 class="mb-2">Total Amount Due</h4>
                        <div class="amount-display">₱<?php echo number_format($total_due, 2); ?></div>
                        <p class="mb-0 mt-2">Pay this amount to restore your service</p>
                    </div>

                    <!-- Calculation Breakdown -->
                    <div class="calculation-steps">
                        <h5 class="section-title"><i class="fas fa-calculator me-2"></i>Payment Calculation Breakdown</h5>
                        
                        <div class="step-item">
                            <div class="step-icon">1</div>
                            <div class="step-content">
                                <div class="d-flex justify-content-between">
                                    <span>Monthly Package Fee:</span>
                                    <strong>₱<?php echo number_format($monthly_fee, 2); ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-item">
                            <div class="step-icon">2</div>
                            <div class="step-content">
                                <div class="d-flex justify-content-between">
                                    <span>Daily Rate Calculation:</span>
                                    <span>₱<?php echo number_format($monthly_fee, 2); ?> ÷ <?php echo $days_in_month; ?> days = <strong>₱<?php echo number_format($daily_rate, 2); ?>/day</strong></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-item">
                            <div class="step-icon">3</div>
                            <div class="step-content">
                                <div class="d-flex justify-content-between">
                                    <span>Overdue Period:</span>
                                    <span><?php echo $due_date_formatted; ?> to <?php echo $today_formatted; ?> <strong>(<?php echo $overdue_days; ?> days)</strong></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-item">
                            <div class="step-icon">4</div>
                            <div class="step-content">
                                <div class="d-flex justify-content-between">
                                    <span>Overdue Consumption:</span>
                                    <span><?php echo $overdue_days; ?> days × ₱<?php echo number_format($daily_rate, 2); ?>/day = <strong>₱<?php echo number_format($overdue_consumption, 2); ?></strong></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Breakdown -->
                    <div class="breakdown-card">
                        <h5 class="section-title"><i class="fas fa-receipt me-2"></i>Payment Breakdown</h5>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span>Outstanding Balance:</span>
                            <strong>₱<?php echo number_format($outstanding_balance, 2); ?></strong>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span>Overdue Consumption:</span>
                            <strong>₱<?php echo number_format($overdue_consumption, 2); ?></strong>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><strong>Total Amount Due:</strong></h5>
                            <h5 class="mb-0"><strong>₱<?php echo number_format($total_due, 2); ?></strong></h5>
                        </div>
                    </div>

                    <!-- Payment Options -->
                    <form action="reconnection_payment.php" method="POST" enctype="multipart/form-data" id="paymentForm">
                        <div class="mb-4">
                            <h5 class="section-title"><i class="fas fa-credit-card me-2"></i>Select Payment Option</h5>
                            
                            <div class="payment-option-card <?php echo $payment_option === 'outstanding' ? 'selected' : ''; ?>" onclick="selectPaymentOption('outstanding')">
                                <input type="radio" name="payment_option" value="outstanding" <?php echo $payment_option === 'outstanding' ? 'checked' : ''; ?>>
                                <div class="payment-option-content">
                                    <div class="payment-option-title">Outstanding Balance Only</div>
                                    <div class="payment-option-description">Pay only the remaining balance from previous bills</div>
                                </div>
                                <div class="payment-option-amount">₱<?php echo number_format($outstanding_balance, 2); ?></div>
                            </div>
                            
                            <div class="payment-option-card <?php echo $payment_option === 'overdue' ? 'selected' : ''; ?>" onclick="selectPaymentOption('overdue')">
                                <input type="radio" name="payment_option" value="overdue" <?php echo $payment_option === 'overdue' ? 'checked' : ''; ?>>
                                <div class="payment-option-content">
                                    <div class="payment-option-title">Overdue Consumption Only</div>
                                    <div class="payment-option-description">Pay only for the overdue period consumption</div>
                                </div>
                                <div class="payment-option-amount">₱<?php echo number_format($overdue_consumption, 2); ?></div>
                            </div>
                            
                            <div class="payment-option-card <?php echo $payment_option === 'both' ? 'selected' : ''; ?>" onclick="selectPaymentOption('both')">
                                <input type="radio" name="payment_option" value="both" <?php echo $payment_option === 'both' ? 'checked' : ''; ?>>
                                <div class="payment-option-content">
                                    <div class="payment-option-title">Both (Outstanding Balance + Overdue Consumption)</div>
                                    <div class="payment-option-description">Pay both outstanding balance and overdue consumption</div>
                                </div>
                                <div class="payment-option-amount">₱<?php echo number_format($total_due, 2); ?></div>
                            </div>
                        </div>

                        <!-- Payment Form -->
                        <input type="hidden" name="customer" value="<?php echo $customer_id; ?>">

                        <h5 class="section-title"><i class="fas fa-money-bill-wave me-2"></i>Payment Details</h5>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="amount" class="form-label">Payment Amount *</label>
                                    <input type="number" name="amount" id="amount" class="form-control" 
                                           step="0.01" min="0" 
                                           value="<?php echo number_format($calculated_amount, 2, '.', ''); ?>" required>
                                    <div class="form-text">
                                        <small>
                                            <strong id="min-amount-text">Required: ₱<?php echo number_format($total_due, 2); ?></strong>
                                        </small>
                                    </div>
                                    <div class="invalid-feedback" id="amount-error"></div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="payment_method" class="form-label">Payment Method *</label>
                                    <select name="payment_method" id="payment_method" class="form-control" required>
                                        <option value="">Select Payment Method</option>
                                        <option value="GCash">GCash</option>
                                        <option value="PayMaya">PayMaya</option>
                                        <option value="Coins.ph">Coins.ph</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="Cash">Cash</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="payment_date" class="form-label">Payment Date *</label>
                                    <input type="date" name="payment_date" id="payment_date" 
                                           class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="payment_time" class="form-label">Payment Time *</label>
                                    <input type="time" name="payment_time" id="payment_time" 
                                           class="form-control" value="<?php echo date('H:i'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="reference_number" class="form-label">Reference Number *</label>
                                    <input type="text" name="reference_number" id="reference_number" 
                                           class="form-control" placeholder="Enter transaction reference" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Transaction Proof (Screenshot/Receipt)</label>
                            <div class="file-upload-area" onclick="document.getElementById('screenshot').click()">
                                <div class="file-upload-icon">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>
                                <h5>Upload Proof of Payment</h5>
                                <p class="text-muted">Click to upload screenshot of transaction or receipt photo</p>
                                <input type="file" name="screenshot" id="screenshot" class="d-none" 
                                       accept="image/*,.pdf">
                            </div>
                            <div id="file-name" class="mt-2 text-center"></div>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                            <a href="disconnected_clients.php" class="btn btn-secondary me-md-2">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-2"></i>Submit Reconnection Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// PHP variables passed to JavaScript
const outstandingBalance = <?php echo $outstanding_balance; ?>;
const overdueConsumption = <?php echo $overdue_consumption; ?>;
const totalDue = <?php echo $total_due; ?>;

document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('amount');
    const paymentForm = document.getElementById('paymentForm');
    const minAmountText = document.getElementById('min-amount-text');
    const amountError = document.getElementById('amount-error');
    const paymentOptions = document.querySelectorAll('input[name="payment_option"]');
    const screenshotInput = document.getElementById('screenshot');
    const fileNameDisplay = document.getElementById('file-name');
    
    // File upload display
    screenshotInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            fileNameDisplay.innerHTML = `<span class="badge bg-primary"><i class="fas fa-file me-1"></i> ${this.files[0].name}</span>`;
        } else {
            fileNameDisplay.innerHTML = '';
        }
    });
    
    function updateRequiredAmountText(amount) {
        minAmountText.textContent = `Required: ₱${amount.toFixed(2)}`;
    }

    function selectPaymentOption(option) {
        document.querySelectorAll('.payment-option-card').forEach(card => {
            card.classList.remove('selected');
        });
        
        const selectedCard = document.querySelector(`.payment-option-card input[value="${option}"]`).closest('.payment-option-card');
        selectedCard.classList.add('selected');
        
        document.querySelector(`input[name="payment_option"][value="${option}"]`).checked = true;
        
        let newAmount = 0;
        switch (option) {
            case 'outstanding':
                newAmount = outstandingBalance;
                break;
            case 'overdue':
                newAmount = overdueConsumption;
                break;
            case 'both':
                newAmount = totalDue;
                break;
        }
        
        amountInput.value = newAmount.toFixed(2);
        updateRequiredAmountText(newAmount);
        validateAmount();
    }

    function validateAmount() {
        const enteredAmount = parseFloat(amountInput.value) || 0;
        const selectedOption = document.querySelector('input[name="payment_option"]:checked').value;
        
        let requiredAmount = 0;
        switch (selectedOption) {
            case 'outstanding':
                requiredAmount = outstandingBalance;
                break;
            case 'overdue':
                requiredAmount = overdueConsumption;
                break;
            case 'both':
                requiredAmount = totalDue;
                break;
        }
        
        const tolerance = 0.01;
        
        if (Math.abs(enteredAmount - requiredAmount) > tolerance) {
            amountError.textContent = `Payment must be exactly ₱${requiredAmount.toFixed(2)} for this option.`;
            amountInput.classList.add('is-invalid');
            return false;
        } else {
            amountError.textContent = '';
            amountInput.classList.remove('is-invalid');
            return true;
        }
    }

    amountInput.addEventListener('input', validateAmount);
    
    paymentOptions.forEach(option => {
        option.addEventListener('change', function() {
            selectPaymentOption(this.value);
        });
    });

    paymentForm.addEventListener('submit', function(e) {
        if (!validateAmount()) {
            e.preventDefault();
            amountInput.focus();
            alert('Please correct the payment amount before submitting.');
        } else if (!confirm('Are you sure you want to submit this reconnection request?')) {
            e.preventDefault();
        }
    });

    // Initialize with the current or default payment option
    const initialOption = document.querySelector('input[name="payment_option"]:checked').value;
    selectPaymentOption(initialOption);
});
</script>
</body>
</html>