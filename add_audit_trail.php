<?php
// Script to add audit_trail table and columns to existing database
// Run with: php add_audit_trail.php

$dbFile = __DIR__ . '/traffic_management.sqlite';

try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create audit_trail table
    $sql = "
    CREATE TABLE IF NOT EXISTS audit_trail (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        table_name TEXT NOT NULL,
        record_id INTEGER,
        details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    ";
    
    $pdo->exec($sql);
    echo "Audit trail table created successfully.\n";
    
    // Add supervisor_id column to violations if not exists
    try {
        $pdo->exec("ALTER TABLE violations ADD COLUMN supervisor_id INTEGER REFERENCES users(id)");
        echo "Added supervisor_id column.\n";
    } catch (Exception $e) {
        echo "supervisor_id column: " . $e->getMessage() . "\n";
    }
    
    // Add validation_date column if not exists
    try {
        $pdo->exec("ALTER TABLE violations ADD COLUMN validation_date DATETIME");
        echo "Added validation_date column.\n";
    } catch (Exception $e) {
        echo "validation_date column: " . $e->getMessage() . "\n";
    }
    
    echo "Database updates completed.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
