<?php
require_once 'auth.php';
require_once 'db.php';

echo "=== Testing Login System ===\n\n";

// Test 1: Database Connection
echo "1. Testing Database Connection...\n";
try {
    global $pdo;
    $result = $pdo->query("SELECT COUNT(*) as user_count FROM users");
    $count = $result->fetch(PDO::FETCH_ASSOC);
    echo "   ✓ Database connected successfully!\n";
    echo "   ✓ Total users in database: " . $count['user_count'] . "\n\n";
} catch (PDOException $e) {
    echo "   ✗ Database error: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: List all users
echo "2. Listing all users:\n";
try {
    $users = $pdo->query("SELECT id, username, full_name, role, status FROM users")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        echo "   - {$u['username']} ({$u['full_name']}) - Role: {$u['role']}, Status: {$u['status']}\n";
    }
    echo "\n";
} catch (PDOException $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Test Login Function
echo "3. Testing login function:\n";
$test_credentials = [
    ['enforcer1', 'password123', 'enforcer'],
    ['supervisor1', 'password123', 'supervisor'],
    ['treasurer1', 'password123', 'treasurer'],
    ['motorist1', 'password123', 'motorist']
];

foreach ($test_credentials as $cred) {
    $username = $cred[0];
    $password = $cred[1];
    $expected_role = $cred[2];
    
    // Create a new PDO for testing (to avoid session conflicts)
    $test_pdo = get_db_connection();
    
    $stmt = $test_pdo->prepare("SELECT id, username, password, full_name, role, status FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        if (password_verify($password, $user['password'])) {
            echo "   ✓ $username / $password => Role: {$user['role']} (Expected: $expected_role)\n";
        } else {
            echo "   ✗ $username - Password verification failed!\n";
        }
    } else {
        echo "   ✗ $username - User not found!\n";
    }
}

echo "\n4. Testing role-based dashboards access:\n";

// Test 4: Check tables for each dashboard
$tables = [
    'users' => 'User management',
    'motorists' => 'Motorist records',
    'violations' => 'Traffic violations',
    'penalties' => 'Penalty types',
    'payments' => 'Payment records',
    'articles' => 'Help articles',
    'audit_trail' => 'System audit log',
    'evidence' => 'Violation evidence',
    'motorist_offense_counts' => 'Offense tracking'
];

foreach ($tables as $table => $description) {
    try {
        $result = $pdo->query("SELECT COUNT(*) as cnt FROM $table")->fetch(PDO::FETCH_ASSOC);
        echo "   ✓ $table: {$result['cnt']} records - $description\n";
    } catch (PDOException $e) {
        echo "   ✗ $table: Error - " . $e->getMessage() . "\n";
    }
}

echo "\n=== Test Complete ===\n";
echo "\nLogin credentials:\n";
echo "  - enforcer1 / password123\n";
echo "  - supervisor1 / password123\n";
echo "  - treasurer1 / password123\n";
echo "  - motorist1 / password123\n";
?>
