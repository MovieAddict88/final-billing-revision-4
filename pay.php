<?php
require_once "includes/headx.php";
if (!isset($_SESSION['admin_session'])) {
    $commons->redirectTo(SITE_PATH . 'login.php');
}
require_once "includes/classes/admin-class.php";
$admins = new Admins($dbh);
$id = isset($_GET['customer']) ? $_GET['customer'] : '';
$action = isset($_GET['action']) ? $_GET['action'] : 'pay';
?>
<style>
    body {
        font-family: Arial, sans-serif;
        margin: 40px;
        color: #000;
    }

    .statement-container {
        max-width: 800px;
        margin: auto;
        padding: 20px;
    }

    .header-container {
        text-align: center;
        height: 120px;
        /* Adjust as needed */
        margin-bottom: 20px;
    }

    .header-container .logo {
        display: inline-block;
        vertical-align: middle;
    }

    .header-container .company-info {
        display: inline-block;
        vertical-align: middle;
        text-align: left;
        margin-left: 15px;
        font-size: 12px;
        line-height: 1.4;
    }

    .header-container .company-info strong {
        font-size: 24px;
        font-weight: bold;
    }

    .statement-title {
        text-align: center;
        font-size: 24px;
        font-weight: bold;
        margin: 40px 0;
    }

    .statement-title span {
        border-bottom: 2px solid #000;
        padding-bottom: 5px;
    }

    .customer-details-grid {
        display: grid;
        grid-template-columns: 100px 1fr;
        gap: 5px 10px;
        margin-bottom: 20px;
    }

    .customer-details-grid strong {
        font-weight: bold;
    }

    .account-info {
        margin-bottom: 20px;
    }

    .account-info p {
        margin: 5px 0;
    }

    .account-summary {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .account-summary th,
    .account-summary td {
        border: 1px solid #000;
        padding: 8px;
        text-align: left;
    }

    .account-summary td:nth-child(2) {
        text-align: right;
    }

    .total-due {
        display: flex;
        justify-content: space-between;
        font-size: 18px;
        font-weight: bold;
        margin-top: 20px;
    }

    .total-due .leader {
        flex-grow: 1;
        border-bottom: 2px dotted #000;
        margin: 0 10px;
    }

    .footer-notes {
        margin-top: 40px;
        font-style: italic;
    }

    .highlight {
        background-color: yellow;
        padding: 10px;
        text-align: center;
        font-size: 18px;
    }

    .blue-bg {
        background-color: #007bff !important;
        color: white !important;
    }

    @media print {
        body {
            margin: 0;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .no-print {
            display: none;
        }

        .statement-container {
            width: 100%;
            margin: auto;
            padding: 20px;
            text-align: center;
        }

        .header-container {
            position: relative !important;
            height: 120px !important;
            margin: 0 auto 20px auto;
            display: flex;
            align-items: center;
        }

        .header-container .logo {
            position: relative !important;
            top: auto !important;
            left: auto !important;
            margin-right: 15px;
        }

        .header-container .logo img {
            max-width: 100px !important;
        }

        .header-container .company-info {
            position: relative !important;
            top: auto !important;
            left: auto !important;
            font-size: 12px !important;
            line-height: 1.4 !important;
            white-space: nowrap;
        }

        .customer-details-grid {
            text-align: left;
        }

        .blue-bg {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            background-color: #007bff !important;
            color: white !important;
        }

        .highlight {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            background-color: yellow !important;
            color: black !important;
        }
    }
</style>
<!doctype html>
<html lang="en" class="no-js">

<head>
    <meta charset=" utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href='https://fonts.googleapis.com/css?family=Open+Sans:300,400,700' rel='stylesheet' type='text/css'>
    <link rel="stylesheet" href="component/css/bootstrap.css">
    <link rel="stylesheet" href="component/css/bootstrap-select.min.css">
    <link rel="stylesheet" href="component/css/style.css">
    <link rel="stylesheet" href="component/css/reset.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <script src="component/js/modernizr.js"></script>
    <title>Statement of Account | Cornerstone</title>
</head>

<body>
    <div class="statement-container">
        <?php
        $info = $admins->getCustomerInfo($id);
        if (isset($info) && is_object($info)) {
            $package_id = $info->package_id;
            $packageInfo = $admins->getPackageInfo($package_id);
            $advance_payment = $admins->getAdvancePaymentBalance($id);
        ?>
            <div class="header-container">
                <div class="logo">
                    <img src="component/orig_cs.png" alt="Company Logo" style="max-width: 100px;">
                </div>
                <div class="company-info">
                    <strong>CORNERSTONE INNOVATE TECH SOL</strong><br>
                    #11 Cassa Apartment Mambog IV, Bacoor, Cavite<br>
                    Brix Bryan S. Villas-Prop.<br>
                    NON-VAT Reg. TIN: 434-028-840-000
                </div>
            </div>

            <div class="statement-title"><span>STATEMENT OF ACCOUNT</span></div>

            <div class="no-print" style="text-align: right; margin-bottom: 20px;">
                <button class="btn btn-primary" onclick="window.print();">
                    <i class="fa fa-print"></i> Print
                </button>
            </div>

            <p style="text-align: right;">Date: <?= htmlspecialchars(date("F d, Y")) ?></p>

            <?php
            $has_unpaid = false;
            $bills = $admins->fetchAllIndividualBill($id);
            if (isset($bills) && sizeof($bills) > 0) {
                foreach ($bills as $bill) {
                    if ($bill->status == 'Unpaid') {
                        $has_unpaid = true;
                        break;
                    }
                }
            }
            ?>
            <div class="customer-details-grid">
                <strong>Subject:</strong>
                <?php if ($action != 'bill' && $has_unpaid) : ?>
                    <span style="text-decoration: underline; font-weight: bold;">NOTICE FOR DISCONNECTION</span>
                <?php else : ?>
                    <span>PAYMENT TRANSACTION</span>
                <?php endif; ?>

                <strong>Name:</strong>
                <span><?= htmlspecialchars($info->full_name) ?></span>

                <strong>Address:</strong>
                <span><?= htmlspecialchars($info->address) ?></span>

                <strong>CP #:</strong>
                <span><?= htmlspecialchars($info->contact) ?></span>
            </div>

            <?php if ($action == 'bill') : ?>
                <table class="account-summary">
                    <thead>
                        <tr>
                            <th class="blue-bg">Billing Period(<?= htmlspecialchars($packageInfo->name) ?>)</th>
                            <th class="blue-bg">Amount</th>
                            <th class="blue-bg">Balance</th>
                            <th class="blue-bg">Over</th>
                            <th class="blue-bg">Payment Paid Months</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_balance = 0;
                        $paymentHistory = $admins->fetchAllIndividualBillHistory($id);
                        $paymentLedger = $admins->fetchPaymentHistoryByCustomer($id);

                        $paymentsByBill = [];
                        foreach ($paymentLedger as $ledger) {
                            $paymentsByBill[$ledger->payment_id][] = $ledger;
                        }

                        if (!empty($paymentHistory)) {
                            foreach ($paymentHistory as $bill) {
                                $total_balance += $bill->balance;
                                $g_date = new DateTime($bill->g_date);
                                $start_date = $g_date->format('F d');
                                $end_date = $g_date->modify('+1 month')->format('F d, Y');
                                $billing_period_display = "$start_date - $end_date";

                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($billing_period_display) . "</td>";
                                echo "<td>" . htmlspecialchars(number_format($bill->amount, 2)) . "</td>";
                                echo "<td>" . ($bill->balance > 0 ? htmlspecialchars(number_format($bill->balance, 2)) : '') . "</td>";
                                echo "<td></td>"; // Over column - logic to be added if necessary

                                echo "<td>";
                                if (isset($paymentsByBill[$bill->id])) {
                                    foreach ($paymentsByBill[$bill->id] as $payment) {
                                        echo "#" . htmlspecialchars($payment->reference_number) . "<br>";
                                        echo "Paid ( " . htmlspecialchars(date('F d, Y / h:i A', strtotime($payment->paid_at))) . " ) " . htmlspecialchars($payment->payment_method) . "<br>";
                                    }
                                } else {
                                    echo "NO PAYMENT";
                                }
                                echo "</td>";
                                echo "</tr>";
                            }
                        }
                        ?>
                        <tr>
                            <td colspan="2" style="text-align: right; font-weight: bold;">BALANCE</td>
                            <td style="font-weight: bold;"><?= htmlspecialchars(number_format($total_balance, 2)) ?></td>
                            <td style="font-weight: bold;"><?= htmlspecialchars(number_format($advance_payment, 2)) ?></td>
                            <td style="font-weight: bold;"><?= htmlspecialchars(number_format($total_balance - $advance_payment, 2)) ?> - (TOTAL BALANCE)</td>
                        </tr>
                    </tbody>
                </table>
                <div class="account-info">
                    <p class="highlight"><strong>Account No. : <?= htmlspecialchars($info->account_number) ?></strong></p>
                </div>
            <?php else : ?>
                <table class="account-summary">
                    <tbody>
                        <?php
                        $total_due = 0.00;
                        $bill_ids = [];
                        $monthArray = [];
                        $packageName = isset($packageInfo->name) ? $packageInfo->name : 'N/A';
                        $packageFee = isset($packageInfo->fee) ? $packageInfo->fee : 0;
                        $billing_period_display = "N/A";
                        $amount_display = $packageFee;

                        echo '<tr><td class="blue-bg"><b>Plan</b></td><td>' . htmlspecialchars($packageName) . '</td></tr>';

                        if (isset($bills) && sizeof($bills) > 0) {
                            $unpaid_bills = [];
                            foreach ($bills as $bill) {
                                if ($bill->status !== 'Paid') {
                                    $due_amount = (is_numeric($bill->balance) && (float)$bill->balance > 0)
                                        ? (float)$bill->balance
                                        : (float)$bill->amount;
                                    $total_due += $due_amount;
                                    $unpaid_bills[] = $bill;
                                }
                            }

                            if (count($unpaid_bills) > 0) {
                                if (count($unpaid_bills) == 1) {
                                    $bill = $unpaid_bills[0];
                                    $g_date = new DateTime($bill->g_date);
                                    $start_date = $g_date->format('F d');
                                    $end_date = $g_date->modify('+1 month')->format('F d, Y');
                                    $billing_period_display = "$start_date - $end_date";
                                    $amount_display = $bill->amount;
                                } else {
                                    $billing_period_display = "Multiple Unpaid Bills";
                                    $amount_display = $total_due;
                                }
                            } else {
                                // If no unpaid bills, show the latest paid bill's period
                                $paid_bills = array_filter($bills, function($bill) {
                                    return $bill->status === 'Paid';
                                });

                                if (!empty($paid_bills)) {
                                    usort($paid_bills, function($a, $b) {
                                        return strtotime($b->g_date) - strtotime($a->g_date);
                                    });
                                    $latest_bill = $paid_bills[0];
                                    $g_date = new DateTime($latest_bill->g_date);
                                    $start_date = $g_date->format('F d');
                                    $end_date = $g_date->modify('+1 month')->format('F d, Y');
                                    $billing_period_display = "$start_date - $end_date";
                                    $amount_display = 0.00;
                                }
                            }
                        } else {
                            // For new customers with no bills, assume current month's fee
                            $total_due = $packageFee;
                            $billing_period_display = date("F d") . " - " . date("F d, Y", strtotime('+1 month'));
                        }
                        ?>
                        <tr>
                            <td class="blue-bg"><strong>Billing Period</strong></td>
                            <td><?= htmlspecialchars($billing_period_display) ?></td>
                        </tr>
                        <tr>
                            <td class="blue-bg"><strong>Amount</strong></td>
                            <td>Php<?= htmlspecialchars(number_format($amount_display, 2)) ?></td>
                        </tr>
                        <tr>
                            <td class="blue-bg"><strong>Total Amount Due</strong></td>
                            <td>Php<?= htmlspecialchars(number_format($total_due, 2)) ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="total-due">
                    <span>TOTAL AMOUNT DUE</span>
                    <span class="leader"></span>
                    <span>Php<?= htmlspecialchars(number_format($total_due, 2)) ?></span>
                </div>

                <div class="account-info">
                    <p class="highlight"><strong>Account No. : <?= htmlspecialchars($info->account_number) ?></strong></p>
                </div>

                <?php if ($action != 'bill' && (isset($total_due) && $total_due > 0)) : ?>
                    <div class="row no-print">
                        <form class="form-inline" action="post_approve.php" method="POST">
                            <input type="hidden" name="customer" value="<?= (isset($info->id) ? htmlspecialchars($info->id) : '') ?>">
                            <input type="hidden" name="bills" value="<?= htmlspecialchars(implode(',', array_map(function ($bill) {
                                                                        return $bill->id;
                                                                    }, $unpaid_bills))) ?>">
                            <div class="form-group">
                                <label for="months"></label>
                                <select class="selectpicker" name="months[]" id="months" multiple required title="Select months">
                                    <?php
                                    if (!empty($unpaid_bills)) {
                                        foreach ($unpaid_bills as $bill) {
                                            echo '<option value="' . htmlspecialchars($bill->r_month) . '" selected>' . htmlspecialchars($bill->r_month) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="sr-only" for="discount">Discount</label>
                                <input type="number" class="form-control" name="discount" id="discount" placeholder="Discount">
                            </div>
                            <div class="form-group">
                                <label class="sr-only" for="total">Payment</label>
                                <input type="number" class="form-control disabled" name="total" id="total" placeholder="total" required="" value="<?= htmlspecialchars($total_due) ?>">
                            </div>
                            <button type="submit" class="btn btn-primary">Paid</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>


            <div class="footer-notes">
                <p><em>We appreciate your prompt payment and value you as a customer.</em></p>
            </div>
            <hr>
            <div class="contact-info-container">
                <div class="contact-title">
                    <strong>Contact us:</strong>
                </div>
                <table>
                    <tr>
                        <td><strong>FB Page</strong></td>
                        <td>:</td>
                        <td>CORNER STONE INNOVATE TECH SOL</td>
                    </tr>
                    <tr>
                        <td><strong>Customer Service</strong></td>
                        <td>:</td>
                        <td>0951-6551142</td>
                    </tr>
                    <tr>
                        <td><strong>Billing Department</strong></td>
                        <td>:</td>
                        <td>0985-3429675</td>
                    </tr>
                </table>
            </div>
        <?php } ?>
    </div>
    <script src="component/js/bootstrap-select.min.js"></script>
    <script>
        $('#months').on('changed.bs.select', function(e) {
            console.log(this.value);
        });
    </script>
</body>

</html>