<?php
require_once 'auth.php';
check_role('supervisor');

global $pdo;
$conn = $pdo;
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

// Data for Common Violations (Bar Chart)
$violation_types_res = $conn->query("SELECT
                                        COALESCE(
                                            NULLIF(TRIM(p.violation_name), ''),
                                            NULLIF(TRIM(v.violation_details), ''),
                                            'Multiple/Custom'
                                        ) as violation_name,
                                        COUNT(v.id) as count
                                     FROM violations v
                                     LEFT JOIN penalties p ON p.id = v.penalty_id
                                     GROUP BY COALESCE(
                                        NULLIF(TRIM(p.violation_name), ''),
                                        NULLIF(TRIM(v.violation_details), ''),
                                        'Multiple/Custom'
                                     )
                                     ORDER BY count DESC
                                     LIMIT 10");
$vt_labels = [];
$vt_data = [];
while($row = $violation_types_res->fetch(PDO::FETCH_ASSOC)) {
    $vt_labels[] = $row['violation_name'];
    $vt_data[] = $row['count'];
}

// Data for Daily Trends (Line Chart) - last 7 days
$trend_date_expr = $is_mysql ? "DATE(violation_date)" : "date(violation_date)";
$trend_where = $is_mysql ? "violation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" : "date(violation_date) >= date('now', '-7 day')";
$trends_res = $conn->query("SELECT $trend_date_expr as date, COUNT(*) as count
                            FROM violations
                            WHERE $trend_where
                            GROUP BY $trend_date_expr
                            ORDER BY date ASC");
$trend_labels = [];
$trend_data = [];
while($row = $trends_res->fetch(PDO::FETCH_ASSOC)) {
    $trend_labels[] = date('M d', strtotime($row['date']));
    $trend_data[] = $row['count'];
}

// Data for Paid vs Unpaid (Pie Chart)
$paid_count = $conn->query("SELECT COUNT(*) as count FROM violations WHERE status = 'paid'")->fetch(PDO::FETCH_ASSOC)['count'];
$unpaid_count = $conn->query("SELECT COUNT(*) as count FROM violations WHERE status != 'paid'")->fetch(PDO::FETCH_ASSOC)['count'];

// Data for Outstanding Enforcer (most pending violations)
$outstanding_enforcer_res = $conn->query("SELECT u.full_name, COUNT(v.id) as pending_count
                                          FROM users u
                                          JOIN violations v ON u.id = v.enforcer_id
                                          WHERE u.role = 'enforcer' AND v.status = 'pending'
                                          GROUP BY u.id, u.full_name
                                          ORDER BY pending_count DESC
                                          LIMIT 1");
$outstanding_enforcer = $outstanding_enforcer_res->fetch(PDO::FETCH_ASSOC);

// Data for Top Violation Type
$top_violation_res = $conn->query("SELECT
                                    COALESCE(
                                        NULLIF(TRIM(p.violation_name), ''),
                                        NULLIF(TRIM(v.violation_details), ''),
                                        'Multiple/Custom'
                                    ) as violation_name,
                                    COUNT(v.id) as count
                                   FROM violations v
                                   LEFT JOIN penalties p ON p.id = v.penalty_id
                                   GROUP BY COALESCE(
                                        NULLIF(TRIM(p.violation_name), ''),
                                        NULLIF(TRIM(v.violation_details), ''),
                                        'Multiple/Custom'
                                   )
                                   ORDER BY count DESC
                                   LIMIT 1");
$top_violation = $top_violation_res->fetch(PDO::FETCH_ASSOC);

// Fetch enforcer performance for table
$enforcer_perf_res = $conn->query("SELECT u.full_name, COUNT(v.id) as total_violations, 
                                   SUM(CASE WHEN v.status = 'validated' THEN 1 ELSE 0 END) as validated_count,
                                   SUM(CASE WHEN v.status = 'paid' THEN 1 ELSE 0 END) as paid_count
                                   FROM users u
                                   LEFT JOIN violations v ON u.id = v.enforcer_id
                                   WHERE u.role = 'enforcer'
                                   GROUP BY u.id, u.full_name
                                   ORDER BY total_violations DESC");
$enforcer_performance = $enforcer_perf_res->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Traffic Management System</title>
    <link rel="stylesheet" href="style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="theme.js" defer></script>
    <style>
        .charts-container {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 2rem;
                    margin-bottom: 2rem;
                }
                .chart-card {
                    background: white;
                    padding: 1.5rem;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .full-width {
                    grid-column: span 2;
                }
                .highlight-card {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 1.5rem;
                    border-radius: 8px;
                    margin-bottom: 1rem;
                }
                .highlight-card h3 {
                    margin-top: 0;
                    opacity: 0.9;
                }
                .highlight-card .value {
                    font-size: 2rem;
                    font-weight: bold;
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>Traffic Supervisor</h2>
            <ul>
                <li><a href="supervisor/dashboard.php">Dashboard</a></li>
                <li><a href="analytics.php" class="active">Analytics</a></li>
                <li><a href="supervisor/reports.php">Reports</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </nav>
        <main class="main-content">
            <header>
                <h1>Enforcement Analytics</h1>
            </header>

            <!-- Key Highlights -->
            <?php if ($top_violation): ?>
            <div class="highlight-card">
                <h3>Most Common Violation</h3>
                <div class="value"><?php echo htmlspecialchars($top_violation['violation_name']); ?></div>
                <p><?php echo $top_violation['count']; ?> recorded violations</p>
            </div>
            <?php endif; ?>

            <?php if ($outstanding_enforcer): ?>
            <div class="highlight-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <h3>Outstanding Enforcer</h3>
                <div class="value"><?php echo htmlspecialchars($outstanding_enforcer['full_name']); ?></div>
                <p><?php echo $outstanding_enforcer['pending_count']; ?> pending violations</p>
            </div>
            <?php endif; ?>

            <div class="charts-container">
                <div class="chart-card full-width">
                    <h3>Violation Trends (Last 7 Days)</h3>
                    <canvas id="trendsChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3>Most Common Violations</h3>
                    <canvas id="commonViolationsChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3>Payment Status (Paid vs Unpaid)</h3>
                    <canvas id="paymentStatusChart"></canvas>
                </div>
            </div>

            <!-- Enforcer Performance Table -->
            <div class="card">
                <h2>Enforcer Performance</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Enforcer</th>
                            <th>Total Violations</th>
                            <th>Validated</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($enforcer_performance as $ep): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ep['full_name']); ?></td>
                                <td><?php echo $ep['total_violations']; ?></td>
                                <td><?php echo $ep['validated_count']; ?></td>
                                <td><?php echo $ep['paid_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        // Trends Chart
        new Chart(document.getElementById('trendsChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: 'Violations',
                    data: <?php echo json_encode($trend_data); ?>,
                    borderColor: '#004a99',
                    tension: 0.1,
                    fill: true,
                    backgroundColor: 'rgba(0, 74, 153, 0.1)'
                }]
            }
        });

        // Common Violations Chart
        new Chart(document.getElementById('commonViolationsChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($vt_labels); ?>,
                datasets: [{
                    label: 'Count',
                    data: <?php echo json_encode($vt_data); ?>,
                    backgroundColor: '#17a2b8'
                }]
            }
        });

        // Payment Status Chart
        new Chart(document.getElementById('paymentStatusChart'), {
            type: 'pie',
            data: {
                labels: ['Paid', 'Unpaid'],
                datasets: [{
                    data: [<?php echo $paid_count; ?>, <?php echo $unpaid_count; ?>],
                    backgroundColor: ['#28a745', '#dc3545']
                }]
            }
        });
    </script>
</body>
</html>
