<?php
require_once __DIR__ . '/db.php';

try {
    // Already connected from api/db.php

    // List all tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in database:\n";
    foreach ($tables as $table) {
        echo "- $table\n";
    }

    // Check specific tables and their row counts
    $tableCounts = [
        'users' => 'SELECT COUNT(*) FROM users',
        'motorists' => 'SELECT COUNT(*) FROM motorists',
        'penalties' => 'SELECT COUNT(*) FROM penalties',
        'violations' => 'SELECT COUNT(*) FROM violations',
        'evidence' => 'SELECT COUNT(*) FROM evidence',
        'payments' => 'SELECT COUNT(*) FROM payments',
        'articles' => 'SELECT COUNT(*) FROM articles'
    ];

    echo "\nRow counts:\n";
    foreach ($tableCounts as $table => $query) {
        $stmt = $pdo->query($query);
        $count = $stmt->fetchColumn();
        echo "$table: $count rows\n";
    }

    // Check sample penalties
    echo "\nSample penalties:\n";
    $stmt = $pdo->query("SELECT violation_name, fine_amount FROM penalties");
    while ($row = $stmt->fetch()) {
        echo "- {$row['violation_name']}: {$row['fine_amount']}\n";
    }

    // Check sample users
    echo "\nSample users:\n";
    $stmt = $pdo->query("SELECT username, role FROM users");
    while ($row = $stmt->fetch()) {
        echo "- {$row['username']} ({$row['role']})\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
