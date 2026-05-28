<?php
require_once 'db.php';
require_once 'auth.php';

if (login('enforcer2', 'password123', 'enforcer')) {
    echo 'Login successful. User ID: ' . $_SESSION['user_id'] . ', Role: ' . $_SESSION['role'] . PHP_EOL;

    // Simulate POST data for violation submission
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'submit_violation' => '1',
        'license_number' => 'ABC123',
        'motorist_name' => 'John Doe',
        'penalty_id' => '1',
        'location' => 'Main Street'
    ];

    // Include the dashboard script to test violation submission
    include 'enforcer/dashboard.php';

    echo 'Violation submission test completed.' . PHP_EOL;
} else {
    echo 'Login failed.' . PHP_EOL;
}
?>
