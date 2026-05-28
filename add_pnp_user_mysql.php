<?php
/**
 * Add PNP Officer user to MySQL database
 * Run this file in your browser: http://localhost/add_pnp_user_mysql.php
 */

$host = '127.0.0.1';
$db   = 'traffic_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

echo "<h2>Adding PNP Officer to MySQL Database</h2>";

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Check if pnp1 exists
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute(['pnp1']);
    
    if ($check->fetch()) {
        echo "<p>✅ User pnp1 already exists!</p>";
    } else {
        // Insert pnp1 user
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            'pnp1', 
            '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 
            'PNP Officer One', 
            'pnp_officer', 
            'active'
        ]);
        echo "<p>✅ Added pnp1 user to database!</p>";
    }
    
    // Check if confiscated_items column exists
    try {
        $pdo->query("SELECT confiscated_items FROM violations LIMIT 1");
        echo "<p>✅ confiscated_items column already exists!</p>";
    } catch (PDOException $e) {
        // Column doesn't exist, add it
        $pdo->exec("ALTER TABLE violations ADD COLUMN confiscated_items VARCHAR(255) DEFAULT 'None'");
        echo "<p>✅ Added confiscated_items column!</p>";
    }
    
    // Show all users
    echo "<h3>Current Users:</h3>";
    echo "<ul>";
    $users = $pdo->query("SELECT username, role, status FROM users");
    while ($user = $users->fetch(PDO::FETCH_ASSOC)) {
        echo "<li>{$user['username']} - {$user['role']} ({$user['status']})</li>";
    }
    echo "</ul>";
    
    echo "<h3>Login Credentials:</h3>";
    echo "<ul>";
    echo "<li><b>pnp1</b> / password123</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}
?>
