<?php
require_once 'auth.php';
check_role('treasurer');

$conn = get_db_connection();

// Revenue by Month
$revenue_res = $conn->query("SELECT strftime('%Y-%m', payment_date) as month, SUM(payment_amount) as total 
                             FROM payments 
                             GROUP BY month 
                             ORDER BY month DESC LIMIT 12");
$rev_labels = [];
$rev_data = [];
while($row = $revenue_res->fetch_assoc()) {
    $rev_labels[] = $row['month'];
    $rev_data[] = $row['total'];
}

// Revenue by Violation Type
$type_revenue_res = $conn->query("SELECT p.violation_name, SUM(pay.payment_amount) as total 
                                  FROM payments pay 
                                  JOIN violations v ON pay.violation_id = v.id 
                                  JOIN penalties p ON v.penalty_id = p.id 
                                  GROUP BY p.id");
$tr_labels = [];
$tr_data = [];
while($row = $type_revenue_res->fetch_assoc()) {
    $tr_labels[] = $row['violation_name'];
    $tr_data[] = $row['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Analytics - Traffic Management System</title>
    <link rel="stylesheet" href="style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="theme.js" defer></script>
</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>Municipal Treasurer</h2>
            <ul>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="revenue.php" class="active">Revenue Analytics</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </nav>
        <main class="main-content">
            <header>
                <h1>Revenue Analytics</h1>
            </header>

            <div class="stats-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="card">
                    <h3>Monthly Revenue Trend</h3>
                    <canvas id="revenueTrendChart"></canvas>
                </div>
                <div class="card">
                    <h3>Revenue by Violation Type</h3>
                    <canvas id="revenueByTypeChart"></canvas>
                </div>
            </div>
        </main>
    </div>

    <script>
        new Chart(document.getElementById('revenueTrendChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_reverse($rev_labels)); ?>,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: <?php echo json_encode(array_reverse($rev_data)); ?>,
                    backgroundColor: '#28a745'
                }]
            }
        });

        new Chart(document.getElementById('revenueByTypeChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($tr_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($tr_data); ?>,
                    backgroundColor: ['#004a99', '#28a745', '#17a2b8', '#ffc107', '#dc3545']
                }]
            }
        });
    </script>
</body>
</html>
