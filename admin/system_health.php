<?php
require_once '../auth.php';
check_role('admin');

$conn = get_db_connection();
$maintenance = get_maintenance_state();
$db_ok = true;
$db_message = 'Connected';
$metrics = [
    'users' => 0,
    'violations' => 0,
    'pending_violations' => 0,
    'validated_violations' => 0,
    'paid_violations' => 0,
    'payments' => 0,
    'incidents_open' => 0
];
$recent_failures = [];

try {
    $conn->query("SELECT 1");
} catch (Throwable $e) {
    $db_ok = false;
    $db_message = 'Database connection check failed.';
}

if ($db_ok) {
    try {
        $metrics['users'] = (int)($conn->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0);
        $metrics['violations'] = (int)($conn->query("SELECT COUNT(*) FROM violations")->fetchColumn() ?: 0);
        $metrics['pending_violations'] = (int)($conn->query("SELECT COUNT(*) FROM violations WHERE status = 'pending'")->fetchColumn() ?: 0);
        $metrics['validated_violations'] = (int)($conn->query("SELECT COUNT(*) FROM violations WHERE status = 'validated'")->fetchColumn() ?: 0);
        $metrics['paid_violations'] = (int)($conn->query("SELECT COUNT(*) FROM violations WHERE status = 'paid'")->fetchColumn() ?: 0);
        $metrics['payments'] = (int)($conn->query("SELECT COUNT(*) FROM payments")->fetchColumn() ?: 0);

        try {
            $metrics['incidents_open'] = (int)($conn->query("SELECT COUNT(*) FROM incident_logbook WHERE status <> 'resolved'")->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $metrics['incidents_open'] = 0;
        }

        ensure_security_events_table();
        $recent_failures_stmt = $conn->query("SELECT event_type, username, ip_address, created_at
                                              FROM security_events
                                              WHERE is_success = 0
                                              ORDER BY id DESC
                                              LIMIT 10");
        $recent_failures = $recent_failures_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $db_ok = false;
        $db_message = 'Error while loading health metrics.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health Monitor - Admin</title>
    <link rel="stylesheet" href="../style.css?v=20260429">
    <script src="../theme.js" defer></script>
    <style>
        .health-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px;
            margin-bottom: 1rem;
        }
        .health-card {
            background: #fff;
            border: 1px solid #dbe5f5;
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 8px 18px rgba(19, 61, 134, 0.08);
        }
        .health-card h3 {
            margin: 0 0 6px;
            font-size: 0.92rem;
            color: #2b487a;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .health-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #153461;
            line-height: 1.1;
        }
        .status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .status-ok {
            color: #23623d;
            background: #eafff1;
            border-color: #c5efd4;
        }
        .status-bad {
            color: #8a1d1d;
            background: #ffecec;
            border-color: #f4c8c8;
        }
        .status-warn {
            color: #7d5c12;
            background: #fff8e7;
            border-color: #f6e5b4;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php $admin_sidebar_active = 'system_health'; include __DIR__ . '/includes/admin_sidebar.php'; ?>

        <main class="main-content">
            <header>
                <h1>System Health Monitor</h1>
                <div class="user-info"><?php echo htmlspecialchars((string)$_SESSION['full_name']); ?></div>
            </header>
            <p class="admin-page-intro">View key health and service indicators.</p>

            <div class="card">
                <h2 class="admin-section-title">Service Status</h2>
                <p>
                    <strong>Database:</strong>
                    <span class="status-pill <?php echo $db_ok ? 'status-ok' : 'status-bad'; ?>">
                        <?php echo $db_ok ? 'HEALTHY' : 'ISSUE'; ?>
                    </span>
                    <span class="admin-inline-note"><?php echo htmlspecialchars($db_message); ?></span>
                </p>
                <p>
                    <strong>Maintenance Mode:</strong>
                    <span class="status-pill <?php echo !empty($maintenance['enabled']) ? 'status-warn' : 'status-ok'; ?>">
                        <?php echo !empty($maintenance['enabled']) ? 'ENABLED' : 'DISABLED'; ?>
                    </span>
                    <?php if (!empty($maintenance['updated_at'])): ?>
                        <span class="admin-inline-note">Last change: <?php echo htmlspecialchars((string)$maintenance['updated_at']); ?></span>
                    <?php endif; ?>
                </p>
            </div>

            <div class="health-grid">
                <div class="health-card"><h3>Total Users</h3><div class="health-value"><?php echo number_format($metrics['users']); ?></div></div>
                <div class="health-card"><h3>Total Violations</h3><div class="health-value"><?php echo number_format($metrics['violations']); ?></div></div>
                <div class="health-card"><h3>Pending Violations</h3><div class="health-value"><?php echo number_format($metrics['pending_violations']); ?></div></div>
                <div class="health-card"><h3>Validated Violations</h3><div class="health-value"><?php echo number_format($metrics['validated_violations']); ?></div></div>
                <div class="health-card"><h3>Paid Violations</h3><div class="health-value"><?php echo number_format($metrics['paid_violations']); ?></div></div>
                <div class="health-card"><h3>Total Payments</h3><div class="health-value"><?php echo number_format($metrics['payments']); ?></div></div>
                <div class="health-card"><h3>Open Incidents</h3><div class="health-value"><?php echo number_format($metrics['incidents_open']); ?></div></div>
            </div>

            <div class="card">
                <h2 class="admin-section-title">Recent Failed Security Events</h2>
                <div class="table-scroll">
                    <table class="admin-data-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Event Type</th>
                                <th>Username</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_failures)): ?>
                                <?php foreach ($recent_failures as $event): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$event['created_at']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$event['event_type']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$event['username']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$event['ip_address']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4"><div class="admin-empty-state"><strong>No failed security events recorded.</strong> System appears stable for this log window.</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
