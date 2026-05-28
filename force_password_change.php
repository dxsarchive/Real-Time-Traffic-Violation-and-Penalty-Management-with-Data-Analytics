<?php
require_once 'auth.php';

if (!is_logged_in()) {
    header('Location: index.php?roles=1');
    exit();
}

if (empty($_SESSION['must_change_password'])) {
    header('Location: ' . role_dashboard_path($_SESSION['role'] ?? ''));
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = (string)($_POST['current_password'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            throw new RuntimeException('All password fields are required.');
        }
        if (strlen($new_password) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.');
        }
        if ($new_password !== $confirm_password) {
            throw new RuntimeException('New password and confirmation do not match.');
        }
        if ($new_password === $current_password) {
            throw new RuntimeException('New password must be different from your current password.');
        }

        global $pdo;
        ensure_user_password_policy_columns();
        $user_id = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($current_password, (string)$user['password'])) {
            throw new RuntimeException('Current password is incorrect.');
        }

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update_stmt->execute([$new_hash, $user_id]);

        $_SESSION['must_change_password'] = false;
        $success = 'Password updated successfully. Redirecting...';
        $redirect_to = role_dashboard_path($_SESSION['role'] ?? '');
        header('Refresh: 1.2; url=' . $redirect_to);
    } catch (Throwable $e) {
        app_log('warning', 'auth.force_password_change', 'Forced password change failed.', $e->getMessage());
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Temporary Password</title>
    <link rel="stylesheet" href="style.css?v=20260429">
    <script src="theme.js" defer></script>
</head>
<body class="login-page">
    <div class="auth-shell">
        <section class="auth-showcase">
            <div class="auth-brand">
                <span class="auth-brand-fallback">MTMO</span>
                <img src="assets/images/pototan-logo-no-bg.png" alt="Municipal Traffic Management Office Logo" class="auth-brand-logo" onerror="this.style.display='none';">
                <div>
                    <h1>Security Update Required</h1>
                    <p>First Login Password Reset</p>
                </div>
            </div>
            <h2>Set Your New Password</h2>
            <p class="auth-intro">Your account is currently using a temporary password. Please set a new secure password to continue.</p>
        </section>
        <section class="auth-panel">
            <div class="login-container auth-card">
                <div class="login-header auth-header">
                    <div class="login-role-badge auth-role-badge">Required Action</div>
                    <h2>Change Password</h2>
                    <p>Account: <?php echo htmlspecialchars((string)($_SESSION['username'] ?? '')); ?></p>
                </div>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <form method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary auth-submit">Update Password</button>
                </form>
            </div>
        </section>
    </div>
</body>
</html>
