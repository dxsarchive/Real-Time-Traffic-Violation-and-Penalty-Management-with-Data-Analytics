<?php
require_once '../auth.php';

if (is_logged_in() && (($_SESSION['role'] ?? '') === 'admin')) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$maintenance_notice = '';
$auth_notice = consume_auth_notice();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if (login($username, $password, 'admin')) {
            if (!empty($_SESSION['must_change_password'])) {
                header('Location: ../force_password_change.php');
                exit();
            }
            header('Location: dashboard.php');
            exit();
        }
        $error = 'Invalid admin credentials.';
    } catch (Throwable $e) {
        app_log('warning', 'admin.login', 'Admin login request failed.', $e->getMessage());
        $error = $e->getMessage();
    }
}

if (isset($_GET['maintenance']) && isset($_SESSION['maintenance_notice'])) {
    $maintenance_notice = (string)$_SESSION['maintenance_notice'];
    unset($_SESSION['maintenance_notice']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal Login</title>
    <link rel="stylesheet" href="../style.css?v=20260429">
    <script src="../theme.js" defer></script>
</head>
<body class="login-page">
    <div class="auth-shell">
        <section class="auth-showcase">
            <a href="../index.php" class="back-link auth-back-link">← Back to Main Site</a>
            <div class="auth-brand">
                <span class="auth-brand-fallback">MTMO</span>
                <img src="../assets/images/pototan-logo-no-bg.png" alt="Municipal Traffic Management Office Logo" class="auth-brand-logo" onerror="this.style.display='none';">
                <div>
                    <h1>Municipal Traffic Management Office</h1>
                    <p>Admin Control Portal</p>
                </div>
            </div>
            <h2>Administrator Access</h2>
            <p class="auth-intro">For system-wide controls, emergency response, and maintenance management only.</p>
        </section>

        <section class="auth-panel">
            <div class="login-container auth-card">
                <div class="login-header auth-header">
                    <div class="login-role-badge auth-role-badge">System Admin</div>
                    <h2>Admin Sign In</h2>
                    <p>Use your admin account to continue.</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($auth_notice !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($auth_notice); ?></div>
                <?php endif; ?>
                <?php if ($maintenance_notice !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($maintenance_notice); ?></div>
                <?php endif; ?>

                <form method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" placeholder="Enter admin username" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter password" required>
                    </div>
                    <button type="submit" class="btn btn-primary auth-submit">Sign In</button>
                </form>

                <div class="login-footer auth-footer">
                    <p>&copy; <?php echo date('Y'); ?> Traffic Management Unit. All rights reserved.</p>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
