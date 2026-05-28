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
    <title>Admin Modules</title>
    <link rel="stylesheet" href="../style.css?v=20260429">
    <script src="../theme.js" defer></script>
    <style>
        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
            margin-top: 0.7rem;
        }
        .module-card {
            background: #ffffff;
            border: 1px solid #dbe5f5;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 8px 18px rgba(19, 61, 134, 0.08);
            display: flex;
            flex-direction: column;
            min-height: 180px;
        }
        .module-card h3 {
            margin: 0 0 6px;
            font-size: 1rem;
            color: #173767;
        }
        .module-card p {
            margin: 0 0 10px;
            color: #4e6287;
            font-size: 0.88rem;
            line-height: 1.45;
            flex: 1;
        }
        .module-card .btn {
            width: 100%;
            margin-top: auto;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php $admin_sidebar_active = 'modules'; include __DIR__ . '/includes/admin_sidebar.php'; ?>
        <main class="main-content">
            <header>
                <h1>Operations Hub</h1>
                <div class="user-info"><?php echo htmlspecialchars((string)$_SESSION['full_name']); ?></div>
            </header>
            <p class="admin-page-intro">Quick access to core admin modules.</p>

            <div class="card">
                <h2 class="admin-section-title">Module Launcher</h2>
                <p class="admin-section-subtitle">All administration modules are centralized here for a cleaner control center.</p>
                <div class="module-grid">
                    <div class="module-card">
                        <h3>Security Audit Timeline</h3>
                        <p>Review authentication outcomes and suspicious activity in one place.</p>
                        <a class="btn btn-primary" href="security_events.php">Open Security Events</a>
                    </div>
                    <div class="module-card">
                        <h3>User Lifecycle Control</h3>
                        <p>Manage account activation, deactivation, and controlled access recovery.</p>
                        <a class="btn btn-primary" href="dashboard.php#users-management">Manage Users</a>
                    </div>
                    <div class="module-card">
                        <h3>Role Assignment</h3>
                        <p>Assign and adjust officer roles based on responsibilities.</p>
                        <a class="btn btn-primary" href="dashboard.php#users-management">Assign Roles</a>
                    </div>
                    <div class="module-card">
                        <h3>Database Backup/Restore</h3>
                        <p>Plan backup operations before major updates or maintenance.</p>
                        <a class="btn btn-primary" href="backup_restore.php">Open Module</a>
                    </div>
                    <div class="module-card">
                        <h3>Incident Logbook</h3>
                        <p>Track emergency incidents and system interventions.</p>
                        <a class="btn btn-primary" href="incident_logbook.php">Open Module</a>
                    </div>
                    <div class="module-card">
                        <h3>System Health Monitor</h3>
                        <p>Track operational indicators and service availability.</p>
                        <a class="btn btn-primary" href="system_health.php">Open Module</a>
                    </div>
                    <div class="module-card">
                        <h3>Security Events</h3>
                        <p>Review suspicious activity and login anomalies.</p>
                        <a class="btn btn-primary" href="security_events.php">Open Module</a>
                    </div>
                    <div class="module-card">
                        <h3>Portal Oversight</h3>
                        <p>Jump directly into role portals for monitoring and emergency intervention.</p>
                        <a class="btn btn-primary" href="portal_access.php">Open Portal Access</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
