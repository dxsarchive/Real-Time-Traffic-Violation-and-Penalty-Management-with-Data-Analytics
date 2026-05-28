<?php
session_start();
require_once 'auth.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $confirm_password = isset($_POST['confirm_password']) ? trim($_POST['confirm_password']) : '';
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';
    $allowed_roles = ['enforcer', 'pnp_officer', 'supervisor', 'treasurer', 'motorist'];

    // Validation
    if (empty($username) || empty($password) || empty($confirm_password) || empty($full_name) || empty($role)) {
        $error = 'All fields are required.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif (!in_array($role, $allowed_roles, true)) {
        $error = 'Invalid role selected.';
    } else {
        try {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username already exists.';
            } else {
                // Insert new user with active status
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, ?, 'active')");
                $stmt->execute([$username, $hashed_password, $full_name, $role]);

                $message = 'Registration successful! You can now log in.';
            }
        } catch (PDOException $e) {
            $error = 'Registration failed. Please try again.';
            error_log("Registration error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Traffic Management System</title>
    <link rel="stylesheet" href="style.css?v=20260425">
    <script src="theme.js" defer></script>
</head>
<body class="login-page auth-single-page">
    <div class="auth-single-shell">
        <div class="login-container auth-card auth-single-card">
            <div class="login-header auth-header">
                <a href="index.php" class="back-link">← Back to Portal Selection</a>
                <h2>User Registration</h2>
                <p>Create your account</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="" class="auth-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" required>
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        <option value="">Select your role</option>
                        <option value="enforcer">Traffic Enforcer</option>
                        <option value="pnp_officer">PNP Officer</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="treasurer">Municipal Treasurer</option>
                        <option value="motorist">Motorist</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn btn-primary auth-submit">Register</button>
            </form>

            <div class="login-footer auth-footer">
                <p>&copy; 2025 Traffic Management Unit</p>
            </div>
        </div>
    </div>
</body>
</html>
