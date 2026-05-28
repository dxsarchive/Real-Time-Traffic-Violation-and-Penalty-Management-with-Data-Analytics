<?php
require_once '../includes/auth.php';
check_role('supervisor');

$conn = get_db_connection();
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

// Data for Common Violations (Bar Chart)
$violation_types_res = $conn->query("SELECT p.violation_name, COUNT(v.id) as count 
                                     FROM penalties p 
                                     LEFT JOIN violations v ON p.id = v.penalty_id 
                                     GROUP BY p.id");
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Traffic Management System</title>
    <link rel="stylesheet" href="../../style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../../theme.js" defer></script>
    <style>
        .charts-container {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 2rem;
                }
                .chart-card {
                    background: white;
                    padding: 1.5rem;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.05 );
                }
                .full-width {
                    grid-column: span 2;
                }
                @media (max-width: 900px) {
                    .charts-container { grid-template-columns: 1fr; }
                    .full-width { grid-column: span 1; }
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
<?php
        $supervisor_sidebar_active = '';
        $supervisor_sidebar_path_prefix = '../';
        include __DIR__ . '/../includes/supervisor_sidebar.php';
?>
        <main class="main-content">
            <header>
                <h1>Enforcement Analytics</h1>
            </header>

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
