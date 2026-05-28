<?php
require_once 'db.php';
echo "<h2>Motorists Table Schema</h2>";
echo "<pre>";
if (isset($use_mysql) && $use_mysql) {
    $result = $pdo->query("DESCRIBE motorists");
} else {
    $result = $pdo->query("PRAGMA table_info(motorists)");
}
foreach ($result as $row) {
    print_r($row);
}
echo "</pre>";
echo '<p><a href="delete check_motorists_schema.php">Close</a></p>';
?>

