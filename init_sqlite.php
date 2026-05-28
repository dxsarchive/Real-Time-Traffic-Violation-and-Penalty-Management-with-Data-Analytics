<?php
/**
 * Initialize SQLite database with the latest schema and sample data
 * Run this file in your browser: http://localhost/init_sqlite.php
 */

require_once 'db.php';

echo "<h2>Initializing SQLite Database...</h2>";

try {
    // Add pnp_officer to role CHECK (if table exists)
    // Note: SQLite doesn't support ALTER TABLE ADD CONSTRAINT, so we recreate table if needed
    
    // Check if pnp1 exists
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute(['pnp1']);
    
    if ($check->fetch()) {
        echo "<p>✅ User pnp1 already exists!</p>";
    } else {
        // Insert pnp1 user
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            'pnp1', 
            '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 
            'PNP Officer One', 
            'pnp_officer', 
            'active'
        ]);
        echo "<p>✅ Added pnp1 user to database!</p>";
    }
    
    // Check if confiscated_items column exists in violations
    $table_info = $pdo->query("PRAGMA table_info(violations)");
    $columns = $table_info->fetchAll(PDO::FETCH_ASSOC);
    $has_confiscated = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'confiscated_items') {
            $has_confiscated = true;
            break;
        }
    }
    
    if (!$has_confiscated) {
        // Add the column
        $pdo->exec("ALTER TABLE violations ADD COLUMN confiscated_items TEXT DEFAULT 'None'");
        echo "<p>✅ Added confiscated_items column to violations table!</p>";
    } else {
        echo "<p>✅ confiscated_items column already exists!</p>";
    }
    
    // Verify all users
    echo "<h3>Current Users:</h3>";
    echo "<ul>";
    $users = $pdo->query("SELECT username, role, status FROM users");
    while ($user = $users->fetch(PDO::FETCH_ASSOC)) {
        echo "<li>{$user['username']} - {$user['role']} ({$user['status']})</li>";
    }
    echo "</ul>";
    
    echo "<h3>Login Credentials:</h3>";
    echo "<ul>";
    echo "<li><b>enforcer1</b> / password123</li>";
    echo "<li><b>supervisor1</b> / password123</li>";
    echo "<li><b>treasurer1</b> / password123</li>";
    echo "<li><b>motorist1</b> / password123</li>";
    echo "<li><b>pnp1</b> / password123 (NEW!)</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}
