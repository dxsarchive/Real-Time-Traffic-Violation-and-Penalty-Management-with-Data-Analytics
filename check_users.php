<?php
/**
 * Script to check user accounts and their status
 * Run this file in your browser: http://localhost/check_users.php
 */

require_once 'db.php';

echo "<h2>User Account Status</h2>";

try {
    // Get all users
    $stmt = $pdo->query("SELECT id, username, full_name, role, status FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "<p>No users found in the database!</p>";
        echo "<p>Please run <a href='init_sqlite.php'>init_sqlite.php</a> to initialize the database with sample users.</p>";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th></tr>";
        
        foreach ($users as $user) {
            $status_color = $user['status'] === 'active' ? 'green' : 'red';
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['username']}</td>";
            echo "<td>{$user['full_name']}</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td style='color: {$status_color}; font-weight: bold;'>{$user['status']}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        echo "<h3>Login Credentials:</h3>";
        echo "<ul>";
        echo "<li><b>enforcer1</b> / password123 (role: enforcer)</li>";
        echo "<li><b>supervisor1</b> / password123 (role: supervisor)</li>";
        echo "<li><b>treasurer1</b> / password123 (role: treasurer)</li>";
        echo "<li><b>motorist1</b> / password123 (role: motorist)</li>";
        echo "<li><b>pnp1</b> / password123 (role: pnp_officer)</li>";
        echo "</ul>";
    }
    
} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
