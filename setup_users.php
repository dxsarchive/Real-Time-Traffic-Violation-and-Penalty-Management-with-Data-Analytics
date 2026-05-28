<?php
/**
 * Complete setup script - Creates all necessary users with working passwords
 * Run this file in your browser: http://localhost/setup_users.php
 */

require_once 'db.php';

echo "<h2>Setting up User Accounts...</h2>";

try {
    // Hash for password "password123"
    $password_hash = password_hash('password123', PASSWORD_DEFAULT);
    
    $users = [
        ['username' => 'enforcer1', 'full_name' => 'John Enforcer', 'role' => 'enforcer'],
        ['username' => 'supervisor1', 'full_name' => 'Maria Supervisor', 'role' => 'supervisor'],
        ['username' => 'treasurer1', 'full_name' => 'Paul Treasurer', 'role' => 'treasurer'],
        ['username' => 'motorist1', 'full_name' => 'Carlos Motorist', 'role' => 'motorist'],
        ['username' => 'pnp1', 'full_name' => 'PNP Officer One', 'role' => 'pnp_officer'],
        ['username' => 'admin1', 'full_name' => 'System Administrator', 'role' => 'admin'],
    ];
    
    foreach ($users as $user) {
        // Check if user exists
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$user['username']]);
        
        if ($check->fetch()) {
            // Update existing user to active status and reset password
            $stmt = $pdo->prepare("UPDATE users SET password = ?, status = 'active' WHERE username = ?");
            $stmt->execute([$password_hash, $user['username']]);
            echo "<p style='color: orange;'>✅ Updated user: {$user['username']}</p>";
        } else {
            // Insert new user
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$user['username'], $password_hash, $user['full_name'], $user['role']]);
            echo "<p style='color: green;'>✅ Created user: {$user['username']}</p>";
        }
    }
    
    echo "<h3>All Users Setup Complete!</h3>";
    echo "<h4>Login Credentials (use these to test):</h4>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Username</th><th>Password</th><th>Role</th><th>Portal Link</th></tr>";
    echo "<tr><td>enforcer1</td><td>password123</td><td>Traffic Enforcer</td><td><a href='index.php?role=enforcer'>Login</a></td></tr>";
    echo "<tr><td>supervisor1</td><td>password123</td><td>Supervisor</td><td><a href='index.php?role=supervisor'>Login</a></td></tr>";
    echo "<tr><td>treasurer1</td><td>password123</td><td>Municipal Treasurer</td><td><a href='index.php?role=treasurer'>Login</a></td></tr>";
    echo "<tr><td>motorist1</td><td>password123</td><td>Motorist</td><td><a href='index.php?role=motorist'>Login</a></td></tr>";
    echo "<tr><td>pnp1</td><td>password123</td><td>PNP Officer</td><td><a href='index.php?role=pnp_officer'>Login</a></td></tr>";
    echo "<tr><td>admin1</td><td>password123</td><td>System Admin</td><td><a href='index.php?role=admin'>Login</a></td></tr>";
    echo "</table>";
    
    echo "<p><b>Note:</b> Use the role-specific portal link for each account.</p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
