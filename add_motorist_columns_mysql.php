<?php
/**
 * Robust MySQL Migration for motorists columns
 * Safe: Checks SHOW COLUMNS before ALTER
 */
require_once 'db.php';

echo "<h2>Adding Missing Columns to motorists (MySQL)</h2>";

$columns = [
    'driver_type' => 'VARCHAR(50) DEFAULT NULL',
    'nationality' => 'VARCHAR(50) DEFAULT NULL',
    'vehicle_year' => 'VARCHAR(10) DEFAULT NULL',
    'vehicle_color' => 'VARCHAR(50) DEFAULT NULL',
    'accident' => 'BOOLEAN DEFAULT 0'
];

$info = $pdo->query("SHOW COLUMNS FROM motorists");
$existing = [];
foreach ($info as $row) {
    $existing[] = $row['Field'];
}
echo "<p>Existing columns: " . implode(', ', $existing) . "</p>";

$added = 0;
foreach ($columns as $col => $def) {
    if (!in_array($col, $existing)) {
        $pdo->exec("ALTER TABLE motorists ADD COLUMN `$col` $def");
        echo "<p>✅ Added: `$col` $def</p>";
        $added++;
    } else {
        echo "<p>ℹ️ Exists: $col</p>";
    }
}

echo "<h3>✅ Migration complete! $added columns added.</h3>";
echo '<p><a href="check_motorists_schema.php">Check Schema</a> | <a href="enforcer/dashboard.php">Test Dashboard</a></p>';
?>

