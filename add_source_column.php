<?php
/**
 * Database Migration: Add 'source' column to violations table
 * This script adds the source column to track whether violations were added by enforcer or treasurer
 */

require_once 'db.php';

global $pdo;
$conn = $pdo;

try {
    // Check if source column already exists (MySQL compatible)
    $result = $conn->query("SHOW COLUMNS FROM violations LIKE 'source'");
    $column_exists = $result->fetch(PDO::FETCH_ASSOC);
    
    if (!$column_exists) {
        // Add source column
        $conn->exec("ALTER TABLE violations ADD COLUMN source VARCHAR(50) DEFAULT 'enforcer'");
        echo "✅ Successfully added 'source' column to violations table!<br>";
        echo "Default value: 'enforcer'<br>";
        echo "<br>Violations added by the Municipal Treasurer will have source = 'treasurer'";
    } else {
        echo "ℹ️ The 'source' column already exists in the violations table.";
    }
    
    echo "<br><br><a href='index.php'>Go to Home</a>";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration</title>
    <link rel="stylesheet" href="style.css?v=20260425">
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 600px; margin: 0 auto; }
                .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; border-left: 4px solid #28a745; }
    </style>

</head>
<body>
    <h1>Database Migration</h1>
</body>
</html>

