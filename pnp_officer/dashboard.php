<?php
require_once '../auth.php';
check_role('pnp_officer');
require_once '../includes/motorist_profile.php';

global $pdo;
$conn = $pdo;
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$selected_motorist_id = isset($_GET['motorist_id']) ? (int)$_GET['motorist_id'] : 0;
$edit_violation_id = isset($_GET['edit_violation_id']) ? (int)$_GET['edit_violation_id'] : 0;
$search_query = trim((string)($_GET['search'] ?? ''));
$success = '';
$error = '';

// Get current user info
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['violation_id'])) {
    $violation_id = (int)$_POST['violation_id'];
    $new_location = trim((string)($_POST['location'] ?? ''));
    $new_violation_details = trim((string)($_POST['violation_details'] ?? ''));
    $new_fine_amount = (float)($_POST['fine_amount'] ?? 0);

    if ($violation_id <= 0 || $new_location === '' || $new_violation_details === '' || $new_fine_amount < 0) {
        $error = 'Edit failed. Please provide valid location, violation details, and amount.';
    } else {
        $update_stmt = $conn->prepare("UPDATE violations SET location = ?, violation_details = ?, fine_amount = ? WHERE id = ?");
        $update_stmt->execute([$new_location, $new_violation_details, $new_fine_amount, $violation_id]);

        try {
            $audit_stmt = $conn->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
            $audit_stmt->execute([$user_id, 'edit', 'violations', $violation_id, 'Violation details updated by PNP officer']);
        } catch (Throwable $e) {
            // Ignore audit errors so primary update can still succeed.
        }

        $success = 'Violation updated successfully.';
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

// Get violation statistics
$total_violations = $conn->query("SELECT COUNT(*) as cnt FROM violations")->fetch(PDO::FETCH_ASSOC)['cnt'];
$validated_violations = $conn->query("SELECT COUNT(*) as cnt FROM violations WHERE status = 'validated'")->fetch(PDO::FETCH_ASSOC)['cnt'];
$paid_violations = $conn->query("SELECT COUNT(*) as cnt FROM violations WHERE status = 'paid'")->fetch(PDO::FETCH_ASSOC)['cnt'];
$active_field_units = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = 'enforcer' AND status = 'active'")->fetch(PDO::FETCH_ASSOC)['cnt'];
$date_filter = $is_mysql ? "violation_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)" : "date(violation_date) >= date('now', '-6 day')";
$weekly_apprehensions = $conn->query("SELECT COUNT(*) as cnt FROM violations WHERE $date_filter")->fetch(PDO::FETCH_ASSOC)['cnt'];

// Additional data for charts
$summary = [];
$status_counts = $conn->query("SELECT status, COUNT(*) as count FROM violations GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($status_counts as $s) {
    $summary['by_status'][$s['status']] = $s['count'];
}

// Monthly trends (driver compatible)
$month_expr = $is_mysql ? "DATE_FORMAT(violation_date, '%Y-%m')" : "strftime('%Y-%m', violation_date)";
$monthly = $conn->query("SELECT $month_expr as month, COUNT(*) as count
                         FROM violations
                         GROUP BY $month_expr
                         ORDER BY month DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$summary['monthly'] = $monthly;

// Age distribution for PNP dashboard chart
$age_labels = ['Under 18', '18-25', '26-35', '36-45', '46-60', '61+', 'Unknown Age'];
$age_counts = [0, 0, 0, 0, 0, 0, 0];
try {
    if ($is_mysql) {
        try {
            $conn->exec("ALTER TABLE motorists ADD COLUMN date_of_birth DATE NULL");
        } catch (PDOException $e) {
        }
    } else {
        try {
            $motorist_columns = $conn->query("PRAGMA table_info(motorists)")->fetchAll(PDO::FETCH_ASSOC);
            $has_date_of_birth = false;
            foreach ($motorist_columns as $column) {
                if (($column['name'] ?? '') === 'date_of_birth') {
                    $has_date_of_birth = true;
                    break;
                }
            }
            if (!$has_date_of_birth) {
                $conn->exec("ALTER TABLE motorists ADD COLUMN date_of_birth TEXT");
            }
        } catch (PDOException $e) {
        }
    }

    $age_rows = $conn->query("SELECT m.date_of_birth
                              FROM violations v
                              LEFT JOIN motorists m ON m.id = v.motorist_id")->fetchAll(PDO::FETCH_ASSOC);
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
            if ($age < 0 || $age > 120) {
                $age_brackets['Unknown Age']++;
            } elseif ($age < 18) {
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
    $age_labels = array_keys($age_brackets);
    $age_counts = array_values($age_brackets);
} catch (Throwable $e) {
}

// Get recent violations (supports dashboard search)
$recent_sql = "
    SELECT v.*, m.full_name as motorist_name, m.license_number, COALESCE(v.violation_details, p.violation_name) as violation_display, u.full_name as enforcer_name
    FROM violations v
    LEFT JOIN motorists m ON v.motorist_id = m.id
    LEFT JOIN penalties p ON v.penalty_id = p.id
    LEFT JOIN users u ON v.enforcer_id = u.id
";
$recent_params = [];
if ($search_query !== '') {
    $recent_sql .= " WHERE (
        v.top_number LIKE ?
        OR COALESCE(m.full_name, '') LIKE ?
        OR COALESCE(m.license_number, '') LIKE ?
        OR COALESCE(v.violation_details, p.violation_name, '') LIKE ?
        OR COALESCE(v.location, '') LIKE ?
        OR COALESCE(u.full_name, '') LIKE ?
    )";
    $search_like = '%' . $search_query . '%';
    $recent_params = [$search_like, $search_like, $search_like, $search_like, $search_like, $search_like];
}
$recent_sql .= " ORDER BY v.violation_date DESC LIMIT 20";
$recent_stmt = $conn->prepare($recent_sql);
$recent_stmt->execute($recent_params);
$recent_violations = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>PNP Officer Dashboard - Traffic Violation System</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="../public/js/violator-pdf.js" defer></script>
    <script src="../theme.js" defer></script>
    <style>
        body, .main-content { background: #f3f5fb; }
                .pnp-label { font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase; color: #355189; font-weight: 700; margin-bottom: 0.25rem; }
                .pnp-title { margin: 0; font-size: 2.15rem; line-height: 1.1; color: #0a1f44; }
                .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.9rem; margin-bottom: 1rem; }
                .kpi-card { background: #fff; border: 1px solid #e2e8f6; border-radius: 12px; padding: 1rem; box-shadow: 0 3px 10px rgba(16, 46, 107, 0.06); }
                .kpi-card h3 { margin: 0 0 0.45rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: #49608f; }
                .kpi-number { font-size: 2rem; font-weight: 800; color: #0f2d66; line-height: 1.05; }
                .kpi-note { margin-top: 0.25rem; color: #6a7b9f; font-size: 0.8rem; font-weight: 600; }
                .incident-layout { display: grid; grid-template-columns: 1fr; gap: 0.75rem; margin-bottom: 0.75rem; }
                .incident-card { background: #fff; border: 1px solid #e2e8f6; border-radius: 12px; box-shadow: 0 3px 10px rgba(16, 46, 107, 0.06); overflow: hidden; }
                .chart-panel {
                    background: #fff;
                    border: 1px solid #e2e8f6;
                    border-radius: 12px;
                    box-shadow: 0 3px 10px rgba(16, 46, 107, 0.06);
                    padding: 0.9rem 1rem;
                    margin-bottom: 0.9rem;
                }
                .chart-panel h2 {
                    margin: 0 0 0.45rem;
                    color: #0d244f;
                    font-size: 1.05rem;
                }
                .chart-panel-sub {
                    margin: 0 0 0.7rem;
                    color: #62749a;
                    font-size: 0.88rem;
                }
                .chart-panel canvas {
                    width: 100% !important;
                    height: 290px !important;
                }
                .chart-no-data {
                    min-height: 290px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    text-align: center;
                    color: #62749a;
                    font-size: 0.94rem;
                    border: 1px dashed #d6e1f4;
                    border-radius: 10px;
                    background: #fbfdff;
                }
                .incident-head { display: flex; justify-content: space-between; align-items: end; padding: 0.85rem 1rem 0.55rem; }
                .incident-head h2 { margin: 0; color: #0d244f; }
                .incident-head .sub { color: #62749a; font-size: 0.9rem; }
                .incident-head a { font-size: 0.86rem; font-weight: 700; text-decoration: none; color: #1259c3; }
                .dashboard-search-form {
                    display: flex;
                    gap: 8px;
                    align-items: center;
                    margin: 0.45rem 1rem 0.9rem;
                    flex-wrap: wrap;
                }
                .dashboard-search-form input[type="text"] {
                    flex: 1;
                    min-width: 220px;
                    height: 42px;
                    padding: 0.6rem 0.75rem;
                    border: 1px solid #c9d7ef;
                    border-radius: 10px;
                    font-size: 0.95rem;
                    color: #16315f;
                    background: #ffffff;
                }
                .dashboard-search-form input[type="text"]::placeholder {
                    color: #6c7fa6;
                }
                .dashboard-search-form .btn {
                    min-height: 42px;
                    padding: 0.55rem 1rem;
                }
                .incident-card table { margin: 0; }
                .incident-card th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; }
                .incident-card td {
                    color: #1a2f57;
                    font-weight: 600;
                }
                .violation-chips {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.32rem;
                    max-width: 280px;
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
                .motorist-link {
                    color: #0b56ba;
                    text-decoration: none;
                    font-weight: 700;
                }
                .motorist-link:hover { text-decoration: underline; }
                .profile-card {
                    margin-top: 20px;
                    border: 1px solid #d6e1f3;
                    border-radius: 12px;
                    background: #fdfefe;
                    padding: 1.15rem;
                }
                .profile-subtext {
                    margin-top: 0;
                    margin-bottom: 14px;
                    color: #526a95;
                    line-height: 1.55;
                    font-size: 1rem;
                }
                .profile-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 10px;
                    margin: 10px 0 12px;
                }
                .profile-box {
                    background: #fff;
                    border: 1px solid #e4e9f4;
                    border-radius: 8px;
                    padding: 0.85rem;
                    font-size: 1.08rem;
                }
                .profile-box strong {
                    display: block;
                    color: #4f638e;
                    font-size: 0.82rem;
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
                    text-decoration: none;
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
                    border-radius: 14px;
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
                    border: 1px solid #ccd9f0;
                    border-radius: 12px;
                    background: linear-gradient(180deg, #ffffff 0%, #f4f8ff 100%);
                    padding: 14px 16px 12px;
                    margin-bottom: 14px;
                }
                .report-header-top {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 14px;
                    border-bottom: 1px solid #dfe8f7;
                    padding-bottom: 10px;
                    margin-bottom: 12px;
                }
                .report-logo-wrap {
                    width: 64px;
                    height: 64px;
                    border: 1px solid #d8e2f3;
                    border-radius: 50%;
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
                    padding: 0;
                    transform: scale(2.2);
                    transform-origin: center;
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
                    font-size: 1.14rem;
                    letter-spacing: 0.02em;
                }
                .report-sub {
                    color: #4f638e;
                    font-size: 0.96rem;
                    margin-top: 0.2rem;
                }
                .report-title {
                    margin: 0;
                    color: #0d244f;
                    font-size: 1.44rem;
                    line-height: 1.28;
                }
                .report-title-row {
                    margin-bottom: 10px;
                    align-items: center;
                    gap: 12px;
                }
                .report-title-row .btn {
                    margin: 0;
                    white-space: nowrap;
                }
                #download-dashboard-profile-pdf {
                    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
                    border: 1px solid #1e3a8a;
                    color: #ffffff;
                    font-weight: 800;
                    box-shadow: 0 8px 18px rgba(29, 78, 216, 0.36);
                    transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
                }
                #download-dashboard-profile-pdf:hover {
                    filter: brightness(1.04);
                    transform: translateY(-1px);
                    box-shadow: 0 12px 22px rgba(29, 78, 216, 0.44);
                }
                #download-dashboard-profile-pdf:focus-visible {
                    outline: 2px solid #93c5fd;
                    outline-offset: 2px;
                }
                .report-meta {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                    gap: 8px 14px;
                    font-size: 0.9rem;
                    color: #314a77;
                    margin-top: 6px;
                    padding-top: 8px;
                    border-top: 1px dashed #d7e3f7;
                }
                .report-meta strong {
                    color: #223d71;
                }
                .report-section-title {
                    margin: 16px 0 10px;
                    color: #173a79;
                    font-size: 1rem;
                    font-weight: 800;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                }
                .profile-subtext {
                    margin-bottom: 12px;
                    font-size: 0.95rem;
                }
                .profile-history-table {
                    border-collapse: separate;
                    border-spacing: 0;
                    border: 1px solid #d9e4f5;
                    border-radius: 10px;
                    overflow: hidden;
                }
                .profile-history-table th {
                    background: #edf3fd;
                    color: #1f3868;
                    font-size: 0.8rem;
                    letter-spacing: 0.06em;
                    text-transform: uppercase;
                    font-weight: 800;
                    padding: 0.68rem 0.56rem;
                    border-bottom: 1px solid #d9e4f5;
                }
                .profile-history-table td {
                    vertical-align: top;
                    font-size: 0.94rem;
                    line-height: 1.42;
                    padding: 0.66rem 0.56rem;
                    border-bottom: 1px solid #ebf1fb;
                }
                .profile-history-table tbody tr:nth-child(even) td {
                    background: #fafcff;
                }
                .profile-history-table tbody tr:hover td {
                    background: #f2f7ff;
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
                html[data-theme="dark"] .kpi-card,
                html[data-theme="dark"] .incident-card,
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
                html[data-theme="dark"] .pnp-label,
                html[data-theme="dark"] .kpi-card h3,
                html[data-theme="dark"] .kpi-note,
                html[data-theme="dark"] .incident-head .sub,
                html[data-theme="dark"] .profile-subtext,
                html[data-theme="dark"] .profile-box strong,
                html[data-theme="dark"] .report-sub,
                html[data-theme="dark"] .report-meta,
                html[data-theme="dark"] .evidence-gallery-meta {
                    color: #a8bcdf;
                }
                html[data-theme="dark"] .pnp-title,
                html[data-theme="dark"] .kpi-number,
                html[data-theme="dark"] .incident-head h2,
                html[data-theme="dark"] .report-title,
                html[data-theme="dark"] .report-office,
                html[data-theme="dark"] .report-section-title,
                html[data-theme="dark"] .evidence-gallery-meta strong {
                    color: #e6efff;
                }
                html[data-theme="dark"] #download-dashboard-profile-pdf {
                    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
                    border-color: #3b82f6;
                    color: #eff6ff;
                    box-shadow: 0 10px 22px rgba(37, 99, 235, 0.34);
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
                html[data-theme="dark"] .report-meta {
                    border-top-color: #32476b;
                }
                html[data-theme="dark"] .profile-history-table {
                    border-color: #2b3a57;
                }
                html[data-theme="dark"] .profile-history-table td {
                    border-bottom-color: #2b3a57;
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
                html[data-theme="dark"] .dashboard-search-form input[type="text"] {
                    background: #1f2d45;
                    border-color: #3b4f75;
                    color: #d8e4ff;
                }
                html[data-theme="dark"] .dashboard-search-form input[type="text"]::placeholder {
                    color: #8ea6cf;
                }
                html[data-theme="dark"] .incident-card td {
                    color: #d7e4ff;
                }
                html[data-theme="dark"] .chart-panel {
                    background: #162033;
                    border-color: #2b3a57;
                }
                html[data-theme="dark"] .chart-panel h2 {
                    color: #e6efff;
                }
                html[data-theme="dark"] .chart-panel-sub,
                html[data-theme="dark"] .chart-no-data {
                    color: #b5c6e4;
                }
                html[data-theme="dark"] .chart-no-data {
                    background: #162235;
                    border-color: #2d3f5d;
                }
                html[data-theme="dark"] .incident-card tr:nth-child(even) td {
                    background: rgba(20, 31, 50, 0.35);
                }
                html[data-theme="dark"] .incident-card tr:hover td {
                    background: rgba(59, 79, 117, 0.32);
                }
                @media (max-width: 980px) {
                    .incident-layout { grid-template-columns: 1fr; }
                    .profile-card { padding: 0.95rem; }
                    .report-title-row { gap: 8px; }
                    .report-title-row .btn { width: 100%; }
                    .report-meta {
                        grid-template-columns: 1fr;
                        gap: 6px;
                    }
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>🚔 PNP Officer</h2>
            <ul>
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <main class="main-content">
            <header>
                <div>
                    <div class="pnp-label">Municipal Traffic Management Office</div>
                    <h1 class="pnp-title">PNP Control Dashboard</h1>
                </div>
                <div class="user-info">Welcome, <?php echo htmlspecialchars($full_name); ?></div>
            </header>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <section class="role-hero-banner">
                <h2>Welcome, <?php echo htmlspecialchars((string)($_SESSION['full_name'] ?? 'PNP Officer')); ?>!</h2>
                <p>Track validated cases, monitor incidents, and access detailed violation intelligence for enforcement support.</p>
            </section>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <h3>Weekly Apprehensions</h3>
                    <div class="kpi-number"><?php echo number_format($weekly_apprehensions); ?></div>
                    <div class="kpi-note">+ trend monitoring</div>
                </div>
                <div class="kpi-card">
                    <h3>Active Field Units</h3>
                    <div class="kpi-number"><?php echo number_format($active_field_units); ?></div>
                    <div class="kpi-note">Live units in operation</div>
                </div>
            </div>

            <section class="chart-panel">
                <h2>Violators by Age Group</h2>
                <p class="chart-panel-sub">See which age group has more recorded violations.</p>
                <canvas id="pnp-age-chart"></canvas>
            </section>

            <div class="incident-layout">
                <div class="incident-card">
                    <div class="incident-head">
                        <div>
                            <h2>Recent Violation Reports</h2>
                            <div class="sub">Live feed of processed violations across all sectors.</div>
                        </div>
                        <a href="#detailed-violation-records">View All Violations</a>
                    </div>
                    <form method="GET" class="dashboard-search-form">
                        <?php if ($selected_motorist_id > 0): ?>
                            <input type="hidden" name="motorist_id" value="<?php echo (int)$selected_motorist_id; ?>">
                        <?php endif; ?>
                        <input
                            type="text"
                            name="search"
                            value="<?php echo htmlspecialchars($search_query); ?>"
                            placeholder="Search TOP #, motorist, license, violation, location, or enforcer"
                        >
                        <button type="submit" class="btn btn-primary">Search</button>
                        <?php if ($search_query !== ''): ?>
                            <a href="dashboard.php<?php echo $selected_motorist_id > 0 ? '?motorist_id=' . (int)$selected_motorist_id : ''; ?>" class="btn">Clear</a>
                        <?php endif; ?>
                    </form>
                    <table>
                        <thead>
                            <tr>
                                <th>Violation ID</th>
                                <th>Violation Type</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach(array_slice($recent_violations, 0, 8) as $v): ?>
                                <tr>
                                    <td><strong>#INC-<?php echo date('Y', strtotime($v['violation_date'])); ?>-<?php echo str_pad((string)$v['id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                                    <td>
                                        <div class="violation-chips">
                                            <?php foreach (split_violation_items($v['violation_display'] ?? 'Violation') as $item): ?>
                                                <span class="violation-chip"><?php echo htmlspecialchars($item); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($v['location']); ?></td>
                                    <td><span class="badge badge-<?php echo $v['status']; ?>"><?php echo strtoupper($v['status']); ?></span></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($v['violation_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card" id="detailed-violation-records">
                <h2>List of Violators</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>TOP Number</th>
                            <th>Motorist</th>
                            <th>License #</th>
                            <th>Violation</th>
                            <th>Location</th>
                            <th>Amount</th>
                            <th>Apprehending Officer</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_violations as $v): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($v['violation_date'])); ?></td>
                                <td><code style="background: #f0f0f0; color: #1f2937; padding: 2px 6px; border-radius: 4px; font-size: 0.85rem; white-space: nowrap;"><?php echo $v['top_number']; ?></code></td>
                                <td>
                                    <a class="motorist-link" href="?motorist_id=<?php echo (int)$v['motorist_id']; ?><?php echo $search_query !== '' ? '&search=' . urlencode($search_query) : ''; ?>">
                                        <?php echo htmlspecialchars($v['motorist_name']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($v['license_number']); ?></td>
                                <td>
                                    <div class="violation-chips">
                                        <?php foreach (split_violation_items($v['violation_display'] ?? 'Multiple/Custom') as $item): ?>
                                            <span class="violation-chip"><?php echo htmlspecialchars($item); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($v['location']); ?></td>
                                <td>₱<?php echo number_format($v['fine_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($v['enforcer_name']); ?></td>
                                <td><span class="badge badge-<?php echo $v['status']; ?>"><?php echo ucfirst($v['status']); ?></span></td>
                                <td>
                                    <a
                                        class="btn"
                                        href="?edit_violation_id=<?php echo (int)$v['id']; ?><?php echo $search_query !== '' ? '&search=' . urlencode($search_query) : ''; ?>"
                                        style="background: #1d4ed8; color:#fff; text-decoration:none; padding:4px 8px; font-size:0.8rem;"
                                    >Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($edit_violation_id > 0): ?>
                <?php
                $edit_target = null;
                foreach ($recent_violations as $candidate_violation) {
                    if ((int)$candidate_violation['id'] === $edit_violation_id) {
                        $edit_target = $candidate_violation;
                        break;
                    }
                }
                ?>
                <?php if ($edit_target): ?>
                    <div class="profile-modal-overlay" role="dialog" aria-modal="true" aria-label="Edit violation">
                        <div class="profile-card profile-modal-card">
                            <div class="profile-modal-close-top">
                                <a class="btn profile-modal-close profile-modal-close-x" href="dashboard.php<?php echo $search_query !== '' ? '?search=' . urlencode($search_query) : ''; ?>" aria-label="Close modal">&times;</a>
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
                                    <textarea name="violation_details" required rows="3" style="width:100%; padding:8px; border:1px solid #cdd9ef; border-radius:6px;"><?php echo htmlspecialchars((string)($edit_target['violation_details'] ?? $edit_target['violation_display'] ?? '')); ?></textarea>
                                </div>
                                <div style="margin-top: 12px; display:flex; gap:8px;">
                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                    <a class="btn" href="dashboard.php<?php echo $search_query !== '' ? '?search=' . urlencode($search_query) : ''; ?>" style="text-decoration:none;">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

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
                        <a class="btn profile-modal-close profile-modal-close-x" href="dashboard.php" aria-label="Close modal">&times;</a>
                    </div>
                    <div class="report-header">
                        <div class="report-header-top">
                            <div class="report-logo-wrap">
                                <img src="../assets/images/traffic-logo.png" alt="MTMO Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <span class="report-logo-fallback" style="display:none;">MTMO</span>
                            </div>
                            <div class="report-office-block">
                                <div class="report-office">Municipal Traffic Management Office - PNP</div>
                                <div class="report-sub">Pototan, Iloilo | Real-Time Traffic Violation Monitoring Unit</div>
                            </div>
                        </div>
                        <div class="report-title-row">
                            <h2 class="report-title">Violator Profile and Violation History Report</h2>
                            <button type="button" class="btn" id="download-dashboard-profile-pdf">Download Official PDF Report</button>
                        </div>
                        <div class="report-meta">
                            <div><strong>Generated On:</strong> <?php echo date('M d, Y h:i A'); ?></div>
                            <div><strong>Generated By:</strong> <?php echo htmlspecialchars($full_name); ?></div>
                            <div><strong>Reference:</strong> PNP-DOC-<?php echo date('Ymd-His'); ?></div>
                        </div>
                    </div>
                    <p class="profile-subtext">Full offense history with evidence for reporting and monitoring by authorized PNP personnel.</p>
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
                    window.dashboardMotoristProfilePayload = <?php echo json_encode($profile_payload, JSON_UNESCAPED_UNICODE); ?>;
                </script>
            <?php elseif ($selected_motorist_id > 0): ?>
                <div class="profile-card">
                    <strong>Motorist profile not found.</strong>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        (function () {
            if (typeof Chart === 'undefined') return;
            const chartCanvas = document.getElementById('pnp-age-chart');
            if (!chartCanvas) return;
            const ageLabels = <?php echo json_encode($age_labels); ?>;
            const ageData = <?php echo json_encode($age_counts); ?>;
            const hasData = Array.isArray(ageData) && ageData.some(function (v) { return Number(v) > 0; });
            if (!hasData && chartCanvas.parentElement) {
                chartCanvas.style.display = 'none';
                const placeholder = document.createElement('div');
                placeholder.className = 'chart-no-data';
                placeholder.textContent = 'No age data available yet. Add motorists with date of birth to populate this chart.';
                chartCanvas.parentElement.appendChild(placeholder);
                return;
            }
            const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
            const axisColor = isDarkTheme ? '#c8d8f3' : '#4f648f';
            const gridColor = isDarkTheme ? 'rgba(167, 194, 238, 0.24)' : 'rgba(33, 77, 157, 0.12)';
            new Chart(chartCanvas, {
                type: 'bar',
                data: {
                    labels: ageLabels,
                    datasets: [{
                        label: 'Violations',
                        data: ageData,
                        backgroundColor: ['#6f42c1', '#17a2b8', '#007bff', '#28a745', '#ffc107', '#fd7e14', '#9aa3b2'],
                        borderColor: isDarkTheme ? '#d6e4ff' : '#1e3b8a',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: axisColor },
                            grid: { color: gridColor }
                        },
                        x: {
                            ticks: { color: axisColor },
                            grid: { display: false }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }());

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
                        window.location.href = 'dashboard.php';
                    }
                });

                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        if (evidenceLightbox && evidenceLightbox.classList.contains('is-open')) {
                            closeEvidenceLightbox();
                            return;
                        }
                        window.location.href = 'dashboard.php';
                    }
                });
            }

            window.setupViolatorPdfDownload({
                buttonId: 'download-dashboard-profile-pdf',
                payload: window.dashboardMotoristProfilePayload || null,
                successMessage: 'PDF report generated successfully.'
            });
        });
    </script>
</body>
</html>
