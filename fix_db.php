<?php
/**
 * Fix all database issues: Run in browser http://localhost/Real-Time Traffic Violation and Penalty Management System Design/fix_db.php
 */

require_once 'db.php';

echo "<h1>🛠️ Database Fixer - Traffic Violation System</h1>";

try {
    global $pdo;
    
    // 1. Ensure all tables exist using init_sqlite_fixed.sql
    $schema_sql = file_get_contents('init_sqlite_fixed.sql');
    $pdo->exec($schema_sql);
    echo "<p>✅ Schema recreated from init_sqlite_fixed.sql</p>";
    
    // 2. Fix penalties (ensure all violations present)
    $pdo->exec("DELETE FROM penalties"); // Clear and re-insert
    $penalties_sql = "
        INSERT OR REPLACE INTO penalties (violation_name, description, fine_amount) VALUES
        ('Unregistered MV', 'Vehicle not registered with LTO', 100.00),
        ('Unlicensed driver', 'Driver without valid DL', 100.00),
        ('Colorum/unfranchised operation', 'Unfranchised public utility vehicle', 100.00),
        ('Invalid or suspended/revoked/expired CR', 'Invalid or expired Certificate of Registration', 100.00),
        ('Invalid or suspended/revoked/expired DL', 'Invalid or expired Driver\\'s License', 100.00),
        ('Out of line', 'Public utility vehicle out of authorized route', 100.00),
        ('Student driver not accompanied by LD', 'Student permit driver without licensed companion', 100.00),
        ('Discorteous driver/conduct', 'Discourteous driving behavior', 100.00),
        ('CR/OR not carried', 'CR/OR not in driver's possession', 100.00),
        ('CPC/PA/Permit not carried', \"Conductor's permit or other docs not carried\", 100.00),
        ('Unauthorized improvised plates', 'Fake or improvised license plates', 100.00),
        ('No required MV part/acc', 'Missing required motor vehicle parts/accessories', 100.00),
        ('No early warning device', 'No early warning devices for breakdowns', 100.00),
        ('No capacity marking', 'No passenger capacity markings', 100.00),
        ('No body (plate) number', 'Missing body or chassis number', 100.00),
        ('For hire MV', 'Private vehicle used for hire without franchise', 100.00),
        ('No tailgate/not for hire sign', 'Missing required signage on tailgate', 100.00),
        ('No front panel route', 'No route display on front panel', 100.00),
        ('Unauthorized wearing slippers/shirt', 'Driver wearing improper attire (slippers/sleeveless)', 100.00),
        ('Allowing passenger on top of MV', 'Passengers riding on roof/top of vehicle', 100.00),
        ('Reckless driving', 'Reckless imprudent driving endangering life/property', 100.00),
        ('Obstruction', 'Causing unnecessary obstruction to traffic', 100.00),
        ('No Helmet', 'Driving without wearing a proper safety helmet', 100.00),
        ('Open muffler', 'Vehicle with open or defective muffler', 1000.00);
    ";
    $pdo->exec($penalties_sql);
    echo "<p>✅ Penalties table fixed and updated</p>";
    
    // 3. Ensure all sample users
    $users_sql = "
        INSERT OR IGNORE INTO users (username, password, full_name, role, status) VALUES
        ('enforcer1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Enforcer One', 'enforcer', 'active'),
        ('enforcer2', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Enforcer Two', 'enforcer', 'active'),
        ('supervisor1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Supervisor One', 'supervisor', 'active'),
        ('treasurer1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Treasurer One', 'treasurer', 'active'),
        ('motorist1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Motorist One', 'motorist', 'active'),
        ('pnp1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'PNP Officer One', 'pnp_officer', 'active');
    ";
    $pdo->exec($users_sql);
    echo "<p>✅ All sample users inserted (password: password123)</p>";
    
    // 4. Test connection and key tables
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>✅ Tables present: " . implode(', ', $tables) . "</p>";
    
    $penalty_count = $pdo->query("SELECT COUNT(*) FROM penalties")->fetchColumn();
    echo "<p>✅ Penalties count: $penalty_count</p>";
    
    echo "<h3>🎉 Database fixed successfully!</h3>";
    echo "<p><b>Login:</b> enforcer1 / password123 → Test violation submission flow</p>";
    echo "<p><a href='index.php'>← Back to login</a></p>";
    
} catch (Exception $e) {
    echo "<p>❌ Fix failed: " . $e->getMessage() . "</p>";
}
?>

