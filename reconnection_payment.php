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
    <style>
        .breakdown-card {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        .total-due {
            background: #e7f3ff;
            border: 2px solid #007bff;
            border-radius: 8px;
        }
        .calculation-steps {
            background: #fff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .payment-option-card {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .payment-option-card:hover {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
        .payment-option-card.selected {
            border-color: #007bff;
            background-color: #e7f3ff;
        }
        .payment-option-card input[type="radio"] {
            margin-right: 10px;
        }
        .is-invalid {
            border-color: #dc3545 !important;
        }
        .invalid-feedback {
            display: block;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.875em;
            color: #dc3545;
        }

/* Fix form labels and spacing */
.form-label {
    font-weight: 600;
    margin-bottom: 8px;
    display: block;
    color: #333;
}

/* Ensure form controls have proper spacing */
.form-group {
    margin-bottom: 1.5rem;
}

/* Fix date and time inputs */
input[type="date"],
input[type="time"] {
    min-height: 38px;
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
    width: 100%;
}

/* Improve focus states */
.form-control:focus {
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    border-color: #80bdff;
    outline: 0;
}

/* Make sure all form controls are consistent */
.form-control {
    font-size: 1rem;
    line-height: 1.5;
}

/* Fix the card body spacing */
.card-body {
    padding: 2rem;
}

/* Improve the breakdown card */
.breakdown-card {
    background: #f8f9fa;
    border-left: 4px solid #007bff;
    padding: 1.5rem;
}

/* Payment option cards styling */
.payment-option-card {
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.payment-option-card:hover {
    border-color: #007bff;
    background-color: #f8f9fa;
}

.payment-option-card.selected {
    border-color: #007bff;
    background-color: #e7f3ff;
}

/* Button spacing */
.d-grid {
    gap: 10px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card mt-5">
                <div class="card-header bg-primary text-white">
                    <h3 class="mb-0">Reconnection Payment for <?php echo htmlspecialchars($customer->full_name); ?></h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger"><?php echo $error_message; ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                    <?php endif; ?>

                    <!-- Calculation Breakdown -->
                    <div class="calculation-steps">
                        <h5>Payment Calculation Breakdown:</h5>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>Monthly Package:</span>
                            <strong>₱<?php echo number_format($monthly_fee, 2); ?></strong>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>Daily Rate Calculation:</span>
                            <span>₱<?php echo number_format($monthly_fee, 2); ?> ÷ <?php echo $days_in_month; ?> days = <strong>₱<?php echo number_format($daily_rate, 2); ?>/day</strong></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>Overdue Period:</span>
                            <span><?php echo $due_date_formatted; ?> to <?php echo $today_formatted; ?> <strong>(<?php echo $overdue_days; ?> days)</strong></span>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <span>Overdue Consumption:</span>
                            <span><?php echo $overdue_days; ?> days × ₱<?php echo number_format($daily_rate, 2); ?>/day = <strong>₱<?php echo number_format($overdue_consumption, 2); ?></strong></span>
                        </div>
                    </div>

                    <!-- Payment Summary -->
                    <div class="breakdown-card p-3 mb-4">
                        <div class="d-flex justify-content-between">
                            <span>Outstanding Balance:</span>
                            <strong>₱<?php echo number_format($outstanding_balance, 2); ?></strong>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <span>Overdue Consumption:</span>
                            <strong>₱<?php echo number_format($overdue_consumption, 2); ?></strong>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-between align-items-center total-due p-2">
                            <h5 class="mb-0"><strong>Total Amount Due:</strong></h5>
                            <h5 class="mb-0"><strong>₱<?php echo number_format($total_due, 2); ?></strong></h5>
                        </div>
                    </div>

                    <!-- Payment Options -->
                    <form action="reconnection_payment.php" method="POST" enctype="multipart/form-data" id="paymentForm">
                        <div class="mb-4">
                            <h5>Select Payment Option:</h5>
                            
                            <div class="payment-option-card <?php echo $payment_option === 'outstanding' ? 'selected' : ''; ?>" onclick="selectPaymentOption('outstanding')">
                                <label class="d-block mb-0">
                                    <input type="radio" name="payment_option" value="outstanding" <?php echo $payment_option === 'outstanding' ? 'checked' : ''; ?>>
                                    <strong>Outstanding Balance Only</strong> - ₱<?php echo number_format($outstanding_balance, 2); ?>
                                </label>
                                <small class="text-muted">Pay only the remaining balance from previous bills</small>
                            </div>
                            
                            <div class="payment-option-card <?php echo $payment_option === 'overdue' ? 'selected' : ''; ?>" onclick="selectPaymentOption('overdue')">
                                <label class="d-block mb-0">
                                    <input type="radio" name="payment_option" value="overdue" <?php echo $payment_option === 'overdue' ? 'checked' : ''; ?>>
                                    <strong>Overdue Consumption Only</strong> - ₱<?php echo number_format($overdue_consumption, 2); ?>
                                </label>
                                <small class="text-muted">Pay only for the overdue period consumption</small>
                            </div>
                            
                            <div class="payment-option-card <?php echo $payment_option === 'both' ? 'selected' : ''; ?>" onclick="selectPaymentOption('both')">
                                <label class="d-block mb-0">
                                    <input type="radio" name="payment_option" value="both" <?php echo $payment_option === 'both' ? 'checked' : ''; ?>>
                                    <strong>Both (Outstanding Balance + Overdue Consumption)</strong> - ₱<?php echo number_format($total_due, 2); ?>
                                </label>
                                <small class="text-muted">Pay both outstanding balance and overdue consumption</small>
                            </div>
                        </div>

                        <!-- Payment Form -->
                        <input type="hidden" name="customer" value="<?php echo $customer_id; ?>">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
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
                                <div class="form-group mb-3">
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
                                <div class="form-group mb-3">
                                    <label for="payment_date" class="form-label">Payment Date *</label>
                                    <input type="date" name="payment_date" id="payment_date" 
                                           class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label for="payment_time" class="form-label">Payment Time *</label>
                                    <input type="time" name="payment_time" id="payment_time" 
                                           class="form-control" value="<?php echo date('H:i'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label for="reference_number" class="form-label">Reference Number *</label>
                                    <input type="text" name="reference_number" id="reference_number" 
                                           class="form-control" placeholder="Enter transaction reference" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-4">
                            <label for="screenshot" class="form-label">Transaction Proof (Screenshot/Receipt)</label>
                            <input type="file" name="screenshot" id="screenshot" class="form-control" 
                                   accept="image/*,.pdf">
                            <div class="form-text">
                                <small>Upload proof of payment (screenshot of transaction or receipt photo)</small>
                            </div>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="disconnected_clients.php" class="btn btn-secondary me-md-2">Cancel</a>
                            <button type="submit" class="btn btn-primary">Submit Reconnection Request</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

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
