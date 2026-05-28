<?php
require_once 'db.php';
echo "<h2>Payments Table Check</h2>";
echo "<pre>";
$res = $pdo->query("SELECT * FROM payments LIMIT 5");
echo "Total payments: " . $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn() . "\n\n";
foreach ($res as $row) {
    print_r($row);
}
echo "\nViolations for payments:\n";
$res2 = $pdo->query("SELECT COUNT(*) FROM payments p JOIN violations v ON p.violation_id = v.id");
echo "Joined violations: " . $res2->fetchColumn() . "\n";
?></pre>
<p><a href="delete check_payments.php">Close</a></p>
?>

