<?php
require_once '../auth.php';
check_role('supervisor');

global $pdo;
$conn = $pdo;
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

function split_violation_items($raw_violation) {
    $raw_violation = trim((string)$raw_violation);
    if ($raw_violation === '') {
        return ['Multiple/Custom'];
    }
    $items = array_values(array_filter(array_map('trim', explode(',', $raw_violation)), fn($item) => $item !== ''));
    return !empty($items) ? $items : ['Multiple/Custom'];
}

function table_has_column(PDO $conn, bool $is_mysql, string $table_name, string $column_name): bool {
    try {
        if ($is_mysql) {
            $stmt = $conn->prepare("SHOW COLUMNS FROM `$table_name` LIKE ?");
            $stmt->execute([$column_name]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        }

        $pragma_stmt = $conn->query("PRAGMA table_info($table_name)");
        $columns = $pragma_stmt ? $pragma_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === $column_name) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $violations_res = $conn->query("SELECT v.id, v.violation_date, v.location, v.fine_amount, v.status, v.top_number,
                                     m.full_name as motorist_name, m.license_number,
                                     COALESCE(p.violation_name, v.violation_details, 'Multiple/Custom') as violation_display,
                                     u.full_name as enforcer_name
                                     FROM violations v
                                     LEFT JOIN motorists m ON v.motorist_id = m.id
                                     LEFT JOIN penalties p ON v.penalty_id = p.id
                                     LEFT JOIN users u ON v.enforcer_id = u.id
                                     ORDER BY v.violation_date DESC");
    $violations = $violations_res->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="violations_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    // Office header (CSV supports text only; logo labels are placeholders)
    fputcsv($output, ['Republic of the Philippines']);
    fputcsv($output, ['Province of Iloilo']);
    fputcsv($output, ['Municipality of Pototan']);
    fputcsv($output, ['MUNICIPAL TRAFFIC MANAGEMENT OFFICE']);
    fputcsv($output, ['2nd Floor Old Market, RY Ladrido Street']);
    fputcsv($output, ['Brgy. San Jose, Pototan, Iloilo']);
    fputcsv($output, []);
    fputcsv($output, []);
    fputcsv($output, ['ID', 'Date', 'TOP Number', 'Motorist Name', 'License Number', 'Violation Type', 'Location', 'Amount', 'Status', 'Enforcer']);
    
    foreach ($violations as $v) {
        fputcsv($output, [
            $v['id'],
            date('Y-m-d H:i', strtotime($v['violation_date'])),
            $v['top_number'],
            $v['motorist_name'],
            $v['license_number'],
            $v['violation_display'],
            $v['location'],
            $v['fine_amount'],
            $v['status'],
            $v['enforcer_name']
        ]);
    }

    // Signatories
    fputcsv($output, []);
    fputcsv($output, ['Prepared by', '', 'Approved by']);
    fputcsv($output, []);
    fputcsv($output, ['ROSEMARIE DE LA PENA', '', 'RODEL L. PATRIARCA']);
    fputcsv($output, ['TRAFFIC Administrator', '', 'TRAFFIC Supervisor']);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    
    fclose($output);
    exit;
}

// Fetch summary data
$summary = [];

// Total violations
$summary['total'] = $conn->query("SELECT COUNT(*) as count FROM violations")->fetch(PDO::FETCH_ASSOC)['count'];

// By status
$status_counts = $conn->query("SELECT status, COUNT(*) as count FROM violations GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$summary['by_status'] = [];
foreach ($status_counts as $s) {
    $summary['by_status'][$s['status']] = $s['count'];
}

// By violation type
$type_counts = $conn->query("SELECT
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
                             LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$summary['by_type'] = $type_counts;

// Monthly trends (driver compatible)
$month_expr = $is_mysql ? "DATE_FORMAT(violation_date, '%Y-%m')" : "strftime('%Y-%m', violation_date)";
$monthly = $conn->query("SELECT $month_expr as month, COUNT(*) as count
                         FROM violations
                         GROUP BY $month_expr
                         ORDER BY month DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$summary['monthly'] = $monthly;

// Top locations
$locations = $conn->query("SELECT location, COUNT(*) as count 
                           FROM violations 
                           GROUP BY location 
                           ORDER BY count DESC 
                           LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$summary['top_locations'] = $locations;
$top_location_labels = [];
$top_location_counts = [];
foreach ($summary['top_locations'] as $loc_row) {
    $label = trim((string)($loc_row['location'] ?? ''));
    $top_location_labels[] = $label !== '' ? $label : 'Unknown Location';
    $top_location_counts[] = (int)($loc_row['count'] ?? 0);
}

// Age group analytics (minor/adult/senior/unknown)
$has_dob_column = table_has_column($conn, $is_mysql, 'motorists', 'date_of_birth');
$age_rows = [];
if ($has_dob_column) {
    $age_rows = $conn->query("SELECT m.date_of_birth
                              FROM violations v
                              LEFT JOIN motorists m ON v.motorist_id = m.id")->fetchAll(PDO::FETCH_ASSOC);
}
$age_group_counts = [
    'Minor (Below 18)' => 0,
    'Young Adult (18-24)' => 0,
    'Adult (25-59)' => 0,
    'Senior (60+)' => 0,
    'Unknown Age' => 0
];
foreach ($age_rows as $age_row) {
    $dob_raw = trim((string)($age_row['date_of_birth'] ?? ''));
    if ($dob_raw === '') {
        $age_group_counts['Unknown Age']++;
        continue;
    }
    $dob_ts = strtotime($dob_raw);
    if (!$dob_ts) {
        $age_group_counts['Unknown Age']++;
        continue;
    }
    $today_ts = strtotime(date('Y-m-d'));
    $age_years = (int)floor(($today_ts - $dob_ts) / (365.25 * 24 * 60 * 60));
    if ($age_years < 0 || $age_years > 120) {
        $age_group_counts['Unknown Age']++;
    } elseif ($age_years < 18) {
        $age_group_counts['Minor (Below 18)']++;
    } elseif ($age_years <= 24) {
        $age_group_counts['Young Adult (18-24)']++;
    } elseif ($age_years <= 59) {
        $age_group_counts['Adult (25-59)']++;
    } else {
        $age_group_counts['Senior (60+)']++;
    }
}
$age_labels = array_keys($age_group_counts);
$age_counts = array_values($age_group_counts);

// Revenue summary
$revenue = $conn->query("SELECT SUM(payment_amount) as total FROM payments")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$summary['total_revenue'] = $revenue;

// --- Analytics transferred from dashboard ---
$total_v = (int)$summary['total'];
$pending_v = (int)($summary['by_status']['pending'] ?? 0);
$validated_v = (int)($summary['by_status']['validated'] ?? 0);
$paid_v = (int)($summary['by_status']['paid'] ?? 0);
$collection_rate = $total_v > 0 ? round((($validated_v + $paid_v) / $total_v) * 100, 1) : 0;

$today_filter = $is_mysql ? "DATE(violation_date) = CURDATE()" : "date(violation_date) = date('now')";
$week_filter = $is_mysql ? "YEARWEEK(violation_date, 1) = YEARWEEK(CURDATE(), 1)" : "date(violation_date) >= date('now', '-6 day')";
$month_filter = $is_mysql ? "DATE_FORMAT(violation_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" : "strftime('%Y-%m', violation_date) = strftime('%Y-%m', 'now')";

$daily_total = (int)$conn->query("SELECT COUNT(*) AS count FROM violations WHERE $today_filter")->fetch(PDO::FETCH_ASSOC)['count'];
$weekly_total = (int)$conn->query("SELECT COUNT(*) AS count FROM violations WHERE $week_filter")->fetch(PDO::FETCH_ASSOC)['count'];
$monthly_total = (int)$conn->query("SELECT COUNT(*) AS count FROM violations WHERE $month_filter")->fetch(PDO::FETCH_ASSOC)['count'];

$date_expr = $is_mysql ? "DATE(violation_date)" : "date(violation_date)";
$date_filter = $is_mysql ? "violation_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)" : "date(violation_date) >= date('now', '-6 day')";
$weekly_stmt = $conn->query("SELECT $date_expr as violation_day, COUNT(*) as count
                             FROM violations
                             WHERE $date_filter
                             GROUP BY $date_expr
                             ORDER BY violation_day ASC");
$weekly_map = [];
foreach ($weekly_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $weekly_map[$row['violation_day']] = (int)$row['count'];
}
$trend_labels = [];
$trend_data = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $trend_labels[] = strtoupper(date('D', strtotime($day)));
    $trend_data[] = $weekly_map[$day] ?? 0;
}

$common_violations_map = [];
$common_rows = $conn->query("SELECT COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') AS violation_text
                             FROM violations v
                             LEFT JOIN penalties p ON v.penalty_id = p.id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($common_rows as $row) {
    foreach (split_violation_items($row['violation_text'] ?? '') as $item) {
        $common_violations_map[$item] = ($common_violations_map[$item] ?? 0) + 1;
    }
}
arsort($common_violations_map);
$common_violations = array_slice($common_violations_map, 0, 6, true);
$common_violation_data = array_values($common_violations);

$month_group_expr = $is_mysql ? "DATE_FORMAT(violation_date, '%Y-%m')" : "strftime('%Y-%m', violation_date)";
$monthly_trend_rows = $conn->query("SELECT $month_group_expr AS month_key, COUNT(*) AS count
                                    FROM violations
                                    GROUP BY $month_group_expr
                                    ORDER BY month_key DESC
                                    LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$monthly_trend_rows = array_reverse($monthly_trend_rows);
$monthly_trend_labels = [];
$monthly_trend_data = [];
foreach ($monthly_trend_rows as $row) {
    $month_ts = strtotime($row['month_key'] . '-01');
    $monthly_trend_labels[] = $month_ts ? date('M Y', $month_ts) : $row['month_key'];
    $monthly_trend_data[] = (int)$row['count'];
}

$paid_count = (int)$conn->query("SELECT COUNT(*) AS count FROM violations WHERE status = 'paid'")->fetch(PDO::FETCH_ASSOC)['count'];
$unpaid_count = max(0, (int)$total_v - $paid_count);
$paid_amount = (float)$conn->query("SELECT COALESCE(SUM(fine_amount), 0) AS total FROM violations WHERE status = 'paid'")->fetch(PDO::FETCH_ASSOC)['total'];
$unpaid_amount = (float)$conn->query("SELECT COALESCE(SUM(fine_amount), 0) AS total FROM violations WHERE status <> 'paid'")->fetch(PDO::FETCH_ASSOC)['total'];
$collection_completion = $total_v > 0 ? round(($paid_count / $total_v) * 100, 1) : 0;

$outstanding_enforcer_res = $conn->query("SELECT u.full_name, COUNT(v.id) as pending_count
                                          FROM users u
                                          JOIN violations v ON u.id = v.enforcer_id
                                          WHERE u.role = 'enforcer' AND v.status = 'pending'
                                          GROUP BY u.id, u.full_name
                                          ORDER BY pending_count DESC
                                          LIMIT 1");
$outstanding_enforcer = $outstanding_enforcer_res->fetch(PDO::FETCH_ASSOC);

$flagged_stmt = $conn->query("SELECT v.violation_date, v.top_number, COALESCE(v.violation_details, p.violation_name, 'Violation Record') as violation_display,
                                     COALESCE(v.location, 'Unknown location') as location, v.status
                              FROM violations v
                              LEFT JOIN penalties p ON v.penalty_id = p.id
                              WHERE v.status IN ('pending', 'rejected')
                              ORDER BY v.violation_date DESC
                              LIMIT 3");
$recent_flagged = $flagged_stmt->fetchAll(PDO::FETCH_ASSOC);

$hour_expr = $is_mysql ? "HOUR(violation_date)" : "CAST(strftime('%H', violation_date) AS INTEGER)";
$peak_hours = $conn->query("SELECT $hour_expr AS hour_of_day, COUNT(*) AS count
                            FROM violations
                            GROUP BY $hour_expr
                            ORDER BY count DESC
                            LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$repeat_offenders = $conn->query("SELECT m.full_name, m.license_number, m.plate, COUNT(v.id) AS violation_count
                                  FROM violations v
                                  JOIN motorists m ON v.motorist_id = m.id
                                  GROUP BY m.id, m.full_name, m.license_number, m.plate
                                  HAVING COUNT(v.id) > 1
                                  ORDER BY violation_count DESC, m.full_name ASC
                                  LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$enforcer_performance = $conn->query("SELECT u.full_name, COUNT(v.id) as total_violations,
                                      SUM(CASE WHEN v.status = 'validated' THEN 1 ELSE 0 END) as validated_count,
                                      SUM(CASE WHEN v.status = 'paid' THEN 1 ELSE 0 END) as paid_count
                                      FROM users u
                                      LEFT JOIN violations v ON u.id = v.enforcer_id
                                      WHERE u.role = 'enforcer'
                                      GROUP BY u.id, u.full_name
                                      ORDER BY total_violations DESC")->fetchAll(PDO::FETCH_ASSOC);

// Recent violations for table
$recent = $conn->query("SELECT v.*, COALESCE(m.full_name, 'Unknown') as motorist_name, COALESCE(p.violation_name, v.violation_details, 'Multiple/Custom') as violation_display 
                        FROM violations v
                        LEFT JOIN motorists m ON v.motorist_id = m.id
                        LEFT JOIN penalties p ON v.penalty_id = p.id
                        ORDER BY v.violation_date DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports and Analytics- Traffic Management System</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../theme.js" defer></script>
    <style>
        .analytics-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1rem 0;}
        .analytics-panel{padding:1.2rem}
        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1rem}
        .kpi-card{padding:1rem 1.1rem}
        .kpi-label{font-size:.9rem;opacity:.9}
        .kpi-value{font-size:1.8rem;font-weight:800}
        .events-panel{padding:1.2rem}
        .event-row{display:grid;grid-template-columns:1fr auto auto;gap:.8rem;align-items:center;padding:.65rem 0;border-top:1px solid var(--border-color)}
        .status-pill{padding:.2rem .55rem;border-radius:999px;font-size:.75rem;font-weight:700}
        .status-pill-critical{background:#ffd8d8;color:#8a1f25}
        .status-pill-standard{background:#dbe7ff;color:#143f8a}
        .violation-chips{display:flex;flex-wrap:wrap;gap:.3rem}
        .violation-chip{display:inline-block;background:#e8f0ff;color:#103f92;border:1px solid #c7d7f8;border-radius:999px;padding:.15rem .55rem;font-size:.78rem}
        @media (max-width:980px){.analytics-grid{grid-template-columns:1fr}.event-row{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="dashboard-container">
<?php $supervisor_sidebar_active = 'reports'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
        <main class="main-content">
            <header>
                <h1>Violation Reports & Summaries</h1>
                <div class="user-info"><?php echo $_SESSION['full_name']; ?></div>
            </header>

            <div class="kpi-grid">
                <div class="card kpi-card">
                    <div class="kpi-label">Daily Violations</div>
                    <div class="kpi-value"><?php echo number_format($daily_total); ?></div>
                </div>
                <div class="card kpi-card">
                    <div class="kpi-label">Weekly Violations</div>
                    <div class="kpi-value"><?php echo number_format($weekly_total); ?></div>
                </div>
                <div class="card kpi-card">
                    <div class="kpi-label">Monthly Violations</div>
                    <div class="kpi-value"><?php echo number_format($monthly_total); ?></div>
                </div>
                <div class="card kpi-card">
                    <div class="kpi-label">Collection Completion</div>
                    <div class="kpi-value"><?php echo number_format($collection_completion, 1); ?>%</div>
                </div>
            </div>

            <div style="margin: 20px 0;">
                <a href="?export=csv" class="btn" style="background: var(--secondary-color); color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">Export to CSV</a>
            </div>

            <div class="analytics-grid">
                <div class="card analytics-panel">
                    <h3>Violation Trends</h3>
                    <p>Weekly distribution of recorded incidents</p>
                    <canvas id="trendChart" height="140"></canvas>
                </div>
                <div class="card analytics-panel">
                    <h3>Most Common Traffic Violations</h3>
                    <?php if (empty($common_violations)): ?>
                        <p>No violation data yet.</p>
                    <?php else: ?>
                        <?php foreach ($common_violations as $label => $count): ?>
                            <?php $max_count = !empty($common_violation_data) ? max($common_violation_data) : 1; $pct = $max_count > 0 ? round(($count / $max_count) * 100, 1) : 0; ?>
                            <div style="margin-bottom:.7rem;">
                                <div style="display:flex;justify-content:space-between;font-size:.86rem;margin-bottom:.22rem;">
                                    <span><?php echo htmlspecialchars($label); ?></span>
                                    <span><?php echo number_format((int)$count); ?> cases</span>
                                </div>
                                <div style="height:10px;border-radius:999px;background:#d9e2f5;overflow:hidden;">
                                    <div style="height:100%;width:<?php echo $pct; ?>%;background:#0f56c3;border-radius:999px;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="analytics-grid">
                <div class="card analytics-panel">
                    <h3>Monthly Violation Trend</h3>
                    <canvas id="monthlyTrendChart" height="150"></canvas>
                </div>
                <div class="card analytics-panel">
                    <h3>Penalty Collection Status</h3>
                    <canvas id="collectionStatusChart" height="150"></canvas>
                    <p style="margin-top:.65rem;">Paid: <?php echo number_format($paid_count); ?> (₱<?php echo number_format($paid_amount, 2); ?>) | Unpaid: <?php echo number_format($unpaid_count); ?> (₱<?php echo number_format($unpaid_amount, 2); ?>)</p>
                </div>
            </div>

            <div class="analytics-grid">
                <div class="card analytics-panel">
                    <h3>Top Violation Locations</h3>
                    <p>Locations with the highest number of recorded violations.</p>
                    <canvas id="topLocationsChart" height="150"></canvas>
                </div>
                <div class="card analytics-panel">
                    <h3>Violator Age Group Distribution</h3>
                    <p>Identifies minors and other age brackets from available birth dates.</p>
                    <canvas id="ageGroupChart" height="150"></canvas>
                </div>
            </div>

            <div class="card events-panel">
                <h3>Recent Flagged Events</h3>
                <?php if (empty($recent_flagged)): ?>
                    <p>No flagged events available.</p>
                <?php else: ?>
                    <?php foreach ($recent_flagged as $event): ?>
                        <div class="event-row">
                            <div>
                                <div><strong><?php echo htmlspecialchars($event['violation_display']); ?></strong></div>
                                <div><?php echo htmlspecialchars($event['location']); ?> • TOP: <?php echo htmlspecialchars($event['top_number'] ?: 'N/A'); ?></div>
                            </div>
                            <div><?php echo date('H:i:s M d', strtotime($event['violation_date'])); ?></div>
                            <div>
                                <span class="status-pill <?php echo $event['status'] === 'rejected' ? 'status-pill-critical' : 'status-pill-standard'; ?>">
                                    <?php echo $event['status'] === 'rejected' ? 'CRITICAL' : 'STANDARD'; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($outstanding_enforcer): ?>
            <div class="card">
                <h2>Most Outstanding Enforcer</h2>
                <p><strong><?php echo $outstanding_enforcer['full_name']; ?></strong> has <strong><?php echo $outstanding_enforcer['pending_count']; ?></strong> pending violations.</p>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3>Top Locations</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th>Violation Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($summary['top_locations'] as $loc): ?>
                            <tr>
                                <td><?php echo $loc['location']; ?></td>
                                <td><?php echo $loc['count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Peak Hours of Violations</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Hour Window</th>
                            <th>Violation Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($peak_hours)): ?>
                            <tr><td colspan="2">No peak-hour data available.</td></tr>
                        <?php else: ?>
                            <?php foreach ($peak_hours as $row): ?>
                                <?php $hour = (int)$row['hour_of_day']; $next = ($hour + 1) % 24; ?>
                                <tr>
                                    <td><?php echo sprintf('%02d:00 - %02d:00', $hour, $next); ?></td>
                                    <td><?php echo number_format((int)$row['count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Repeat Offenders</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Motorist</th>
                            <th>License Number</th>
                            <th>Plate Number</th>
                            <th>Violation Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($repeat_offenders)): ?>
                            <tr><td colspan="4">No repeat offenders recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach($repeat_offenders as $offender): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($offender['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($offender['license_number'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($offender['plate'] ?: '-'); ?></td>
                                    <td><?php echo number_format((int)$offender['violation_count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Enforcer Performance</h3>
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
                                <td><?php echo (int)$ep['total_violations']; ?></td>
                                <td><?php echo (int)$ep['validated_count']; ?></td>
                                <td><?php echo (int)$ep['paid_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Recent Violations</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>TOP Number</th>
                            <th>Motorist</th>
                            <th>Violation</th>
                            <th>Location</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent as $v): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($v['violation_date'])); ?></td>
                                <td><?php echo $v['top_number']; ?></td>
                                <td><?php echo $v['motorist_name']; ?></td>
                                <td>
                                    <div class="violation-chips">
                                        <?php foreach (split_violation_items($v['violation_display'] ?? 'Multiple/Custom') as $item): ?>
                                            <span class="violation-chip"><?php echo htmlspecialchars($item); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><?php echo $v['location']; ?></td>
                                <td>₱<?php echo number_format($v['fine_amount'], 2); ?></td>
                                <td><span class="badge badge-<?php echo $v['status']; ?>"><?php echo ucfirst($v['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: 'Incidents',
                    data: <?php echo json_encode($trend_data); ?>,
                    borderColor: '#2e67c8',
                    backgroundColor: 'rgba(46, 103, 200, 0.12)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3,
                    pointRadius: 3
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#d7e0f6' } },
                    x: { grid: { display: false } }
                }
            }
        });

        new Chart(document.getElementById('monthlyTrendChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($monthly_trend_labels); ?>,
                datasets: [{
                    label: 'Monthly Incidents',
                    data: <?php echo json_encode($monthly_trend_data); ?>,
                    backgroundColor: 'rgba(15, 86, 195, 0.75)',
                    borderColor: '#0f56c3',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#d7e0f6' } },
                    x: { grid: { display: false } }
                }
            }
        });

        new Chart(document.getElementById('collectionStatusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Unpaid'],
                datasets: [{
                    data: [<?php echo (int)$paid_count; ?>, <?php echo (int)$unpaid_count; ?>],
                    backgroundColor: ['#1ea672', '#db5b5b'],
                    borderColor: ['#1a8f62', '#bf4a4a'],
                    borderWidth: 1.5
                }]
            },
            options: {
                plugins: { legend: { position: 'bottom' } },
                cutout: '62%'
            }
        });

        new Chart(document.getElementById('topLocationsChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($top_location_labels); ?>,
                datasets: [{
                    label: 'Violations',
                    data: <?php echo json_encode($top_location_counts); ?>,
                    backgroundColor: 'rgba(38, 123, 255, 0.75)',
                    borderColor: '#1f63cf',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#d7e0f6' }, ticks: { precision: 0 } },
                    x: { grid: { display: false } }
                }
            }
        });

        new Chart(document.getElementById('ageGroupChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($age_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($age_counts); ?>,
                    backgroundColor: ['#e15759', '#f28e2b', '#4e79a7', '#59a14f', '#9aa3b2'],
                    borderColor: ['#c5484a', '#d77c24', '#416a92', '#4a8942', '#7e8898'],
                    borderWidth: 1.5
                }]
            },
            options: {
                plugins: { legend: { position: 'bottom' } },
                cutout: '58%'
            }
        });
    </script>
</body>
</html>
