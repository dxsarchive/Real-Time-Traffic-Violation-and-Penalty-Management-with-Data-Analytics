<?php
// Initializes a MySQL database using Real-time_db.sql (designed for XAMPP MySQL/MariaDB)
// Run with: & 'C:\xampp\php\php.exe' init_mysql.php

$sqlFile = __DIR__ . '/Real-time_db.sql';
if (!file_exists($sqlFile)) {
    echo "SQL file not found: $sqlFile\n";
    exit(1);
}

require_once __DIR__ . '/config.php';

$host = $db_host;
$user = $db_user;
$pass = $db_pass;

try {
    // Connect without selecting a database so CREATE DATABASE works
    $pdo = new PDO("mysql:host=$host", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $sql = file_get_contents($sqlFile);
    // Split statements by semicolon — acceptable for simple schema files
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($stmts as $stmt) {
        if ($stmt === '' ) continue;
        $pdo->exec($stmt);
    }

    echo "MySQL database initialized successfully.\n";
} catch (PDOException $e) {
    echo "Error initializing MySQL DB: " . $e->getMessage() . "\n";
    exit(1);
}

?>
