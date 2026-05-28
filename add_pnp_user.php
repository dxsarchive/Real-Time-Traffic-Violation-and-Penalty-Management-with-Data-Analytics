<?php
/**
 * Script to add PNP Officer user to the database
 * Run this file in your browser: http://localhost/add_pnp_user.php
 */

require_once 'db.php';

try {
    // Check if pnp1 already exists
    $check = $pdo->prepare("SELECT id FROM users WHERE username = 'pnp1'");
    $check->execute();
    
    if ($check->fetch()) {
        echo "User pnp1 already exists!";
    } else {
        // Insert pnp1 user
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
        $result = $stmt->execute([
            'pnp1', 
            '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 
            'PNP Officer One', 
            'pnp_officer', 
            'active'
        ]);
        
        if ($result) {
            echo "✅ Success! PNP Officer user added.<br>";
            echo "<b>Login credentials:</b><br>";
            echo "Username: pnp1<br>";
            echo "Password: password123<br>";
            echo "Role: pnp_officer";
        } else {
            echo "❌ Failed to add user.";
        }
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
