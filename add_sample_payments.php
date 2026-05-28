<?php
require_once 'db.php';

// Check if sample payments exist
$count = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
if ($count > 0) {
    echo "Sample payments already exist ($count).";
    exit;
}

// Add sample violations first if none
$v_count = $pdo->query("SELECT COUNT(*) FROM violations")->fetchColumn();
if ($v_count == 0) {
    // Add sample violation
    $pdo->exec("INSERT INTO violations (motorist_id, enforcer_id, penalty_id, location, fine_amount, top_number, status) VALUES (1, 1, 1, 'Main Street', 500, 'SAMPLE-001', 'validated')");
    echo "Sample violation added.\n";
}

$v_id = $pdo->query("SELECT id FROM violations LIMIT 1")->fetchColumn();

// Add sample payment
$treasurer_id = 5; // Sample treasurer
$stmt = $pdo->prepare("INSERT INTO payments (violation_id, treasurer_id, receipt_number, payment_amount) VALUES (?, ?, ?, ?)");
$stmt->execute([$v_id, $treasurer_id, 'SAMPLE-REC-001', 500]);
echo "✅ Sample payment added. Receipt: SAMPLE-REC-001\n";
echo "Now check treasurer/dashboard.php - payment history should show.\n";
echo '<a href="treasurer/dashboard.php">Test Dashboard</a>';
?>

