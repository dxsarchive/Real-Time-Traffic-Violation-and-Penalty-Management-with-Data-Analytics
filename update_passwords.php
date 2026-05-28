<?php
require_once 'db.php';

$hashed_password = password_hash('password123', PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username IN ('enforcer1', 'enforcer2', 'supervisor1', 'supervisor2', 'treasurer1', 'treasurer2', 'motorist1', 'motorist2')");
    $stmt->execute([$hashed_password]);

    echo "Passwords updated successfully to 'password123' for all sample users.\n";
} catch (PDOException $e) {
    echo "Error updating passwords: " . $e->getMessage() . "\n";
}
?>
