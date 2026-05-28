<?php
require_once '../auth.php';
check_role('pnp_officer');
require_once '../includes/motorist_profile.php';

global $pdo;
$conn = $pdo;
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$selected_motorist_id = isset($_GET['motorist_id']) ? (int)$_GET['motorist_id'] : 0;
$success = '';
$error = '';

if ($is_mysql) {
    try {
        $conn->exec("ALTER TABLE evidence ADD COLUMN evidence_type VARCHAR(50) NOT NULL DEFAULT 'general'");
    } catch (PDOException $e) {
    }
    try {
        $conn->exec("ALTER TABLE evidence ADD COLUMN evidence_label VARCHAR(255) NULL");
    } catch (PDOException $e) {
    }
} else {
    try {
        $columns = $conn->query("PRAGMA table_info(evidence)")->fetchAll(PDO::FETCH_ASSOC);
        $has_evidence_type = false;
        $has_evidence_label = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'evidence_type') {
                $has_evidence_type = true;
            }
            if (($column['name'] ?? '') === 'evidence_label') {
                $has_evidence_label = true;
            }
        }
        if (!$has_evidence_type) {
            $conn->exec("ALTER TABLE evidence ADD COLUMN evidence_type TEXT NOT NULL DEFAULT 'general'");
        }
        if (!$has_evidence_label) {
            $conn->exec("ALTER TABLE evidence ADD COLUMN evidence_label TEXT");
        }
    } catch (PDOException $e) {
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_motorist_dob'])) {
    $target_motorist_id = (int)($_POST['motorist_id'] ?? 0);
    $date_of_birth = trim((string)($_POST['date_of_birth'] ?? ''));
    $selected_motorist_id = $target_motorist_id > 0 ? $target_motorist_id : $selected_motorist_id;
    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if ($target_motorist_id <= 0) {
            throw new RuntimeException('Invalid motorist selected.');
        }
        if ($date_of_birth === '') {
            throw new RuntimeException('Date of birth is required.');
        }
        $dob_date = DateTime::createFromFormat('Y-m-d', $date_of_birth);
        $is_valid_dob = $dob_date && $dob_date->format('Y-m-d') === $date_of_birth;
        if (!$is_valid_dob) {
            throw new RuntimeException('Invalid date format for date of birth.');
        }
        if ($dob_date > new DateTime('today')) {
            throw new RuntimeException('Date of birth cannot be in the future.');
        }

        $save_stmt = $conn->prepare("UPDATE motorists SET date_of_birth = ? WHERE id = ?");
        $save_stmt->execute([$date_of_birth, $target_motorist_id]);
        $success = 'Motorist date of birth saved successfully.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $violations_res = $conn->query("SELECT v.id, v.violation_date, v.location, v.fine_amount, v.status, v.top_number,
                                     m.full_name as motorist_name, m.license_number,
                                     COALESCE(v.violation_details, p.violation_name) as violation_display,
                                     u.full_name as enforcer_name
                                     FROM violations v
                                     LEFT JOIN motorists m ON v.motorist_id = m.id
                                     LEFT JOIN penalties p ON v.penalty_id = p.id
                                     LEFT JOIN users u ON v.enforcer_id = u.id
                                     ORDER BY v.violation_date DESC");
    $violations = $violations_res->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="pnp_violations_report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    // Office header (CSV supports text only; logo labels are placeholders)
    fputcsv($output, ['[POTOTAN LOGO]', '', '', '', 'Republic of the Philippines', '', '', '', '', '[TRAFFIC OFFICE LOGO]']);
    fputcsv($output, ['', '', '', '', 'Province of Iloilo']);
    fputcsv($output, ['', '', '', '', 'Municipality of Pototan']);
    fputcsv($output, ['', '', '', '', 'MUNICIPAL TRAFFIC MANAGEMENT OFFICE']);
    fputcsv($output, ['', '', '', '', '2nd Floor Old Market, RY Ladrido Street']);
    fputcsv($output, ['', '', '', '', 'Brgy. San Jose, Pototan, Iloilo']);
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
    fputcsv($output, ['Prepared by', '', 'Checked by', '', 'Noted by']);
    fputcsv($output, [$_SESSION['full_name'] ?? 'PNP Officer', '', 'PNP Station Commander', '', 'Municipal Administrator']);
    fputcsv($output, ['PNP Officer', '', '', '', '']);
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

// By violation type (segregated: split multi-violation entries by comma)
$type_rows = $conn->query("SELECT COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') as violation_text
                           FROM violations v
                           LEFT JOIN penalties p ON p.id = v.penalty_id")->fetchAll(PDO::FETCH_ASSOC);
$type_totals = [];
foreach ($type_rows as $row) {
    $raw_text = trim((string)($row['violation_text'] ?? ''));
    $parts = array_values(array_filter(array_map('trim', explode(',', $raw_text)), fn($item) => $item !== ''));
    if (empty($parts)) {
        $parts = ['Multiple/Custom'];
    }
    foreach ($parts as $violation_name) {
        $type_totals[$violation_name] = ($type_totals[$violation_name] ?? 0) + 1;
    }
}
arsort($type_totals);
$summary['by_type'] = [];
foreach (array_slice($type_totals, 0, 10, true) as $violation_name => $count) {
    $summary['by_type'][] = [
        'violation_name' => $violation_name,
        'count' => $count
    ];
}

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

// Age distribution (based on motorists linked to recorded violations)
$age_rows = [];
$has_dob_column = table_has_column($conn, $is_mysql, 'motorists', 'date_of_birth');
if ($has_dob_column) {
    $age_rows = $conn->query("SELECT m.date_of_birth
                              FROM violations v
                              LEFT JOIN motorists m ON m.id = v.motorist_id")->fetchAll(PDO::FETCH_ASSOC);
}
$age_brackets = [
    'Under 18' => 0,
    '18-25' => 0,
    '26-35' => 0,
    '36-45' => 0,
    '46-60' => 0,
    '61+' => 0,
    'Unknown Age' => 0
];
$today = new DateTime('today');
foreach ($age_rows as $age_row) {
    $dob_raw = trim((string)($age_row['date_of_birth'] ?? ''));
    if ($dob_raw === '') {
        $age_brackets['Unknown Age']++;
        continue;
    }
    try {
        $dob = new DateTime($dob_raw);
        $age = (int)$dob->diff($today)->y;
        if ($age < 18) {
            $age_brackets['Under 18']++;
        } elseif ($age <= 25) {
            $age_brackets['18-25']++;
        } elseif ($age <= 35) {
            $age_brackets['26-35']++;
        } elseif ($age <= 45) {
            $age_brackets['36-45']++;
        } elseif ($age <= 60) {
            $age_brackets['46-60']++;
        } else {
            $age_brackets['61+']++;
        }
    } catch (Exception $e) {
        $age_brackets['Unknown Age']++;
    }
}
$summary['by_age'] = $age_brackets;

// Revenue summary
$revenue = $conn->query("SELECT SUM(payment_amount) as total FROM payments")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$summary['total_revenue'] = $revenue;

$selected_motorist_profile = null;
$selected_motorist_violations = [];
$selected_motorist_evidence_by_violation = [];

$motorist_profile_data = fetch_motorist_profile_data($conn, $selected_motorist_id);
$selected_motorist_profile = $motorist_profile_data['profile'];
$selected_motorist_violations = $motorist_profile_data['violations'];
$selected_motorist_evidence_by_violation = $motorist_profile_data['evidence_by_violation'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PNP Officer Reports - Traffic Violation System</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="../public/js/violator-pdf.js" defer></script>
    <script src="../theme.js" defer></script>
    <style>
        .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                .stat-card {
                    background: white;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    text-align: center;
                }
                .stat-card h3 {
                    margin: 0 0 10px 0;
                    color: #666;
                    font-size: 0.9rem;
                    text-transform: uppercase;
                }
                .stat-card .value {
                    font-size: 2rem;
                    font-weight: bold;
                    color: #2c3e50;
                }
                .charts-container {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                    gap: 2rem;
                    margin: 20px 0;
                }
                .age-focus-card h3 {
                    margin-bottom: 0.2rem;
                }
                .age-focus-subtitle {
                    margin: 0 0 0.7rem;
                    color: #62749a;
                    font-size: 0.92rem;
                }
                .age-focus-card canvas {
                    width: 100% !important;
                    height: 300px !important;
                }
                .no-chart-data {
                    min-height: 300px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    text-align: center;
                    color: #64779d;
                    font-size: 0.95rem;
                    border: 1px dashed #d7e2f5;
                    border-radius: 8px;
                    background: #fbfdff;
                    padding: 1rem;
                }
                .badge-validated { background: #27ae60; color: white; padding: 3px 8px; border-radius: 4px; }
                .badge-pending { background: #f39c12; color: white; padding: 3px 8px; border-radius: 4px; }
                .badge-paid { background: #3498db; color: white; padding: 3px 8px; border-radius: 4px; }
                .badge-rejected { background: #e74c3c; color: white; padding: 3px 8px; border-radius: 4px; }
                .badge-released { background: #8e44ad; color: white; padding: 3px 8px; border-radius: 4px; }
                html[data-theme="dark"] body,
                html[data-theme="dark"] .main-content {
                    background: #0f1726;
                }
                html[data-theme="dark"] .stats-card,
                html[data-theme="dark"] .profile-card,
                html[data-theme="dark"] .profile-modal-card,
                html[data-theme="dark"] .evidence-lightbox-dialog,
                html[data-theme="dark"] .report-header,
                html[data-theme="dark"] .evidence-gallery-item,
                html[data-theme="dark"] .profile-box {
                    background: #162033;
                    border-color: #2b3a57;
                    color: #d8e4ff;
                }
                html[data-theme="dark"] .profile-subtext,
                html[data-theme="dark"] .profile-box strong,
                html[data-theme="dark"] .report-sub,
                html[data-theme="dark"] .report-meta,
                html[data-theme="dark"] .evidence-gallery-meta {
                    color: #a8bcdf;
                }
                html[data-theme="dark"] .report-title,
                html[data-theme="dark"] .report-office,
                html[data-theme="dark"] .report-section-title,
                html[data-theme="dark"] .evidence-gallery-meta strong {
                    color: #e6efff;
                }
                html[data-theme="dark"] .profile-history-table th,
                html[data-theme="dark"] table th {
                    background: #1f2d45;
                    color: #d9e8ff;
                    border-color: #2b3a57;
                }
                html[data-theme="dark"] .profile-history-table td,
                html[data-theme="dark"] table td {
                    border-color: #2b3a57;
                    color: #d8e4ff;
                }
                html[data-theme="dark"] .report-logo-wrap {
                    background: #0f1726;
                    border-color: #2b3a57;
                }
                html[data-theme="dark"] .profile-modal-overlay,
                html[data-theme="dark"] .evidence-lightbox {
                    background: rgba(2, 8, 20, 0.8);
                }
                html[data-theme="dark"] .profile-modal-close-x {
                    border-color: #3b4f75;
                    background: #1f2d45;
                    color: #d7e6ff !important;
                }
                html[data-theme="dark"] .no-chart-data {
                    color: #b5c6e4;
                    background: #162235;
                    border-color: #2d3f5d;
                }
                html[data-theme="dark"] .age-focus-subtitle {
                    color: #b5c6e4;
                }
                .btn {
                    background: var(--primary-color);
                    color: white;
                    padding: 10px 20px;
                    text-decoration: none;
                    border-radius: 4px;
                    display: inline-block;
                    margin: 10px 0;
                }
                .btn:hover {
                    background: var(--primary-hover);
                }
                .violation-chips {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.32rem;
                    max-width: 300px;
                }
                .violation-chip {
                    display: inline-block;
                    padding: 0.16rem 0.62rem;
                    border-radius: 999px;
                    background: #e5eefb;
                    border: 1px solid #bdd0f2;
                    color: #1b4f9f;
                    font-size: 0.8rem;
                    line-height: 1.25;
                    white-space: nowrap;
                }
                .motorist-link {
                    color: #0b56ba;
                    text-decoration: none;
                    font-weight: 700;
                }
                .motorist-link:hover {
                    text-decoration: underline;
                }
                .profile-card {
                    margin-top: 20px;
                    border: 1px solid #d6e1f3;
                    border-radius: 12px;
                    background: #fdfefe;
                    padding: 1rem;
                }
                .profile-subtext {
                    margin-top: 0;
                    margin-bottom: 12px;
                    color: #526a95;
                }
                .profile-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 12px;
                    margin: 12px 0;
                }
                .profile-box {
                    background: #fff;
                    border: 1px solid #e4e9f4;
                    border-radius: 8px;
                    padding: 0.75rem;
                }
                .profile-box strong {
                    display: block;
                    color: #4f638e;
                    font-size: 0.75rem;
                    text-transform: uppercase;
                    margin-bottom: 0.25rem;
                }
                .evidence-grid {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                }
                .evidence-item {
                    border: 1px solid #d4deef;
                    border-radius: 8px;
                    padding: 0.4rem;
                    width: 132px;
                    background: #fff;
                }
                .evidence-item img {
                    width: 100%;
                    height: 86px;
                    object-fit: cover;
                    border-radius: 6px;
                    display: block;
                    margin-bottom: 0.25rem;
                }
                .evidence-item span {
                    display: block;
                    font-size: 0.72rem;
                    color: #526a95;
                }
                .profile-actions {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 10px;
                    align-items: center;
                    margin: 12px 0;
                }
                .profile-actions .btn {
                    margin: 0;
                }
                .profile-modal-overlay {
                    position: fixed;
                    inset: 0;
                    background: rgba(15, 23, 42, 0.55);
                    z-index: 2000;
                    display: flex;
                    align-items: flex-start;
                    justify-content: center;
                    padding: 24px;
                    overflow-y: auto;
                }
                .profile-modal-card {
                    width: min(1100px, 100%);
                    margin: 0;
                    box-shadow: 0 20px 60px rgba(15, 23, 42, 0.25);
                }
                .profile-modal-close-top {
                    display: flex;
                    justify-content: flex-end;
                    margin-bottom: 8px;
                }
                .report-title-row {
                    display: flex;
                    align-items: flex-start;
                    justify-content: space-between;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                .profile-modal-close {
                    margin-left: auto;
                }
                .profile-modal-close-x {
                    width: 36px;
                    height: 36px;
                    min-width: 36px;
                    min-height: 36px;
                    padding: 0;
                    border-radius: 999px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 1.2rem;
                    font-weight: 700;
                    line-height: 1;
                    text-decoration: none;
                }
                .evidence-gallery {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
                    gap: 10px;
                    margin-bottom: 10px;
                }
                .evidence-gallery-item {
                    border: 1px solid #d9e3f4;
                    border-radius: 10px;
                    background: #fff;
                    overflow: hidden;
                }
                .evidence-gallery-item img {
                    width: 100%;
                    height: 120px;
                    object-fit: cover;
                    display: block;
                }
                .evidence-gallery-meta {
                    padding: 8px;
                    font-size: 0.76rem;
                    color: #4f638e;
                }
                .evidence-gallery-meta strong {
                    color: #233f72;
                    display: block;
                    margin-bottom: 2px;
                }
                .evidence-lightbox {
                    position: fixed;
                    inset: 0;
                    background: rgba(2, 6, 23, 0.78);
                    display: none;
                    align-items: center;
                    justify-content: center;
                    z-index: 3200;
                    padding: 24px;
                }
                .evidence-lightbox.is-open {
                    display: flex;
                }
                .evidence-lightbox-dialog {
                    width: min(1100px, 100%);
                    max-height: 92vh;
                    background: #fff;
                    border-radius: 12px;
                    box-shadow: 0 24px 64px rgba(2, 6, 23, 0.45);
                    overflow: hidden;
                    display: grid;
                    grid-template-rows: auto 1fr;
                }
                .evidence-lightbox-head {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 10px;
                    padding: 10px 14px;
                    border-bottom: 1px solid #e2e8f3;
                    background: #f8fbff;
                }
                .evidence-lightbox-title {
                    color: #173a79;
                    font-weight: 700;
                    font-size: 0.95rem;
                }
                .evidence-lightbox-close {
                    border: none;
                    background: #1d4f9f;
                    color: #fff;
                    border-radius: 6px;
                    padding: 6px 10px;
                    cursor: pointer;
                }
                .evidence-lightbox-body {
                    padding: 10px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #ffffff;
                }
                .evidence-lightbox-body img {
                    max-width: 100%;
                    max-height: 78vh;
                    object-fit: contain;
                    border-radius: 6px;
                }
                .report-header {
                    border: 1px solid #d6e1f3;
                    border-radius: 10px;
                    background: linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
                    padding: 12px 14px;
                    margin-bottom: 12px;
                }
                .report-header-top {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 12px;
                    border-bottom: 1px solid #e3eaf6;
                    padding-bottom: 8px;
                    margin-bottom: 8px;
                }
                .report-logo-wrap {
                    width: 54px;
                    height: 54px;
                    border: 1px solid #d8e2f3;
                    border-radius: 10px;
                    background: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    overflow: hidden;
                    flex-shrink: 0;
                }
                .report-logo-wrap img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                }
                .report-logo-fallback {
                    font-size: 0.63rem;
                    font-weight: 700;
                    color: #5e7093;
                    text-align: center;
                    line-height: 1.1;
                    padding: 4px;
                }
                .report-office-block {
                    flex: 1;
                    min-width: 0;
                }
                .report-office {
                    font-weight: 800;
                    color: #0b2f6b;
                    font-size: 1rem;
                    letter-spacing: 0.02em;
                }
                .report-sub {
                    color: #4f638e;
                    font-size: 0.83rem;
                }
                .report-title {
                    margin: 0;
                    color: #0d244f;
                    font-size: 1.12rem;
                }
                .report-meta {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                    gap: 8px;
                    font-size: 0.84rem;
                    color: #314a77;
                }
                .report-meta strong {
                    color: #223d71;
                }
                .report-section-title {
                    margin: 14px 0 8px;
                    color: #173a79;
                    font-size: 0.92rem;
                    font-weight: 800;
                    letter-spacing: 0.05em;
                    text-transform: uppercase;
                }
                .profile-history-table th {
                    background: #eef3fb;
                    color: #1f3868;
                    font-size: 0.76rem;
                    letter-spacing: 0.05em;
                    text-transform: uppercase;
                }
                .profile-history-table td {
                    vertical-align: top;
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>🚔 PNP Officer</h2>
            <ul>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="reports.php" class="active">Reports</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <main class="main-content">
            <header>
                <h1>Reports</h1>
                <div class="user-info">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
            </header>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Violations</h3>
                    <div class="value"><?php echo $summary['total']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Revenue</h3>
                    <div class="value">₱<?php echo number_format($summary['total_revenue'], 2); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending</h3>
                    <div class="value"><?php echo $summary['by_status']['pending'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Validated</h3>
                    <div class="value"><?php echo $summary['by_status']['validated'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Paid</h3>
                    <div class="value"><?php echo $summary['by_status']['paid'] ?? 0; ?></div>
                </div>
            </div>

            <div style="margin: 20px 0;">
                <a href="?export=csv" class="btn" style="background: var(--secondary-color);">Export to CSV</a>
            </div>

            <div class="charts-container">
                <div class="card">
                    <h3>Violations by Type</h3>
                    <canvas id="typeChart" width="400" height="300"></canvas>
                </div>
                <div class="card">
                    <h3>Status Distribution</h3>
                    <canvas id="statusChart" width="400" height="300"></canvas>
                </div>
                <div class="card">
                    <h3>Monthly Trends</h3>
                    <canvas id="monthlyChart" width="400" height="300"></canvas>
                </div>
                <div class="card">
                    <h3>Top Locations</h3>
                    <canvas id="locationChart" width="400" height="300"></canvas>
                </div>
                <div class="card age-focus-card">
                    <h3>Violations by Age Group</h3>
                    <p class="age-focus-subtitle">See which age group has more recorded violations.</p>
                    <canvas id="ageChart" width="400" height="300"></canvas>
                </div>
            </div>

            <?php if ($selected_motorist_profile): ?>
                <?php
                $total_offenses = count($selected_motorist_violations);
                $profile_payload = build_motorist_profile_payload(
                    $selected_motorist_profile,
                    $selected_motorist_violations,
                    $selected_motorist_evidence_by_violation
                );
                ?>
                <div class="profile-modal-overlay" role="dialog" aria-modal="true" aria-label="Violator Profile">
                <div class="profile-card profile-modal-card" id="motorist-profile-card" tabindex="-1">
                    <div class="profile-modal-close-top">
                        <a class="btn profile-modal-close profile-modal-close-x" href="reports.php" aria-label="Close modal">&times;</a>
                    </div>
                    <div class="report-header">
                        <div class="report-header-top">
                            <div class="report-logo-wrap">
                                <img src="../assets/images/pototan-logo-no-bg.png" alt="MTMO Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <span class="report-logo-fallback" style="display:none;">MTMO</span>
                            </div>
                            <div class="report-office-block">
                                <div class="report-office">Municipal Traffic Management Office - PNP</div>
                                <div class="report-sub">Pototan, Iloilo | Real-Time Traffic Violation Monitoring Unit</div>
                            </div>
                            <div class="report-logo-wrap">
                                <img src="../assets/images/pototan-logo-no-bg.png" alt="PNP Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <span class="report-logo-fallback" style="display:none;">PNP</span>
                            </div>
                        </div>
                        <div class="report-title-row">
                            <h3 class="report-title">Violator Profile and Violation History Report</h3>
                            <button type="button" class="btn" id="download-profile-pdf">Download Official PDF Report</button>
                        </div>
                        <div class="report-meta">
                            <div><strong>Generated On:</strong> <?php echo date('M d, Y h:i A'); ?></div>
                            <div><strong>Generated By:</strong> <?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div><strong>Reference:</strong> PNP-DOC-<?php echo date('Ymd-His'); ?></div>
                        </div>
                    </div>
                    <p class="profile-subtext">Full violation history and evidence for reporting and monitoring by authorized PNP personnel.</p>
                    <div class="report-section-title">Violator Information</div>
                    <div class="profile-grid">
                        <div class="profile-box">
                            <strong>Violator Name</strong>
                            <?php echo htmlspecialchars($selected_motorist_profile['full_name']); ?>
                        </div>
                        <div class="profile-box">
                            <strong>License Number</strong>
                            <?php echo htmlspecialchars($selected_motorist_profile['license_number'] ?: 'N/A'); ?>
                        </div>
                        <div class="profile-box">
                            <strong>Plate Number</strong>
                            <?php echo htmlspecialchars($selected_motorist_profile['plate'] ?: 'N/A'); ?>
                        </div>
                        <div class="profile-box">
                            <strong>Total Offenses</strong>
                            <?php echo $total_offenses; ?>
                        </div>
                    </div>

                    <div class="profile-box">
                        <strong>Address</strong>
                        <?php echo htmlspecialchars($selected_motorist_profile['address'] ?: 'N/A'); ?>
                    </div>
                    <div class="profile-box" style="margin-top:10px;">
                        <strong>Date of Birth</strong>
                        <?php
                            $current_dob = trim((string)($selected_motorist_profile['date_of_birth'] ?? ''));
                            echo $current_dob !== '' ? htmlspecialchars($current_dob) : 'Not set';
                        ?>
                        <form method="POST" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="save_motorist_dob" value="1">
                            <input type="hidden" name="motorist_id" value="<?php echo (int)$selected_motorist_profile['id']; ?>">
                            <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($current_dob); ?>" required>
                            <button type="submit" class="btn btn-primary btn-sm">Save DOB</button>
                        </form>
                    </div>

                    <div class="report-section-title">Violation History</div>
                    <table class="profile-history-table">
                        <thead>
                            <tr>
                                <th>TOP #</th>
                                <th>Date & Time</th>
                                <th>Offense</th>
                                <th>Location</th>
                                <th>Description</th>
                                <th>Penalty Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selected_motorist_violations as $mv): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($mv['top_number']); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($mv['violation_date'])); ?></td>
                                    <td>
                                        <div class="violation-chips">
                                            <?php foreach (split_violation_items($mv['violation_display'] ?? 'Multiple/Custom') as $item): ?>
                                                <span class="violation-chip"><?php echo htmlspecialchars($item); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($mv['location']); ?></td>
                                    <td><?php echo nl2br(htmlspecialchars($mv['incident_description'] ?: 'No narrative submitted.')); ?></td>
                                    <td>₱<?php echo number_format((float)$mv['fine_amount'], 2); ?></td>
                                    <td><span class="badge badge-<?php echo htmlspecialchars($mv['status']); ?>"><?php echo ucfirst($mv['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="report-section-title">Evidence Gallery</div>
                    <?php
                    $all_evidence_items = [];
                    foreach ($selected_motorist_violations as $gallery_violation) {
                        $gallery_evidence = $selected_motorist_evidence_by_violation[(int)$gallery_violation['id']] ?? [];
                        foreach ($gallery_evidence as $gallery_item) {
                            $all_evidence_items[] = [
                                'top_number' => $gallery_violation['top_number'] ?? 'N/A',
                                'file_path' => $gallery_item['file_path'] ?? '',
                                'label' => $gallery_item['evidence_label'] ?: ucfirst(str_replace('_', ' ', $gallery_item['evidence_type'] ?? 'Evidence'))
                            ];
                        }
                    }
                    ?>
                    <?php if (!empty($all_evidence_items)): ?>
                        <div class="evidence-gallery">
                            <?php foreach ($all_evidence_items as $g_item): ?>
                                <a class="evidence-gallery-item js-evidence-trigger" href="../uploads/<?php echo htmlspecialchars($g_item['file_path']); ?>" data-evidence-label="<?php echo htmlspecialchars($g_item['label']); ?>">
                                    <img src="../uploads/<?php echo htmlspecialchars($g_item['file_path']); ?>" alt="Evidence">
                                    <div class="evidence-gallery-meta">
                                        <strong><?php echo htmlspecialchars($g_item['label']); ?></strong>
                                        TOP #: <?php echo htmlspecialchars($g_item['top_number']); ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="profile-subtext" style="margin-bottom:10px;">No uploaded evidence images available for this violator.</div>
                    <?php endif; ?>
                </div>
                <div class="evidence-lightbox" id="evidence-lightbox" aria-hidden="true">
                    <div class="evidence-lightbox-dialog">
                        <div class="evidence-lightbox-head">
                            <div class="evidence-lightbox-title" id="evidence-lightbox-title">Evidence Preview</div>
                            <button type="button" class="evidence-lightbox-close" id="evidence-lightbox-close">Close</button>
                        </div>
                        <div class="evidence-lightbox-body">
                            <img src="" alt="Evidence Preview" id="evidence-lightbox-image">
                        </div>
                    </div>
                </div>
                </div>
                <script>
                    window.motoristProfilePayload = <?php echo json_encode($profile_payload, JSON_UNESCAPED_UNICODE); ?>;
                </script>
            <?php elseif ($selected_motorist_id > 0): ?>
                <div class="profile-card">
                    <strong>Motorist profile not found.</strong>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Violations by Type Chart
        new Chart(document.getElementById('typeChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($summary['by_type'], 'violation_name')); ?>,
                datasets: [{
                    label: 'Count',
                    data: <?php echo json_encode(array_column($summary['by_type'], 'count')); ?>,
                    backgroundColor: '#004a99',
                    borderColor: '#003366',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Status Distribution Chart
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Validated', 'Paid', 'Rejected'],
                datasets: [{
                    data: [
                        <?php echo $summary['by_status']['pending'] ?? 0; ?>,
                        <?php echo $summary['by_status']['validated'] ?? 0; ?>,
                        <?php echo $summary['by_status']['paid'] ?? 0; ?>,
                        <?php echo $summary['by_status']['rejected'] ?? 0; ?>
                    ],
                    backgroundColor: ['#ffc107', '#17a2b8', '#28a745', '#dc3545'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Monthly Trends Chart
        new Chart(document.getElementById('monthlyChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($summary['monthly'], 'month')); ?>,
                datasets: [{
                    label: 'Violations',
                    data: <?php echo json_encode(array_column($summary['monthly'], 'count')); ?>,
                    borderColor: '#e74c3c',
                    backgroundColor: 'rgba(231, 76, 60, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Top Locations Chart
        new Chart(document.getElementById('locationChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($summary['top_locations'], 'location')); ?>,
                datasets: [{
                    label: 'Count',
                    data: <?php echo json_encode(array_column($summary['top_locations'], 'count')); ?>,
                    backgroundColor: '#f39c12',
                    borderColor: '#e67e22',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Age Distribution Chart
        const ageChartEl = document.getElementById('ageChart');
        const ageDataValues = <?php echo json_encode(array_values($summary['by_age'])); ?>;
        const hasAgeData = Array.isArray(ageDataValues) && ageDataValues.some(function (v) { return Number(v) > 0; });
        if (!hasAgeData && ageChartEl && ageChartEl.parentElement) {
            ageChartEl.style.display = 'none';
            const placeholder = document.createElement('div');
            placeholder.className = 'no-chart-data';
            placeholder.textContent = 'No age data available yet. Add motorists with date of birth to populate this chart.';
            ageChartEl.parentElement.appendChild(placeholder);
        } else if (ageChartEl) {
            new Chart(ageChartEl, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_keys($summary['by_age'])); ?>,
                    datasets: [{
                        label: 'Violations',
                        data: ageDataValues,
                        backgroundColor: ['#6f42c1', '#17a2b8', '#007bff', '#28a745', '#ffc107', '#fd7e14', '#9aa3b2'],
                        borderColor: '#3b2b66',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const evidenceLightbox = document.getElementById('evidence-lightbox');
            const evidenceLightboxImage = document.getElementById('evidence-lightbox-image');
            const evidenceLightboxTitle = document.getElementById('evidence-lightbox-title');
            const evidenceLightboxClose = document.getElementById('evidence-lightbox-close');
            const evidenceTriggers = document.querySelectorAll('.js-evidence-trigger');

            const closeEvidenceLightbox = function() {
                if (!evidenceLightbox) {
                    return;
                }
                evidenceLightbox.classList.remove('is-open');
                evidenceLightbox.setAttribute('aria-hidden', 'true');
                if (evidenceLightboxImage) {
                    evidenceLightboxImage.src = '';
                }
            };

            if (evidenceLightbox && evidenceTriggers.length > 0) {
                evidenceTriggers.forEach(function(trigger) {
                    trigger.addEventListener('click', function(event) {
                        event.preventDefault();
                        if (!evidenceLightboxImage) {
                            return;
                        }
                        const imageUrl = trigger.getAttribute('href') || '';
                        const imageLabel = trigger.getAttribute('data-evidence-label') || 'Evidence Preview';
                        evidenceLightboxImage.src = imageUrl;
                        evidenceLightboxTitle.textContent = imageLabel;
                        evidenceLightbox.classList.add('is-open');
                        evidenceLightbox.setAttribute('aria-hidden', 'false');
                    });
                });
                evidenceLightbox.addEventListener('click', function(event) {
                    if (event.target === evidenceLightbox) {
                        closeEvidenceLightbox();
                    }
                });
                if (evidenceLightboxClose) {
                    evidenceLightboxClose.addEventListener('click', closeEvidenceLightbox);
                }
            }

            const profileOverlay = document.querySelector('.profile-modal-overlay');
            if (profileOverlay) {
                profileOverlay.addEventListener('click', function(event) {
                    if (event.target === profileOverlay) {
                        window.location.href = 'reports.php';
                    }
                });

                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        if (evidenceLightbox && evidenceLightbox.classList.contains('is-open')) {
                            closeEvidenceLightbox();
                            return;
                        }
                        window.location.href = 'reports.php';
                    }
                });
            }

            window.setupViolatorPdfDownload({
                buttonId: 'download-profile-pdf',
                payload: window.motoristProfilePayload || null,
                successMessage: 'PDF report generated successfully.'
            });
        });
    </script>
</body>
</html>