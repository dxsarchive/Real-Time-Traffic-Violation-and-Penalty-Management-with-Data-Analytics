<?php
require_once '../auth.php';
check_role('treasurer');

global $pdo;
$conn = $pdo;
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

// Revenue by Month (driver compatible)
$month_expr = $is_mysql ? "DATE_FORMAT(payment_date, '%Y-%m')" : "strftime('%Y-%m', payment_date)";
$revenue_res = $conn->query("SELECT $month_expr as month, SUM(payment_amount) as total
                             FROM payments
                             GROUP BY $month_expr
                             ORDER BY month DESC LIMIT 12");
$rev_labels = [];
$rev_data = [];
while($row = $revenue_res->fetch(PDO::FETCH_ASSOC)) {
    $rev_labels[] = $row['month'];
    $rev_data[] = $row['total'];
}

// Revenue by Violation Type (include records without penalty_id)
$type_revenue_res = $conn->query("SELECT COALESCE(
                                        NULLIF(TRIM(v.violation_details), ''),
                                        p.violation_name,
                                        'Multiple/Custom'
                                   ) AS violation_name,
                                   SUM(pay.payment_amount) AS total
                                  FROM payments pay
                                  JOIN violations v ON pay.violation_id = v.id
                                  LEFT JOIN penalties p ON v.penalty_id = p.id
                                  GROUP BY COALESCE(
                                        NULLIF(TRIM(v.violation_details), ''),
                                        p.violation_name,
                                        'Multiple/Custom'
                                  )
                                  ORDER BY total DESC");
$tr_labels = [];
$tr_data = [];
while($row = $type_revenue_res->fetch(PDO::FETCH_ASSOC)) {
    $tr_labels[] = $row['violation_name'];
    $tr_data[] = (float)$row['total'];
}

// Top Violations by Type (count, include records without penalty_id)
// Split comma-separated violation_details so each violation is charted separately.
$top_violations_res = $conn->query("SELECT COALESCE(
                                        NULLIF(TRIM(v.violation_details), ''),
                                        p.violation_name,
                                        'Multiple/Custom'
                                    ) AS violation_name
                                    FROM violations v
                                    LEFT JOIN penalties p ON v.penalty_id = p.id");
$top_violation_counts = [];
while($row = $top_violations_res->fetch(PDO::FETCH_ASSOC)) {
    $raw_name = trim((string)$row['violation_name']);
    if ($raw_name === '') {
        $raw_name = 'Multiple/Custom';
    }

    $split_names = preg_split('/\s*,\s*/', $raw_name, -1, PREG_SPLIT_NO_EMPTY);
    if (!$split_names || count($split_names) === 0) {
        $split_names = [$raw_name];
    }

    foreach ($split_names as $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        if (!isset($top_violation_counts[$name])) {
            $top_violation_counts[$name] = 0;
        }
        $top_violation_counts[$name]++;
    }
}

arsort($top_violation_counts);
$top_violation_counts = array_slice($top_violation_counts, 0, 10, true);
$tv_labels = array_keys($top_violation_counts);
$tv_data = array_values($top_violation_counts);

// Additional Stats
$total_revenue = $conn->query("SELECT SUM(payment_amount) as total FROM payments")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$total_transactions = $conn->query("SELECT COUNT(*) as count FROM payments")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
$avg_transaction = $total_transactions > 0 ? $total_revenue / $total_transactions : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Analytics - Traffic Management System</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../theme.js" defer></script>
    <style>
        .stat-card {
                    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
                    border-radius: 16px;
                    padding: 1.5rem;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
                    transition: transform 0.3s ease, box-shadow 0.3s ease;
                }
                .stat-card:hover {
                    transform: translateY(-5px);
                    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
                }
                .stat-icon {
                    width: 60px;
                    height: 60px;
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 1.5rem;
                }
                .stat-icon.total {
                    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
                }
                .stat-icon.transactions {
                    background: linear-gradient(135deg, #cce5ff 0%, #b8daff 100%);
                }
                .stat-icon.average {
                    background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
                }
                .stat-content h3 {
                    font-size: 0.85rem;
                    color: #6c757d;
                    margin-bottom: 0.25rem;
                    font-weight: 500;
                }
                .stat-content .value {
                    font-size: 1.75rem;
                    font-weight: 700;
                    color: #212529;
                }
                .card {
                    background: #fff;
                    border-radius: 16px;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
                    margin-bottom: 1.5rem;
                    overflow: hidden;
                }
                .card-header {
                    background: linear-gradient(135deg, #004a99 0%, #007bff 100%);
                    color: white;
                    padding: 1rem 1.5rem;
                    border-radius: 16px 16px 0 0;
                }
                .card-header h3 {
                    margin: 0;
                    font-size: 1.25rem;
                }
                .card-body {
                    padding: 1.5rem;
                }
                .chart-container {
                    position: relative;
                    height: 350px;
                }
                html[data-theme="dark"] .stat-content h3 {
                    color: #c3d2ee;
                }
                html[data-theme="dark"] .stat-content .value {
                    color: #f2f6ff;
                }
                html[data-theme="dark"] .card-body,
                html[data-theme="dark"] .card-body p,
                html[data-theme="dark"] .card-body span {
                    color: #e6edfb;
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>💼 Municipal Treasurer</h2>
            <ul>
                <li><a href="dashboard.php">📊 Dashboard</a></li>
                <li><a href="payment_history.php">🧾 Payment History</a></li>
                <li><a href="revenue.php" class="active">📈 Revenue Analytics</a></li>
                <li><a href="../logout.php">🚪 Logout</a></li>
            </ul>
        </nav>
        <main class="main-content">
            <header>
                <h1>📈 Revenue Analytics</h1>
                <div class="user-info">👤 <?php echo $_SESSION['full_name']; ?></div>
            </header>

            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                <div class="stat-card">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div class="stat-icon total">💰</div>
                        <div class="stat-content">
                            <h3>Total Revenue</h3>
                            <div class="value">₱<?php echo number_format($total_revenue, 2); ?></div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div class="stat-icon transactions">📊</div>
                        <div class="stat-content">
                            <h3>Total Transactions</h3>
                            <div class="value"><?php echo $total_transactions; ?></div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div class="stat-icon average">📈</div>
                        <div class="stat-content">
                            <h3>Avg. Transaction</h3>
                            <div class="value">₱<?php echo number_format($avg_transaction, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 1.5rem;">
                <div class="card">
                    <div class="card-header">
                        <h3>📊 Monthly Revenue Trend</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="revenueTrendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3>🥧 Revenue by Violation Type</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="revenueByTypeChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3>🚨 Top Violations by Type</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="topViolationsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
        const chartTextColor = isDarkTheme ? '#e6edfb' : '#334155';
        const chartGridColor = isDarkTheme ? 'rgba(148, 163, 184, 0.25)' : 'rgba(148, 163, 184, 0.2)';

        // Monthly Revenue Trend Chart
        new Chart(document.getElementById('revenueTrendChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_reverse($rev_labels)); ?>,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: <?php echo json_encode(array_reverse($rev_data)); ?>,
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                    borderColor: '#28a745',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: chartTextColor
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: chartTextColor
                        },
                        grid: {
                            color: chartGridColor
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: chartTextColor,
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        },
                        grid: {
                            color: chartGridColor
                        }
                    }
                }
            }
        });

        // Revenue by Type Chart
        new Chart(document.getElementById('revenueByTypeChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($tr_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($tr_data); ?>,
                    backgroundColor: [
                        '#004a99',
                        '#28a745',
                        '#17a2b8',
                        '#ffc107',
                        '#dc3545',
                        '#6c757d',
                        '#20c997'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: chartTextColor,
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                }
            }
        });

        // Top Violations by Type Chart
        new Chart(document.getElementById('topViolationsChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($tv_labels); ?>,
                datasets: [{
                    label: 'Number of Violations',
                    data: <?php echo json_encode($tv_data); ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.8)',
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(255, 206, 86, 0.8)',
                        'rgba(75, 192, 192, 0.8)',
                        'rgba(153, 102, 255, 0.8)',
                        'rgba(255, 159, 64, 0.8)',
                        'rgba(199, 199, 199, 0.8)',
                        'rgba(83, 102, 255, 0.8)',
                        'rgba(40, 159, 64, 0.8)',
                        'rgba(210, 99, 132, 0.8)'
                    ],
                    borderColor: [
                        'rgb(255, 99, 132)',
                        'rgb(54, 162, 235)',
                        'rgb(255, 206, 86)',
                        'rgb(75, 192, 192)',
                        'rgb(153, 102, 255)',
                        'rgb(255, 159, 64)',
                        'rgb(199, 199, 199)',
                        'rgb(83, 102, 255)',
                        'rgb(40, 159, 64)',
                        'rgb(210, 99, 132)'
                    ],
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: chartTextColor
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            color: chartTextColor,
                            callback: function(value) {
                                return value;
                            }
                        },
                        grid: {
                            color: chartGridColor
                        }
                    },
                    y: {
                        ticks: {
                            color: chartTextColor
                        },
                        grid: {
                            color: chartGridColor
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
