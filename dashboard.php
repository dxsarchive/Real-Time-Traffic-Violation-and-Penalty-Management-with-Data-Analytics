<?php
require_once 'auth.php';
check_role('treasurer');

$conn = get_db_connection();

// Handle Payment
if (isset($_POST['mark_paid']) && isset($_POST['violation_id'])) {
    $v_id = (int)$_POST['violation_id'];
    $amount = (float)$_POST['amount'];
    $treasurer_id = $_SESSION['user_id'];
    $receipt_no = 'REC-' . time() . '-' . rand(1000, 9999);

    try {
        $conn->beginTransaction();

        $p_stmt = $conn->prepare("INSERT INTO payments (violation_id, treasurer_id, receipt_number, payment_amount) VALUES (:violation_id, :treasurer_id, :receipt_number, :payment_amount)");
        $p_stmt->execute([
            ':violation_id' => $v_id,
            ':treasurer_id' => $treasurer_id,
            ':receipt_number' => $receipt_no,
            ':payment_amount' => $amount
        ]);

        $v_stmt = $conn->prepare("UPDATE violations SET status = 'paid' WHERE id = :id");
        $v_stmt->execute([':id' => $v_id]);

        $conn->commit();
        $success = "Payment recorded successfully! Receipt: " . $receipt_no;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = "Error recording payment.";
    }
}

// Stats
$stmt = $conn->query("SELECT SUM(payment_amount) as total FROM payments");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$revenue = $row['total'] ?? 0;

$stmt = $conn->query("SELECT COUNT(*) as count FROM violations WHERE status = 'validated'");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$validated_count = $row['count'] ?? 0;

// Fetch validated violations for payment
$validated_res = $conn->query("SELECT v.*, m.full_name as motorist_name, p.violation_name 
                               FROM violations v 
                               JOIN motorists m ON v.motorist_id = m.id 
                               JOIN penalties p ON v.penalty_id = p.id 
                               WHERE v.status = 'validated' 
                               ORDER BY v.validation_date DESC");

$validated_rows = $validated_res->fetchAll(PDO::FETCH_ASSOC);

// Fetch payment history
$history_res = $conn->query("SELECT pay.*, m.full_name as motorist_name, p.violation_name 
                             FROM payments pay 
                             JOIN violations v ON pay.violation_id = v.id 
                             JOIN motorists m ON v.motorist_id = m.id 
                             JOIN penalties p ON v.penalty_id = p.id 
                             ORDER BY pay.payment_date DESC LIMIT 20");

$history_rows = $history_res->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treasurer Dashboard - Traffic Management System</title>
    <link rel="stylesheet" href="style.css?v=20260425">
    <script src="theme.js" defer></script>
</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>Municipal Treasurer</h2>
            <ul>
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="revenue.php">Revenue Analytics</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </nav>
        <main class="main-content">
            <header>
                <h1>Payment Management</h1>
                <div class="user-info"><?php echo $_SESSION['full_name']; ?></div>
            </header>

            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Revenue</h3>
                    <div class="value">₱<?php echo number_format($revenue, 2); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending Payments</h3>
                    <div class="value"><?php echo $validated_count; ?></div>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert alert-success" style="background-color: #d4edda; color: #155724; padding: 10px; margin-bottom: 20px;"><?php echo $success; ?></div>
            <?php endif; ?>

            <div class="card">
                <h2>Validated Violations (Pending Payment)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Motorist</th>
                            <th>Violation</th>
                            <th>Amount</th>
                            <th>Validated On</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($validated_rows) > 0): ?>
                            <?php foreach($validated_rows as $v): ?>
                                <tr>
                                    <td><?php echo $v['motorist_name']; ?></td>
                                    <td><?php echo $v['violation_name']; ?></td>
                                    <td>₱<?php echo number_format($v['fine_amount'], 2); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($v['validation_date'])); ?></td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="violation_id" value="<?php echo $v['id']; ?>">
                                            <input type="hidden" name="amount" value="<?php echo $v['fine_amount']; ?>">
                                            <button type="submit" name="mark_paid" class="btn" style="background: var(--secondary-color); color:white; padding: 5px 10px;">Mark as Paid</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5">No pending payments.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>Recent Payment History</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Motorist</th>
                            <th>Violation</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($history_rows as $h): ?>
                            <tr>
                                <td><?php echo $h['receipt_number']; ?></td>
                                <td><?php echo $h['motorist_name']; ?></td>
                                <td><?php echo $h['violation_name']; ?></td>
                                <td>₱<?php echo number_format($h['payment_amount'], 2); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($h['payment_date'])); ?></td>
                                <td><a href="receipt.php?id=<?php echo $h['id']; ?>" class="btn" style="background: var(--info); color:white; padding: 2px 5px; font-size: 0.8rem;">View Receipt</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
