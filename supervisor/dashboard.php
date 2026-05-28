<?php
require_once '../auth.php';
check_role('supervisor');
require_once '../includes/motorist_profile.php';

global $pdo;
$conn = $pdo;
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$edit_violation_id = isset($_GET['edit_violation_id']) ? (int)$_GET['edit_violation_id'] : 0;

function split_violation_items($raw_violation) {
    $raw_violation = trim((string)$raw_violation);
    if ($raw_violation === '') {
        return ['Multiple/Custom'];
    }
    $items = array_values(array_filter(array_map('trim', explode(',', $raw_violation)), fn($item) => $item !== ''));
    return !empty($items) ? $items : ['Multiple/Custom'];
}

// Handle Validation/Rejection and quick edit
if (isset($_POST['action']) && isset($_POST['violation_id'])) {
    $v_id = $_POST['violation_id'];
    $action = $_POST['action'];
    $sup_id = $_SESSION['user_id'];

    if ($action === 'edit') {
        $new_location = trim((string)($_POST['location'] ?? ''));
        $new_violation_details = trim((string)($_POST['violation_details'] ?? ''));
        $new_fine_amount = (float)($_POST['fine_amount'] ?? 0);

        if ($new_location === '' || $new_violation_details === '' || $new_fine_amount < 0) {
            $success = "Edit failed. Please provide valid location, violation details, and amount.";
        } else {
            $upd_stmt = $conn->prepare("UPDATE violations SET location = ?, violation_details = ?, fine_amount = ? WHERE id = ?");
            $upd_stmt->execute([$new_location, $new_violation_details, $new_fine_amount, $v_id]);

            $audit_stmt = $conn->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
            $audit_stmt->execute([$sup_id, 'edit', 'violations', $v_id, "Violation details updated by supervisor"]);
            $success = "Violation updated successfully!";
        }
    } else {
        $status = ($action == 'validate') ? 'validated' : 'rejected';
        $upd_stmt = $conn->prepare("UPDATE violations SET status = ?, supervisor_id = ?, validation_date = ? WHERE id = ?");
        $upd_stmt->execute([$status, $sup_id, date('Y-m-d H:i:s'), $v_id]);

        // Log the action in audit trail
        $audit_stmt = $conn->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
        $audit_stmt->execute([$sup_id, $action, 'violations', $v_id, "Status changed to: $status"]);

        $success = "Violation " . ($action == 'validate' ? 'validated' : 'rejected') . " successfully!";
    }
}

// Combined reports export (CSV)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv_stmt = $conn->query("SELECT v.id, v.violation_date, v.location, v.fine_amount, v.status, v.top_number,
                                     COALESCE(m.full_name, 'Unknown') as motorist_name,
                                     COALESCE(m.license_number, '-') as license_number,
                                     COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') as violation_display,
                                     COALESCE(u.full_name, 'System') as enforcer_name
                              FROM violations v
                              LEFT JOIN motorists m ON v.motorist_id = m.id
                              LEFT JOIN penalties p ON v.penalty_id = p.id
                              LEFT JOIN users u ON v.enforcer_id = u.id
                              ORDER BY v.violation_date DESC");
    $csv_rows = $csv_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="supervisor_analytics_reports_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'TOP Number', 'Motorist Name', 'License Number', 'Violation Type', 'Location', 'Amount', 'Status', 'Enforcer']);
    foreach ($csv_rows as $row) {
        fputcsv($output, [
            $row['id'],
            date('Y-m-d H:i', strtotime($row['violation_date'])),
            $row['top_number'],
            $row['motorist_name'],
            $row['license_number'],
            $row['violation_display'],
            $row['location'],
            $row['fine_amount'],
            $row['status'],
            $row['enforcer_name']
        ]);
    }
    fclose($output);
    exit;
}

// Handle filter form submission
$filter_status = $_GET['status'] ?? 'all';
$where_clause = "";
if ($filter_status !== 'all' && in_array($filter_status, ['pending', 'validated', 'paid', 'rejected'])) {
    $where_clause = "WHERE v.status = '" . $filter_status . "'";
}

$filter_date = trim((string)($_GET['violation_date'] ?? ''));
$date_where = "";
if ($filter_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $date_expr = $is_mysql ? "DATE(v.violation_date)" : "date(v.violation_date)";
    $date_where = " AND $date_expr = '" . $filter_date . "'";
}
$search_term = trim((string)($_GET['search'] ?? ''));

// Fetch stats for cards
$total_v = $conn->query("SELECT COUNT(*) as count FROM violations")->fetch(PDO::FETCH_ASSOC)['count'];
$pending_v = $conn->query("SELECT COUNT(*) as count FROM violations WHERE status = 'pending'")->fetch(PDO::FETCH_ASSOC)['count'];
$validated_v = $conn->query("SELECT COUNT(*) as count FROM violations WHERE status = 'validated'")->fetch(PDO::FETCH_ASSOC)['count'];
$paid_v = $conn->query("SELECT COUNT(*) as count FROM violations WHERE status = 'paid'")->fetch(PDO::FETCH_ASSOC)['count'];
$active_enforcers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'enforcer' AND status = 'active'")->fetch(PDO::FETCH_ASSOC)['count'];
$collection_rate = $total_v > 0 ? round((($validated_v + $paid_v) / $total_v) * 100, 1) : 0;

// Repeat offenders (motorists with multiple violations)
$repeat_offenders = $conn->query("SELECT m.full_name, m.license_number, m.plate, COUNT(v.id) AS violation_count
                                  FROM violations v
                                  JOIN motorists m ON v.motorist_id = m.id
                                  GROUP BY m.id, m.full_name, m.license_number, m.plate
                                  HAVING COUNT(v.id) > 1
                                  ORDER BY violation_count DESC, m.full_name ASC
                                  LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$recent_reports = $conn->query("SELECT v.violation_date, v.top_number, COALESCE(m.full_name, 'Unknown') as motorist_name,
                                       COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') as violation_display,
                                       COALESCE(v.location, 'Unknown location') as location, v.fine_amount, v.status
                                FROM violations v
                                LEFT JOIN motorists m ON v.motorist_id = m.id
                                LEFT JOIN penalties p ON v.penalty_id = p.id
                                ORDER BY v.violation_date DESC
                                LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Handle source filter
$filter_source = $_GET['source'] ?? 'all';
$source_where = "";
if ($filter_source !== 'all' && in_array($filter_source, ['enforcer', 'treasurer'])) {
    $source_where = " AND v.source = '" . $filter_source . "'";
}

// Build where clause combining status and source filters
$full_where_clause = $where_clause;
if (!empty($where_clause)) {
    $full_where_clause = $where_clause . $source_where . $date_where;
} else {
    $full_where_clause = "WHERE 1=1" . $source_where . $date_where;
}

// Fetch violations for management with filtering
$search_where = "";
$violations_params = [];
if ($search_term !== '') {
    $search_like = '%' . $search_term . '%';
    $search_where = " AND (
        m.full_name LIKE :search_like
        OR v.top_number LIKE :search_like
        OR v.location LIKE :search_like
        OR v.status LIKE :search_like
        OR COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') LIKE :search_like
    )";
    $violations_params[':search_like'] = $search_like;
}

$violations_stmt = $conn->prepare("SELECT v.*, m.full_name as motorist_name, p.violation_name, e.file_path
                                   FROM violations v
                                   LEFT JOIN motorists m ON v.motorist_id = m.id
                                   LEFT JOIN penalties p ON v.penalty_id = p.id
                                   LEFT JOIN evidence e ON v.id = e.violation_id
                                   $full_where_clause
                                   $search_where
                                   ORDER BY v.violation_date DESC");
$violations_stmt->execute($violations_params);
$violations = $violations_stmt->fetchAll(PDO::FETCH_ASSOC);

// Collapse duplicate violation rows created by evidence joins,
// and collect all related evidence file paths per violation.
$grouped_violations = [];
foreach ($violations as $row) {
    $violation_id = (int)($row['id'] ?? 0);
    if ($violation_id <= 0) {
        continue;
    }

    if (!isset($grouped_violations[$violation_id])) {
        $row['evidence_files'] = [];
        $grouped_violations[$violation_id] = $row;
    }

    $file_path = trim((string)($row['file_path'] ?? ''));
    if ($file_path !== '' && !in_array($file_path, $grouped_violations[$violation_id]['evidence_files'], true)) {
        $grouped_violations[$violation_id]['evidence_files'][] = $file_path;
    }
}
$violations = array_values($grouped_violations);

// Fetch enforcer performance data
$enforcer_perf_res = $conn->query("SELECT u.full_name, COUNT(v.id) as total_violations, 
                                   SUM(CASE WHEN v.status = 'validated' THEN 1 ELSE 0 END) as validated_count,
                                   SUM(CASE WHEN v.status = 'paid' THEN 1 ELSE 0 END) as paid_count
                                   FROM users u
                                   LEFT JOIN violations v ON u.id = v.enforcer_id
                                   WHERE u.role = 'enforcer'
                                   GROUP BY u.id, u.full_name
                                   ORDER BY total_violations DESC");
$enforcer_performance = $enforcer_perf_res->fetchAll(PDO::FETCH_ASSOC);

$selected_motorist_id = isset($_GET['motorist_id']) ? (int)$_GET['motorist_id'] : 0;
$selected_motorist_profile = null;
$selected_motorist_violations = [];
$selected_motorist_evidence_by_violation = [];
$selected_motorist_payload = null;
if ($selected_motorist_id > 0) {
    $motorist_profile_data = fetch_motorist_profile_data($conn, $selected_motorist_id);
    $selected_motorist_profile = $motorist_profile_data['profile'];
    $selected_motorist_violations = $motorist_profile_data['violations'];
    $selected_motorist_evidence_by_violation = $motorist_profile_data['evidence_by_violation'];
    $selected_motorist_payload = build_motorist_profile_payload(
        $selected_motorist_profile,
        $selected_motorist_violations,
        $selected_motorist_evidence_by_violation
    );
}

$dashboard_query_params = [
    'status' => $filter_status,
    'source' => $filter_source,
    'violation_date' => $filter_date,
    'search' => $search_term,
];
$dashboard_query = http_build_query(array_filter($dashboard_query_params, fn($value) => $value !== '' && $value !== null));
$dashboard_base_url = 'dashboard.php' . ($dashboard_query !== '' ? '?' . $dashboard_query : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard - Traffic Management System</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="../public/js/violator-pdf.js" defer></script>
    <script src="../theme.js" defer></script>
    <style>
        body {
                    background: #f3f5fb;
                }
                .main-content {
                    background: #f3f5fb;
                }
                .analytics-label {
                    font-size: 0.72rem;
                    letter-spacing: 0.16em;
                    color: #34508a;
                    font-weight: 700;
                    margin-bottom: 0.35rem;
                    text-transform: uppercase;
                }
                .analytics-title {
                    font-size: 2.75rem;
                    line-height: 1.05;
                    margin-bottom: 1.25rem;
                    color: #0a1f44;
                }
                .kpi-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 1rem;
                    margin-bottom: 1.25rem;
                }
                .kpi-card {
                    background: linear-gradient(180deg, #ffffff 0%, #f6f9ff 100%);
                    border: 1px solid #d8e4fb;
                    border-radius: 12px;
                    padding: 1.15rem 1.25rem;
                    box-shadow: 0 6px 16px rgba(16, 46, 107, 0.08);
                }
                .kpi-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 0.9rem;
                }
                .kpi-badge {
                    font-size: 0.72rem;
                    padding: 0.2rem 0.5rem;
                    border-radius: 6px;
                    color: #0b3f99;
                    background: #dfeaff;
                    font-weight: 700;
                }
                .kpi-label {
                    color: #47577a;
                    font-size: 0.92rem;
                }
                .kpi-value {
                    color: #0b2f73;
                    font-size: 2rem;
                    font-weight: 800;
                    line-height: 1.05;
                }
                .analytics-focus-banner {
                    background: linear-gradient(135deg, #0f56c3 0%, #1b73e0 100%);
                    border-radius: 12px;
                    color: #ffffff;
                    padding: 1rem 1.1rem;
                    margin: 0 0 1rem;
                    box-shadow: 0 10px 20px rgba(15, 86, 195, 0.22);
                }
                .analytics-focus-banner h3 {
                    margin: 0 0 0.2rem;
                    font-size: 1.05rem;
                }
                .analytics-focus-banner p {
                    margin: 0;
                    opacity: 0.96;
                    font-size: 0.9rem;
                }
                .analytics-legend {
                    margin-top: 0.75rem;
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.45rem;
                }
                .legend-chip {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.32rem;
                    padding: 0.25rem 0.55rem;
                    border-radius: 999px;
                    font-size: 0.75rem;
                    font-weight: 700;
                    border: 1px solid rgba(255, 255, 255, 0.35);
                    background: rgba(255, 255, 255, 0.13);
                }
                .legend-dot {
                    width: 9px;
                    height: 9px;
                    border-radius: 50%;
                }
                .legend-trend { background: #9fc5ff; }
                .legend-risk { background: #ffb2b2; }
                .legend-payment { background: #99dfbe; }
                .legend-location { background: #ffcb84; }
                .legend-time { background: #b8a9ff; }
                .legend-repeat { background: #8dd9eb; }
                .analytics-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 1rem;
                    margin-bottom: 1rem;
                }
                .analytics-panel {
                    background: #ffffff;
                    border: 1px solid #dbe6fa;
                    border-radius: 12px;
                    padding: 1.2rem 1.3rem;
                    box-shadow: 0 4px 14px rgba(18, 49, 107, 0.07);
                }
                .panel-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: baseline;
                    margin-bottom: 0.7rem;
                }
                .panel-title-wrap {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                }
                .panel-icon {
                    width: 30px;
                    height: 30px;
                    border-radius: 8px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 0.95rem;
                    background: #e7efff;
                    color: #0d4fb8;
                    border: 1px solid #cfe0ff;
                    flex-shrink: 0;
                }
                .icon-trend {
                    background: #e7efff;
                    border-color: #cfe0ff;
                    color: #0d4fb8;
                }
                .icon-risk {
                    background: #ffe7e7;
                    border-color: #ffd0d0;
                    color: #b4232f;
                }
                .icon-payment {
                    background: #e5f7ee;
                    border-color: #c6ecd8;
                    color: #18794e;
                }
                .icon-location {
                    background: #fff2df;
                    border-color: #ffe2bc;
                    color: #b86b00;
                }
                .icon-time {
                    background: #ece9ff;
                    border-color: #d8d1ff;
                    color: #5941c2;
                }
                .icon-repeat {
                    background: #e8fbff;
                    border-color: #caeef7;
                    color: #0a6f8d;
                }
                .panel-head h3 {
                    font-size: 1.85rem;
                    color: #0a1f44;
                }
                .panel-sub {
                    color: #4c5e87;
                    font-size: 0.9rem;
                    margin-bottom: 0.85rem;
                }
                .download-link {
                    color: #0a48b7;
                    font-size: 0.78rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.09em;
                    text-decoration: none;
                }
                .category-row {
                    margin-bottom: 0.75rem;
                }
                .category-top {
                    display: flex;
                    justify-content: space-between;
                    font-size: 0.86rem;
                    margin-bottom: 0.23rem;
                    color: #1a325f;
                    font-weight: 600;
                }
                .category-bar {
                    width: 100%;
                    height: 10px;
                    border-radius: 999px;
                    background: #d9e2f5;
                    overflow: hidden;
                }
                .category-fill {
                    height: 100%;
                    background: #0f56c3;
                    border-radius: 999px;
                }
                .events-panel {
                    background: #ffffff;
                    border: 1px solid #dbe6fa;
                    border-radius: 12px;
                    padding: 1.2rem 1.3rem;
                    margin-bottom: 1rem;
                    box-shadow: 0 4px 14px rgba(18, 49, 107, 0.07);
                }
                .card {
                    border: 1px solid #dbe6fa;
                    box-shadow: 0 4px 12px rgba(17, 47, 105, 0.06);
                }
                .card h2 {
                    color: #0d2f6f;
                    display: flex;
                    align-items: center;
                    gap: 0.45rem;
                }
                .events-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 0.7rem;
                }
                .events-title-wrap {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                }
                .events-head h3 {
                    margin: 0;
                    color: #0a1f44;
                }
                .section-icon {
                    width: 28px;
                    height: 28px;
                    border-radius: 7px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 0.9rem;
                    background: #e8f0ff;
                    color: #0d4fb8;
                    border: 1px solid #cfe0ff;
                }
                .event-row {
                    display: grid;
                    grid-template-columns: 1fr auto auto;
                    gap: 0.8rem;
                    align-items: center;
                    padding: 0.7rem 0;
                    border-top: 1px solid #dfe6f8;
                }
                .event-title {
                    font-weight: 700;
                    color: #0a1f44;
                }
                .event-meta {
                    color: #52618a;
                    font-size: 0.84rem;
                }
                .event-time {
                    color: #243f72;
                    font-size: 0.85rem;
                    font-weight: 600;
                }
                .status-pill {
                    padding: 0.18rem 0.52rem;
                    border-radius: 5px;
                    font-size: 0.72rem;
                    font-weight: 700;
                }
                .status-pill-critical {
                    background: #ffd8d8;
                    color: #9a1e1e;
                }
                .status-pill-standard {
                    background: #dbe7ff;
                    color: #143f8a;
                }
                .report-btn {
                    margin-top: 0.55rem;
                    display: inline-block;
                    background: #0f56c3;
                    color: #fff;
                    border-radius: 10px;
                    padding: 0.6rem 1rem;
                    text-decoration: none;
                    font-weight: 700;
                }
                .evidence-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; }
                .evidence-modal.active { display: flex; justify-content: center; align-items: center; }
                .evidence-modal-content { background: white; padding: 20px; border-radius: 8px; max-width: 90%; max-height: 90%; }
                .evidence-modal-content img { max-width: 100%; max-height: 80vh; }
                .close-modal { position: absolute; top: 20px; right: 20px; color: white; font-size: 30px; cursor: pointer; }
                .evidence-modal-gallery {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 12px;
                    margin-top: 10px;
                    max-height: 72vh;
                    overflow-y: auto;
                    padding-right: 4px;
                }
                .evidence-modal-gallery img {
                    width: 100%;
                    max-height: 220px;
                    object-fit: cover;
                    border-radius: 8px;
                    border: 1px solid #dbe6fa;
                }
                .filter-form { margin-bottom: 20px; }
                .filter-form select { padding: 8px; margin-right: 10px; }
                .filter-form input[type="date"],
                .filter-form input[type="text"] {
                    padding: 8px;
                    margin-right: 10px;
                    border: 1px solid #cdd9ef;
                    border-radius: 6px;
                    background: #fff;
                    color: #162b52;
                }
                .filter-form input[type="text"] {
                    min-width: 220px;
                }
                html[data-theme="dark"] body,
                html[data-theme="dark"] body .main-content {
                    background: #0f141c;
                }
                html[data-theme="dark"] .analytics-label,
                html[data-theme="dark"] .analytics-title,
                html[data-theme="dark"] .kpi-label,
                html[data-theme="dark"] .kpi-value,
                html[data-theme="dark"] .panel-head h3,
                html[data-theme="dark"] .panel-sub,
                html[data-theme="dark"] .events-head h3,
                html[data-theme="dark"] .event-title,
                html[data-theme="dark"] .event-meta,
                html[data-theme="dark"] .event-time,
                html[data-theme="dark"] .card h2 {
                    color: #e6edfb;
                }
                html[data-theme="dark"] .kpi-card,
                html[data-theme="dark"] .analytics-panel,
                html[data-theme="dark"] .events-panel,
                html[data-theme="dark"] .card {
                    background: #162235;
                    border-color: #2b3a52;
                    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.3);
                }
                html[data-theme="dark"] .download-link,
                html[data-theme="dark"] .motorist-link,
                html[data-theme="dark"] .card a {
                    color: #8eb8ff;
                }
                html[data-theme="dark"] .category-top,
                html[data-theme="dark"] .profile-subtext,
                html[data-theme="dark"] .profile-box strong,
                html[data-theme="dark"] .evidence-gallery-meta {
                    color: #c9d6ee;
                }
                html[data-theme="dark"] table {
                    background: #162235;
                }
                html[data-theme="dark"] table th {
                    background: #1f2e46;
                    color: #dbe6fb;
                    border-bottom-color: #33445f;
                }
                html[data-theme="dark"] table td {
                    color: #d4deef;
                    border-bottom-color: #2f3d52;
                }
                html[data-theme="dark"] table tr:nth-child(even) td {
                    background: #121a26;
                }
                html[data-theme="dark"] table tr:hover td {
                    background: #243044;
                }
                html[data-theme="dark"] .filter-form input[type="date"],
                html[data-theme="dark"] .filter-form input[type="text"],
                html[data-theme="dark"] .filter-form select {
                    background: #0f1725;
                    color: #e6edfb;
                    border-color: #33445f;
                }
                html[data-theme="dark"] .profile-modal-card,
                html[data-theme="dark"] .profile-box,
                html[data-theme="dark"] .evidence-gallery-item {
                    background: #162235;
                    border-color: #2b3a52;
                    color: #e6edfb;
                }
                .motorist-link {
                    color: #0f56c3;
                    font-weight: 600;
                    text-decoration: none;
                }
                .motorist-link:hover { text-decoration: underline; }
                .profile-modal-overlay {
                    position: fixed;
                    inset: 0;
                    background: rgba(5, 14, 32, 0.55);
                    z-index: 1100;
                    display: flex;
                    align-items: flex-start;
                    justify-content: center;
                    padding: 3rem 1rem 1rem;
                    overflow-y: auto;
                }
                .profile-modal-card {
                    width: min(1020px, 96%);
                    background: #fff;
                    border-radius: 12px;
                    padding: 1rem 1.1rem 1rem;
                }
                .profile-modal-close-top {
                    display: flex;
                    justify-content: flex-end;
                    margin-bottom: 0.45rem;
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
                .profile-modal-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    gap: 12px;
                    margin-bottom: 0.35rem;
                }
                .profile-modal-title-wrap {
                    flex: 1;
                    min-width: 0;
                }
                .profile-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                    gap: 0.7rem;
                    margin: 0.8rem 0;
                }
                .profile-box {
                    border: 1px solid #dbe6fa;
                    border-radius: 8px;
                    padding: 0.6rem 0.7rem;
                    background: #f7faff;
                }
                .profile-box strong {
                    display: block;
                    color: #24457f;
                    font-size: 0.8rem;
                    margin-bottom: 0.3rem;
                }
                .profile-subtext { color: #4a5b80; margin-bottom: 0.7rem; }
                .evidence-gallery {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
                    gap: 0.7rem;
                    margin-top: 0.8rem;
                }
                .evidence-gallery-item {
                    display: block;
                    text-decoration: none;
                    border: 1px solid #d7e3fa;
                    border-radius: 10px;
                    overflow: hidden;
                    background: #f8fbff;
                }
                .evidence-gallery-item img {
                    width: 100%;
                    height: 120px;
                    object-fit: cover;
                    display: block;
                    background: #eef4ff;
                }
                .evidence-gallery-meta {
                    padding: 0.45rem 0.55rem;
                    font-size: 0.78rem;
                    color: #39507e;
                }
                .evidence-gallery-meta strong {
                    display: block;
                    color: #183970;
                }
                .violation-chips {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.35rem;
                    max-width: 280px;
                }
                .violation-chip {
                    display: inline-block;
                    background: #e8f0ff;
                    color: #103f92;
                    border: 1px solid #c7d7f8;
                    border-radius: 999px;
                    padding: 0.15rem 0.55rem;
                    font-size: 0.78rem;
                    line-height: 1.3;
                    white-space: nowrap;
                }
                .role-hero-banner {
                    margin-bottom: 1rem;
                    padding: 4.4rem 1.8rem;
                    min-height: 360px;
                    border-radius: 14px;
                    border: 1px solid #7ab3f0;
                    position: relative;
                    overflow: hidden;
                    text-align: center;
                    background:
                        linear-gradient(120deg, rgba(32, 116, 220, 0.62) 0%, rgba(79, 163, 245, 0.56) 45%, rgba(23, 98, 200, 0.66) 100%),
                        radial-gradient(circle at 16% 78%, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0) 40%),
                        radial-gradient(circle at 88% 18%, rgba(255, 255, 255, 0.18) 0%, rgba(255, 255, 255, 0) 36%),
                        url("../assets/images/pototan-hall-wide.png") center 44% / cover no-repeat;
                    box-shadow: 0 14px 32px rgba(25, 88, 163, 0.26);
                    animation: roleHeroBackgroundShift 14s ease-in-out infinite alternate;
                }
                .role-hero-banner h2 {
                    margin: 0;
                    color: #ffffff;
                    font-size: clamp(2.7rem, 5.8vw, 3.9rem);
                    text-shadow: 0 8px 18px rgba(13, 6, 34, 0.32);
                    position: relative;
                    z-index: 1;
                    opacity: 0;
                    transform: translateY(18px);
                    animation: roleHeroFadeUp 760ms ease-out 140ms forwards;
                }
                .role-hero-banner p {
                    margin: 0.95rem auto 0;
                    color: #eaf4ff;
                    line-height: 1.6;
                    font-size: 1.24rem;
                    max-width: 820px;
                    position: relative;
                    z-index: 1;
                    opacity: 0;
                    transform: translateY(16px);
                    animation: roleHeroFadeUp 760ms ease-out 320ms forwards;
                }
                @keyframes roleHeroFadeUp {
                    from { opacity: 0; transform: translateY(20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                @keyframes roleHeroBackgroundShift {
                    from { background-position: center 44%, center center, center center, center 44%; }
                    to { background-position: center 44%, center center, center center, center 50%; }
                }
                @media (prefers-reduced-motion: reduce) {
                    .role-hero-banner { animation: none; }
                    .role-hero-banner h2, .role-hero-banner p { animation: none; opacity: 1; transform: none; }
                }
                @media (max-width: 980px) {
                    .analytics-grid { grid-template-columns: 1fr; }
                    .event-row { grid-template-columns: 1fr; }
                    .analytics-title { font-size: 2rem; }
                    .analytics-focus-banner {
                        padding: 0.85rem 0.9rem;
                    }
                    .analytics-legend {
                        gap: 0.35rem;
                        margin-top: 0.6rem;
                    }
                    .legend-chip {
                        font-size: 0.7rem;
                        padding: 0.2rem 0.48rem;
                    }
                    .panel-head h3 {
                        font-size: 1.35rem;
                    }
                    .panel-icon {
                        width: 26px;
                        height: 26px;
                        font-size: 0.82rem;
                    }
                    .section-icon {
                        width: 24px;
                        height: 24px;
                        font-size: 0.78rem;
                    }
                    .card h2 {
                        font-size: 1.1rem;
                    }
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
<?php $supervisor_sidebar_active = 'dashboard'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
        <main class="main-content">
            <header>
                <div>
                    <div class="analytics-label">Analytics Interface // Live Ledger</div>
                    <h1 class="analytics-title">Supervisor Dashboard</h1>
                </div>
                <div class="user-info"><?php echo $_SESSION['full_name']; ?></div>
            </header>
            <section class="role-hero-banner">
                <h2>Welcome, <?php echo htmlspecialchars((string)($_SESSION['full_name'] ?? 'Supervisor')); ?>!</h2>
                <p>Review live records, validate violations, and monitor overall traffic operations from one central supervisor dashboard.</p>
            </section>

            <?php if (isset($success)): ?>
                <div class="alert alert-success" style="background-color: #d4edda; color: #155724; padding: 10px; margin-bottom: 20px;"><?php echo $success; ?></div>
            <?php endif; ?>

            <div class="card">
                <h2><span class="section-icon icon-repeat">🔁</span>Repeat Offenders</h2>
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
                            <tr>
                                <td colspan="4">No repeat offenders recorded.</td>
                            </tr>
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
                <h2>Recent Violations Report View</h2>
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
                        <?php foreach($recent_reports as $r): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($r['violation_date'])); ?></td>
                                <td><?php echo htmlspecialchars($r['top_number']); ?></td>
                                <td><?php echo htmlspecialchars($r['motorist_name']); ?></td>
                                <td>
                                    <div class="violation-chips">
                                        <?php foreach (split_violation_items($r['violation_display']) as $item): ?>
                                            <span class="violation-chip"><?php echo htmlspecialchars($item); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($r['location']); ?></td>
                                <td>₱<?php echo number_format($r['fine_amount'], 2); ?></td>
                                <td><span class="badge badge-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

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
                                <td><?php echo $ep['full_name']; ?></td>
                                <td><?php echo $ep['total_violations']; ?></td>
                                <td><?php echo $ep['validated_count']; ?></td>
                                <td><?php echo $ep['paid_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>Manage Violations</h2>
                <form method="GET" class="filter-form">
                    <label>Filter by Status:</label>
                    <select name="status">
                        <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="validated" <?php echo $filter_status == 'validated' ? 'selected' : ''; ?>>Validated</option>
                        <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <label for="violation_date">Date:</label>
                    <input type="date" id="violation_date" name="violation_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                    <label for="search">Search:</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Motorist, violation, TOP #, location">
                    <?php if ($filter_source !== 'all'): ?>
                        <input type="hidden" name="source" value="<?php echo htmlspecialchars($filter_source); ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn" style="background: var(--primary-color); color: white;">Filter</button>
                    <a href="?export=csv&status=<?php echo urlencode($filter_status); ?>&source=<?php echo urlencode($filter_source); ?>&violation_date=<?php echo urlencode($filter_date); ?>&search=<?php echo urlencode($search_term); ?>" class="btn" style="background: var(--secondary-color); color: white; text-decoration: none;">Export CSV</a>
                </form>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Motorist</th>
                            <th>Violation</th>
                            <th>Evidence</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($violations as $v): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($v['violation_date'])); ?></td>
                                <td>
                                    <?php if (!empty($v['motorist_id'])): ?>
                                        <a href="<?php echo htmlspecialchars($dashboard_base_url . ($dashboard_query !== '' ? '&' : '?') . 'motorist_id=' . (int)$v['motorist_id']); ?>" class="motorist-link">
                                            <?php echo htmlspecialchars($v['motorist_name'] ?: 'Unknown'); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($v['motorist_name'] ?: 'Unknown'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="violation-chips">
                                        <?php
                                            $violation_text = $v['violation_details'] ?? $v['violation_name'] ?? 'Multiple/Custom';
                                            foreach (split_violation_items($violation_text) as $item):
                                        ?>
                                            <span class="violation-chip"><?php echo htmlspecialchars($item); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($v['evidence_files'])): ?>
                                        <?php if (count($v['evidence_files']) === 1): ?>
                                            <a href="#" onclick="showEvidence('<?php echo htmlspecialchars($v['evidence_files'][0], ENT_QUOTES); ?>'); return false;">View Evidence</a>
                                        <?php else: ?>
                                            <a href="#" onclick='showEvidenceGallery(<?php echo htmlspecialchars(json_encode(array_values($v['evidence_files'])), ENT_QUOTES); ?>); return false;'>
                                                View Evidence (<?php echo count($v['evidence_files']); ?>)
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        No Image
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?php echo $v['status']; ?>"><?php echo ucfirst($v['status']); ?></span></td>
                                <td>
                                    <a
                                        class="btn"
                                        href="<?php echo htmlspecialchars($dashboard_base_url . ($dashboard_query !== '' ? '&' : '?') . 'edit_violation_id=' . (int)$v['id']); ?>"
                                        style="background: #1d4ed8; color:white; padding: 2px 8px; font-size: 0.8rem; text-decoration:none;"
                                    >Edit</a>
                                    <?php if($v['status'] == 'pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="violation_id" value="<?php echo $v['id']; ?>">
                                            <button type="submit" name="action" value="validate" class="btn" style="background: var(--secondary-color); color:white; padding: 2px 5px; font-size: 0.8rem;">Validate</button>
                                            <button type="submit" name="action" value="reject" class="btn" style="background: var(--danger); color:white; padding: 2px 5px; font-size: 0.8rem;">Reject</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($edit_violation_id > 0): ?>
                <?php
                    $edit_target = null;
                    foreach ($violations as $candidate_violation) {
                        if ((int)$candidate_violation['id'] === $edit_violation_id) {
                            $edit_target = $candidate_violation;
                            break;
                        }
                    }
                ?>
                <?php if ($edit_target): ?>
                    <div class="profile-modal-overlay" role="dialog" aria-modal="true" aria-label="Edit violation">
                        <div class="card profile-modal-card">
                            <div class="profile-modal-close-top">
                                <a class="btn profile-modal-close-x" href="<?php echo htmlspecialchars($dashboard_base_url); ?>" aria-label="Close modal">&times;</a>
                            </div>
                            <h2>Edit Violation Record</h2>
                            <p class="profile-subtext">Update violation information for TOP # <?php echo htmlspecialchars($edit_target['top_number'] ?: '-'); ?>.</p>
                            <form method="POST">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="violation_id" value="<?php echo (int)$edit_target['id']; ?>">
                                <div class="profile-grid">
                                    <div class="profile-box">
                                        <strong>Location</strong>
                                        <input type="text" name="location" required value="<?php echo htmlspecialchars((string)($edit_target['location'] ?? '')); ?>" style="width:100%; padding:8px; border:1px solid #cdd9ef; border-radius:6px;">
                                    </div>
                                    <div class="profile-box">
                                        <strong>Penalty Amount</strong>
                                        <input type="number" name="fine_amount" min="0" step="0.01" required value="<?php echo htmlspecialchars((string)($edit_target['fine_amount'] ?? '0')); ?>" style="width:100%; padding:8px; border:1px solid #cdd9ef; border-radius:6px;">
                                    </div>
                                </div>
                                <div class="profile-box">
                                    <strong>Violation Details</strong>
                                    <textarea name="violation_details" required rows="3" style="width:100%; padding:8px; border:1px solid #cdd9ef; border-radius:6px;"><?php echo htmlspecialchars((string)($edit_target['violation_details'] ?? $edit_target['violation_name'] ?? '')); ?></textarea>
                                </div>
                                <div style="margin-top: 12px; display:flex; gap:8px;">
                                    <button type="submit" class="btn" style="background: var(--secondary-color); color:white;">Save Changes</button>
                                    <a class="btn" href="<?php echo htmlspecialchars($dashboard_base_url); ?>" style="text-decoration:none;">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($selected_motorist_profile): ?>
                <div class="profile-modal-overlay" role="dialog" aria-modal="true" aria-label="Violator Profile">
                    <div class="card profile-modal-card" id="supervisor-profile-card" tabindex="-1">
                        <div class="profile-modal-close-top">
                            <a class="btn profile-modal-close-x" href="<?php echo htmlspecialchars($dashboard_base_url); ?>" aria-label="Close modal">&times;</a>
                        </div>
                        <div class="profile-modal-header">
                            <div class="profile-modal-title-wrap">
                                <h2>Violator Profile and Violation History Report</h2>
                                <p class="profile-subtext">Review violator details and download the official PDF report.</p>
                            </div>
                            <button type="button" class="btn" id="download-supervisor-modal-pdf" style="background: var(--secondary-color); color: #fff;">Download Official PDF Report</button>
                        </div>
                        <div class="profile-grid">
                            <div class="profile-box">
                                <strong>Violator Name</strong>
                                <?php echo htmlspecialchars($selected_motorist_profile['full_name'] ?: 'N/A'); ?>
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
                                <?php echo number_format(count($selected_motorist_violations)); ?>
                            </div>
                        </div>
                        <div class="profile-box">
                            <strong>Address</strong>
                            <?php echo htmlspecialchars($selected_motorist_profile['address'] ?: 'N/A'); ?>
                        </div>
                        <table>
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
                                        <td><?php echo htmlspecialchars($mv['top_number'] ?: '-'); ?></td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($mv['violation_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($mv['violation_display'] ?: 'Multiple/Custom'); ?></td>
                                        <td><?php echo htmlspecialchars($mv['location'] ?: 'N/A'); ?></td>
                                        <td><?php echo nl2br(htmlspecialchars($mv['incident_description'] ?: 'No narrative submitted.')); ?></td>
                                        <td>₱<?php echo number_format((float)$mv['fine_amount'], 2); ?></td>
                                        <td><span class="badge badge-<?php echo htmlspecialchars($mv['status']); ?>"><?php echo ucfirst($mv['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                        <h3 style="margin-top: 1rem; color:#0d2f6f;">Evidence Gallery</h3>
                        <?php if (!empty($all_evidence_items)): ?>
                            <div class="evidence-gallery">
                                <?php foreach ($all_evidence_items as $g_item): ?>
                                    <a class="evidence-gallery-item" href="#" onclick="showEvidence('<?php echo htmlspecialchars($g_item['file_path'], ENT_QUOTES); ?>'); return false;">
                                        <img src="../uploads/<?php echo htmlspecialchars($g_item['file_path']); ?>" alt="Evidence">
                                        <div class="evidence-gallery-meta">
                                            <strong><?php echo htmlspecialchars($g_item['label']); ?></strong>
                                            TOP #: <?php echo htmlspecialchars($g_item['top_number']); ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="profile-subtext">No uploaded evidence images available for this violator.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <script>
                    window.supervisorMotoristProfilePayload = <?php echo json_encode($selected_motorist_payload, JSON_UNESCAPED_UNICODE); ?>;
                </script>
            <?php elseif ($selected_motorist_id > 0): ?>
                <div class="profile-modal-overlay" role="dialog" aria-modal="true" aria-label="Violator Profile">
                    <div class="card profile-modal-card">
                        <h2>Motorist profile not found.</h2>
                        <a class="btn" href="<?php echo htmlspecialchars($dashboard_base_url); ?>">Close</a>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Evidence Modal -->
    <div id="evidenceModal" class="evidence-modal" onclick="closeEvidence()">
        <span class="close-modal">&times;</span>
        <div class="evidence-modal-content">
            <img id="evidenceImage" src="" alt="Evidence" style="display: none;">
            <div id="evidenceGallery" class="evidence-modal-gallery" style="display: none;"></div>
        </div>
    </div>

    <script>
        function showEvidence(filePath) {
            const image = document.getElementById('evidenceImage');
            const gallery = document.getElementById('evidenceGallery');
            gallery.style.display = 'none';
            gallery.innerHTML = '';
            image.style.display = 'block';
            document.getElementById('evidenceImage').src = '../uploads/' + filePath;
            document.getElementById('evidenceModal').classList.add('active');
        }
        function showEvidenceGallery(filePaths) {
            const image = document.getElementById('evidenceImage');
            const gallery = document.getElementById('evidenceGallery');
            image.style.display = 'none';
            image.src = '';
            gallery.style.display = 'grid';
            gallery.innerHTML = '';

            (filePaths || []).forEach(function(filePath) {
                const img = document.createElement('img');
                img.src = '../uploads/' + filePath;
                img.alt = 'Evidence';
                gallery.appendChild(img);
            });

            document.getElementById('evidenceModal').classList.add('active');
        }
        function closeEvidence() {
            document.getElementById('evidenceModal').classList.remove('active');
            document.getElementById('evidenceGallery').innerHTML = '';
            document.getElementById('evidenceImage').src = '';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const profileOverlay = document.querySelector('.profile-modal-overlay');
            if (profileOverlay) {
                profileOverlay.addEventListener('click', function(event) {
                    if (event.target === profileOverlay) {
                        window.location.href = <?php echo json_encode($dashboard_base_url); ?>;
                    }
                });
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        window.location.href = <?php echo json_encode($dashboard_base_url); ?>;
                    }
                });
            }

            if (window.setupViolatorPdfDownload) {
                window.setupViolatorPdfDownload({
                    buttonId: 'download-supervisor-modal-pdf',
                    payload: window.supervisorMotoristProfilePayload || null,
                    successMessage: 'PDF report generated successfully.'
                });
            }
        });
    </script>
</body>
</html>
