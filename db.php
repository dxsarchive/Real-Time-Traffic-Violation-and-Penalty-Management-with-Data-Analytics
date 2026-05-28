<?php
require_once __DIR__ . '/config.php';

// Use a single timezone across the app for consistent real-world timestamps.
date_default_timezone_set('Asia/Manila');

// Check if MySQL should be used (via config_private.php)
$use_mysql = false;
if (file_exists(__DIR__ . '/config_private.php')) {
    include __DIR__ . '/config_private.php';
}

function get_db_connection() {
    global $db_host, $db_user, $db_pass, $db_name, $db_charset, $use_mysql;
    
    // Check if MySQL is configured
    if (isset($use_mysql) && $use_mysql === true) {
        $dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            return new PDO($dsn, $db_user, $db_pass, $options);
        } catch (PDOException $e) {
            die("MySQL connection failed: " . $e->getMessage() . "\nMake sure XAMPP MySQL is running and you've imported init_mysql.sql in phpMyAdmin.");
        }
    }
    
    // Default: Use SQLite
    $dsn = "sqlite:" . __DIR__ . "/traffic_management.sqlite";
    try {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, null, null, $options);
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage() . "\nTo initialize the database, run init_sqlite.php using PHP.");
    }
}

$pdo = get_db_connection();
?>
