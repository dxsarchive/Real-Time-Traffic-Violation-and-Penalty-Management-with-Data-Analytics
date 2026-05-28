<?php
/**
 * Add missing columns to motorists table for enforcer dashboard
 * Run: http://localhost/add_motorist_columns.php
 * Safe: Adds columns if missing (MySQL/SQLite compatible)
 */

require_once 'db.php';
echo "<h2>Adding Motorists Columns...</h2>";

try {
    // Common columns to add
    $columns = [
        'driver_type VARCHAR(50) DEFAULT NULL',
        'nationality VARCHAR(50) DEFAULT NULL',
        'vehicle_year VARCHAR(10) DEFAULT NULL',
        'vehicle_color VARCHAR(50) DEFAULT NULL',
        'accident BOOLEAN DEFAULT 0'
    ];

    if (isset($use_mysql) && $use_mysql === true) {
        // MySQL
        foreach ($columns as $col_def) {
            $pdo->exec("ALTER TABLE motorists ADD COLUMN IF NOT EXISTS $col_def");
            echo "<p>✅ Added/verified: $col_def (MySQL)</p>";
        }
    } else {
        // SQLite: Check PRAGMA, ALTER if missing
        $info = $pdo->query("PRAGMA table_info(motorists)");
        $existing = [];
        while ($row = $info->fetch(PDO::FETCH_ASSOC)) {
            $existing[] = $row['name'];
        }
        $sqlite_cols = ['driver_type TEXT', 'nationality TEXT', 'vehicle_year TEXT', 'vehicle_color TEXT', 'accident INTEGER DEFAULT 0'];
        foreach (['driver_type', 'nationality', 'vehicle_year', 'vehicle_color', 'accident'] as $col) {
            if (!in_array($col, $existing)) {
                $pdo->exec("ALTER TABLE motorists ADD COLUMN $col " . ($col == 'accident' ? 'INTEGER DEFAULT 0' : 'TEXT'));
                echo "<p>✅ Added: $col (SQLite)</p>";
            } else {
                echo "<p>✅ Exists: $col</p>";
            }
        }
    }

    echo "<h3>✅ Schema updated! Test enforcer/dashboard.php now.</h3>";
    echo "<p><a href='enforcer/dashboard.php'>Go to Enforcer Dashboard</a></p>";

} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}
?>
