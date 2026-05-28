<?php
// Quick DB sanity check script.
require_once __DIR__ . '/db.php';

try {
    $pdo = get_db_connection();
    $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM users');
    $row = $stmt->fetch();
    $count = $row ? $row['cnt'] : 0;
    echo "Connected OK — users count: " . $count . "\n";
} catch (Exception $e) {
    echo "DB test failed: " . $e->getMessage() . "\n";
    exit(1);
}

?>
