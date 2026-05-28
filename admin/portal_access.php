<?php
require_once '../auth.php';
check_role('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Access - Admin</title>
    <link rel="stylesheet" href="../style.css?v=20260429">
    <script src="../theme.js" defer></script>
    <style>
        .portal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php $admin_sidebar_active = 'portal_access'; include __DIR__ . '/includes/admin_sidebar.php'; ?>

        <main class="main-content">
            <header>
                <h1>Portal Access</h1>
                <div class="user-info"><?php echo htmlspecialchars((string)$_SESSION['full_name']); ?></div>
            </header>
            <p class="admin-page-intro">Direct access to each portal.</p>

            <div class="card">
                <h2 class="admin-section-title">Direct Portal Launcher</h2>
                <p class="admin-section-subtitle">Open any portal directly for monitoring, emergency handling, or maintenance checks.</p>
                <div class="portal-grid">
                    <a class="btn btn-primary" href="../enforcer/dashboard.php">Traffic Enforcer Portal</a>
                    <a class="btn btn-primary" href="../supervisor/dashboard.php">Supervisor Portal</a>
                    <a class="btn btn-primary" href="../pnp_officer/dashboard.php">PNP Officer Portal</a>
                    <a class="btn btn-primary" href="../treasurer/dashboard.php">Treasurer Portal</a>
                    <a class="btn btn-primary" href="../motorist/dashboard.php">Motorist Portal</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
