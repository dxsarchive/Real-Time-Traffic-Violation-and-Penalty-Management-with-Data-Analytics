<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require __DIR__ . '/db.php';

$sql = "
SELECT v.id, v.location, v.status, v.fine_amount, v.violation_date, v.violation_details,
       COALESCE(v.violation_details, p.violation_name) AS violation_name, m.full_name AS motorist_name
FROM violations v
LEFT JOIN penalties p ON v.penalty_id = p.id
LEFT JOIN motorists m ON v.motorist_id = m.id
ORDER BY v.violation_date DESC
LIMIT 100
";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();
echo json_encode($rows);