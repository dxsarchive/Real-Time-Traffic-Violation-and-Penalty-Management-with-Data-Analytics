<?php
require_once '../auth.php';
check_role('motorist');

global $pdo;
$conn = $pdo;
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

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

if ($is_mysql) {
    $conn->exec("CREATE TABLE IF NOT EXISTS feedback_concerns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        motorist_user_id INT NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        reference_number VARCHAR(100) NOT NULL,
        violation_id INT NULL,
        contact_info VARCHAR(150) NOT NULL,
        concern_type VARCHAR(40) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('Pending','Reviewed','Resolved') DEFAULT 'Pending',
        supervisor_response TEXT NULL,
        supervisor_id INT NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_feedback_motorist (motorist_user_id),
        INDEX idx_feedback_status (status),
        INDEX idx_feedback_reference (reference_number),
        FOREIGN KEY (motorist_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (violation_id) REFERENCES violations(id) ON DELETE SET NULL,
        FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
    )");
} else {
    $conn->exec("CREATE TABLE IF NOT EXISTS feedback_concerns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        motorist_user_id INTEGER NOT NULL,
        full_name TEXT NOT NULL,
        reference_number TEXT NOT NULL,
        violation_id INTEGER,
        contact_info TEXT NOT NULL,
        concern_type TEXT NOT NULL,
        message TEXT NOT NULL,
        status TEXT DEFAULT 'Pending',
        supervisor_response TEXT,
        supervisor_id INTEGER,
        reviewed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (motorist_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (violation_id) REFERENCES violations(id) ON DELETE SET NULL,
        FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
    )");
}

$errors = [];
$success = '';
$full_name = trim($_SESSION['full_name'] ?? '');
$reference_number = '';
$contact_info = '';
$concern_type = 'Dispute';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $reference_number = trim($_POST['reference_number'] ?? '');
    $contact_info = trim($_POST['contact_info'] ?? '');
    $concern_type = trim($_POST['concern_type'] ?? 'Dispute');
    $message = trim($_POST['message'] ?? '');

    if ($full_name === '') {
        $errors[] = 'Full Name is required.';
    }
    if ($reference_number === '') {
        $errors[] = 'Violation Reference Number / Ticket ID is required.';
    }
    if ($contact_info === '') {
        $errors[] = 'Contact Information is required.';
    }
    if ($message === '') {
        $errors[] = 'Message / Description is required.';
    }
    if (strlen($message) > 2500) {
        $errors[] = 'Message is too long. Please keep it within 2500 characters.';
    }

    $allowed_types = ['Dispute', 'Inquiry', 'Complaint'];
    if (!in_array($concern_type, $allowed_types, true)) {
        $errors[] = 'Invalid concern type selected.';
    }

    $matched_violation_id = null;
    if (empty($errors)) {
        $ref_query = $conn->prepare("SELECT id FROM violations WHERE top_number = ? LIMIT 1");
        $ref_query->execute([$reference_number]);
        $row = $ref_query->fetch(PDO::FETCH_ASSOC);

        if (!$row && ctype_digit($reference_number)) {
            $id_query = $conn->prepare("SELECT id FROM violations WHERE id = ? LIMIT 1");
            $id_query->execute([(int)$reference_number]);
            $row = $id_query->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) {
            $errors[] = 'Reference number is invalid. Please provide a valid TOP number or Ticket ID.';
        } else {
            $matched_violation_id = (int)$row['id'];
        }
    }

    if (empty($errors)) {
        $insert_stmt = $conn->prepare("INSERT INTO feedback_concerns (
            motorist_user_id, full_name, reference_number, violation_id, contact_info, concern_type, message, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $insert_stmt->execute([
            (int)$_SESSION['user_id'],
            $full_name,
            $reference_number,
            $matched_violation_id,
            $contact_info,
            $concern_type,
            $message
        ]);
        $success = 'Your feedback has been submitted successfully and forwarded to the Supervisor Portal.';
        $reference_number = '';
        $contact_info = '';
        $concern_type = 'Dispute';
        $message = '';
    }
}

$my_feedback_stmt = $conn->prepare("SELECT id, reference_number, concern_type, message, status, supervisor_response, created_at, updated_at
                                    FROM feedback_concerns
                                    WHERE motorist_user_id = ?
                                    ORDER BY created_at DESC");
$my_feedback_stmt->execute([(int)$_SESSION['user_id']]);
$my_feedback = $my_feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

$welcome_articles = [];
$welcome_announcements = [];
$welcome_tutorial_videos = [];
$office_contact = [
    'office_name' => 'Municipal Traffic Management Office',
    'address' => 'Pototan, Iloilo',
    'phone' => '09637464431',
    'email' => 'pototanmtmo@gmail.com'
];

$total_feedback = count($my_feedback);
$pending_feedback = 0;
$reviewed_feedback = 0;
$resolved_feedback = 0;
foreach ($my_feedback as $item) {
    if (($item['status'] ?? '') === 'Pending') {
        $pending_feedback++;
    } elseif (($item['status'] ?? '') === 'Reviewed') {
        $reviewed_feedback++;
    } elseif (($item['status'] ?? '') === 'Resolved') {
        $resolved_feedback++;
    }
}

$total_violations = 0;
$unpaid_violations = 0;
$my_violations = [];
try {
    $violations_count_stmt = $conn->prepare("SELECT COUNT(*) AS total,
                                                    SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid
                                             FROM violations v
                                             INNER JOIN motorists m ON v.motorist_id = m.id
                                             WHERE UPPER(TRIM(m.full_name)) = UPPER(TRIM(?))");
    $violations_count_stmt->execute([$_SESSION['full_name'] ?? '']);
    $violations_stats = $violations_count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_violations = (int)($violations_stats['total'] ?? 0);
    $unpaid_violations = (int)($violations_stats['unpaid'] ?? 0);

    $my_violations_stmt = $conn->prepare("SELECT v.top_number,
                                                 v.violation_date,
                                                 COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') AS violation_display,
                                                 COALESCE(v.location, 'N/A') AS location,
                                                 COALESCE(v.fine_amount, 0) AS fine_amount,
                                                 COALESCE(v.status, 'pending') AS status
                                          FROM violations v
                                          INNER JOIN motorists m ON v.motorist_id = m.id
                                          LEFT JOIN penalties p ON v.penalty_id = p.id
                                          WHERE m.user_id = ?
                                          ORDER BY v.violation_date DESC");
    $my_violations_stmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $my_violations = $my_violations_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $total_violations = 0;
    $unpaid_violations = 0;
    $my_violations = [];
}

try {
    require_once __DIR__ . '/../includes/ensure_articles_schema.php';
    ensure_articles_schema($conn, $conn->getAttribute(PDO::ATTR_DRIVER_NAME));

    $welcome_articles = $conn->query("SELECT title, content, published_at, COALESCE(link_url, '') AS link_url, COALESCE(attachment_path, '') AS attachment_path
                                      FROM articles
                                      WHERE COALESCE(is_active, 1) = 1
                                      ORDER BY published_at DESC
                                      LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $welcome_announcements = $conn->query("SELECT title, content, image_path, posted_at
                                           FROM announcements
                                           WHERE is_active = 1
                                           ORDER BY posted_at DESC
                                           LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $welcome_tutorial_videos = $conn->query("SELECT title, url, file_path, description
                                             FROM tutorial_videos
                                             WHERE is_active = 1
                                             ORDER BY sort_order ASC, created_at DESC
                                             LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $welcome_articles = [];
    $welcome_announcements = [];
    $welcome_tutorial_videos = [];
}

$settled_violations = max(0, $total_violations - $unpaid_violations);

// Supervisor-style transparency graphs (global traffic reports)
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$trend_labels = [];
$trend_data = [];
$monthly_trend_labels = [];
$monthly_trend_data = [];
$top_location_labels = [];
$top_location_counts = [];
$age_labels = ['Minor (Below 18)', 'Young Adult (18-24)', 'Adult (25-59)', 'Senior (60+)', 'Unknown Age'];
$age_counts = [0, 0, 0, 0, 0];
$paid_count = 0;
$unpaid_count = 0;

try {
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
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i day"));
        $trend_labels[] = strtoupper(date('D', strtotime($day)));
        $trend_data[] = $weekly_map[$day] ?? 0;
    }

    $month_group_expr = $is_mysql ? "DATE_FORMAT(violation_date, '%Y-%m')" : "strftime('%Y-%m', violation_date)";
    $monthly_rows = $conn->query("SELECT $month_group_expr AS month_key, COUNT(*) AS count
                                  FROM violations
                                  GROUP BY $month_group_expr
                                  ORDER BY month_key DESC
                                  LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $monthly_rows = array_reverse($monthly_rows);
    foreach ($monthly_rows as $row) {
        $month_ts = strtotime($row['month_key'] . '-01');
        $monthly_trend_labels[] = $month_ts ? date('M Y', $month_ts) : $row['month_key'];
        $monthly_trend_data[] = (int)$row['count'];
    }

    $paid_count = (int)$conn->query("SELECT COUNT(*) AS count FROM violations WHERE status = 'paid'")->fetch(PDO::FETCH_ASSOC)['count'];
    $global_total = (int)$conn->query("SELECT COUNT(*) AS count FROM violations")->fetch(PDO::FETCH_ASSOC)['count'];
    $unpaid_count = max(0, $global_total - $paid_count);

    $locations = $conn->query("SELECT location, COUNT(*) as count
                               FROM violations
                               GROUP BY location
                               ORDER BY count DESC
                               LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($locations as $loc_row) {
        $label = trim((string)($loc_row['location'] ?? ''));
        $top_location_labels[] = $label !== '' ? $label : 'Unknown Location';
        $top_location_counts[] = (int)($loc_row['count'] ?? 0);
    }

    $age_group_counts = [
        'Minor (Below 18)' => 0,
        'Young Adult (18-24)' => 0,
        'Adult (25-59)' => 0,
        'Senior (60+)' => 0,
        'Unknown Age' => 0
    ];
    $age_rows = [];
    $has_dob_column = table_has_column($conn, $is_mysql, 'motorists', 'date_of_birth');
    if ($has_dob_column) {
        $age_rows = $conn->query("SELECT m.date_of_birth
                                  FROM violations v
                                  LEFT JOIN motorists m ON v.motorist_id = m.id")->fetchAll(PDO::FETCH_ASSOC);
    }
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
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motorist Dashboard</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="../theme.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { margin: 0; background: linear-gradient(180deg, #eef3fb 0%, #f6f9ff 100%); font-family: 'Segoe UI', Arial, sans-serif; color: #12284d; }
        .motorist-topnav {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.2rem;
            padding: 1rem 1.5rem;
            background: rgba(255, 255, 255, 0.92);
            color: #1b2a47;
            border-bottom: 1px solid #e6ebf5;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 24px rgba(15, 35, 76, 0.08);
        }
        .motorist-topnav h1 {
            margin: 0;
            font-size: 1.3rem;
            color: #6b2fc9;
            flex-shrink: 0;
        }
        .motorist-topnav-links {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.9rem;
            flex-wrap: wrap;
            flex: 1;
            min-width: 0;
        }
        .motorist-topnav-links a { color: #4b5873; text-decoration: none; font-weight: 700; font-size: 0.96rem; white-space: nowrap; padding: 0.35rem 0.62rem; border-radius: 999px; transition: background 0.2s ease, color 0.2s ease; }
        .motorist-topnav-links a:hover { background: rgba(107, 47, 201, 0.1); }
        .motorist-topnav-links a:hover { color: #6b2fc9; }
        .motorist-topnav-links .active-nav {
            color: #6b2fc9;
            border-bottom: 3px solid #6b2fc9;
            padding-bottom: 0.28rem;
            background: rgba(107, 47, 201, 0.08);
            border-radius: 6px;
            padding-left: 0.35rem;
            padding-right: 0.35rem;
        }
        .motorist-menu-icon {
            display: none;
            border: 1px solid #d5deef;
            background: #fff;
            color: #45526d;
            border-radius: 8px;
            padding: 0.45rem 0.7rem;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
        }
        .motorist-profile-toggle {
            border: 2px solid #d5deef;
            background: #fff;
            color: #45526d;
            border-radius: 50%;
            width: 42px;
            height: 42px;
            padding: 0;
            cursor: pointer;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .motorist-avatar-toggle {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.88rem;
            letter-spacing: 0.02em;
            object-fit: cover;
            overflow: hidden;
            background: linear-gradient(135deg, #6b2fc9 0%, #8a45db 100%);
            color: #fff;
        }
        .motorist-avatar-toggle.default-avatar,
        .motorist-avatar.default-avatar {
            background:
                #eef2ff
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='16' r='8' fill='%235b6b8a'/%3E%3Cpath d='M10 40c1.8-7.5 8-12 14-12s12.2 4.5 14 12' fill='none' stroke='%235b6b8a' stroke-width='5' stroke-linecap='round'/%3E%3C/svg%3E")
                center / 72% 72% no-repeat;
            color: transparent;
            font-size: 0;
            border: 1px solid #d6deef;
        }
        .motorist-profile-popover {
            position: absolute;
            top: 74px;
            right: 1.4rem;
            background: #ffffff;
            border: 1px solid #dbe6fa;
            border-radius: 12px;
            box-shadow: 0 10px 26px rgba(18, 49, 107, 0.16);
            padding: 0.75rem 0.9rem;
            min-width: 220px;
            display: none;
            flex-direction: column;
            align-items: stretch;
            gap: 0.55rem;
            z-index: 60;
            cursor: default;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .motorist-profile-popover.open {
            display: flex;
        }
        .motorist-profile-popover:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(18, 49, 107, 0.2);
        }
        .motorist-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6b2fc9 0%, #8a45db 100%);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.95rem;
            letter-spacing: 0.02em;
            flex-shrink: 0;
            object-fit: cover;
            overflow: hidden;
        }
        .motorist-profile-name {
            font-weight: 700;
            color: #17396f;
            font-size: 0.92rem;
            line-height: 1.3;
            word-break: break-word;
        }
        .motorist-profile-meta {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            min-width: 0;
        }
        .motorist-profile-head {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            min-width: 0;
        }
        .motorist-profile-logout {
            text-decoration: none;
            color: #5e6f91;
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            border-top: 1px solid #e5ebf7;
            padding-top: 0.45rem;
        }
        .motorist-profile-logout:hover {
            color: #6b2fc9;
        }
        .motorist-page { width: min(1280px, 100%); margin: 0 auto; padding: 1.1rem; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes softPop {
            from { opacity: 0; transform: translateY(10px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .motorist-banner {
            background:
                linear-gradient(120deg, rgba(32, 116, 220, 0.74) 0%, rgba(79, 163, 245, 0.68) 45%, rgba(23, 98, 200, 0.78) 100%),
                url('../assets/images/pototan-hall-wide.png') center center / cover no-repeat;
            color: #fff;
            border-radius: 20px;
            min-height: 55vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2.8rem 1.4rem;
            box-shadow: 0 16px 34px rgba(25, 88, 163, 0.28);
            margin-bottom: 1.15rem;
            animation: fadeUp 0.6s ease forwards;
        }
        .motorist-banner h2 { margin: 0 0 0.65rem; font-size: clamp(2.4rem, 5.2vw, 3.4rem); text-align: center; text-shadow: 0 10px 22px rgba(13, 6, 34, 0.34); }
        .motorist-banner p { margin: 0 auto; color: rgba(255, 255, 255, 0.95); max-width: 900px; text-align: center; font-size: 1.18rem; line-height: 1.6; }
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 0.9rem;
            margin-bottom: 1.1rem;
        }
        .analytics-card {
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            border: 1px solid #d6e3fb;
            border-radius: 14px;
            padding: 1rem 1.05rem;
            box-shadow: 0 8px 18px rgba(18, 49, 107, 0.1);
            transition: transform 0.22s ease, box-shadow 0.22s ease;
            animation: softPop 0.5s ease both;
        }
        .analytics-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(18, 49, 107, 0.14);
        }
        .analytics-card:nth-child(1) { animation-delay: 0.08s; }
        .analytics-card:nth-child(2) { animation-delay: 0.14s; }
        .analytics-card:nth-child(3) { animation-delay: 0.2s; }
        .analytics-card:nth-child(4) { animation-delay: 0.26s; }
        .analytics-card:nth-child(1) { border-color: #ccb6f2; }
        .analytics-card:nth-child(2) { border-color: #f6c2c2; }
        .analytics-card:nth-child(3) { border-color: #b9efdc; }
        .analytics-card:nth-child(4) { border-color: #bfd7fb; }
        .analytics-card h3 { margin: 0; color: #5b6e95; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
        .analytics-card p { margin: 0.38rem 0 0; font-size: 1.7rem; color: #0d2f6f; font-weight: 800; }
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .chart-card {
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            border: 1px solid #d7e4fb;
            border-radius: 14px;
            padding: 1rem;
            box-shadow: 0 8px 18px rgba(18, 49, 107, 0.1);
            animation: fadeUp 0.55s ease both;
        }
        .chart-card h3 {
            margin: 0 0 0.55rem;
            color: #0d2f6f;
            font-size: 1rem;
        }
        .chart-card canvas {
            width: 100% !important;
            height: 190px !important;
        }
        .chart-card.wide {
            grid-column: 1 / -1;
        }
        .no-chart-data {
            min-height: 190px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #6a7a9b;
            font-size: 0.92rem;
            border: 1px dashed #d8e1f2;
            border-radius: 10px;
            background: #fbfdff;
            padding: 0.8rem;
        }
        .supervisor-reports-title {
            margin: 0 0 0.35rem;
            color: #0d2f6f;
        }
        .supervisor-reports-subtitle {
            margin: 0 0 0.9rem;
            color: #5a6d95;
            font-size: 0.92rem;
        }
        .section-card {
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            border: 1px solid #d7e4fb;
            border-radius: 16px;
            padding: 1.1rem;
            box-shadow: 0 10px 22px rgba(18, 49, 107, 0.1);
            margin-bottom: 1rem;
            animation: fadeUp 0.55s ease both;
        }
        .section-card:nth-of-type(1) { animation-delay: 0.12s; }
        .section-card:nth-of-type(2) { animation-delay: 0.18s; }
        .section-card:nth-of-type(3) { animation-delay: 0.24s; }
        .section-card:nth-of-type(4) { animation-delay: 0.3s; }
        .section-card:nth-of-type(5) { animation-delay: 0.36s; }
        .section-card h2 { margin: 0 0 0.85rem; color: #0d2f6f; }
        .cards-grid { display: grid; grid-template-columns: repeat(3, minmax(200px, 1fr)); gap: 0.8rem; }
        .mini-card {
            border: 1px solid #dfe8f8;
            border-radius: 12px;
            padding: 0.85rem;
            background: linear-gradient(180deg, #ffffff 0%, #fafdff 100%);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .mini-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(18, 49, 107, 0.1);
        }
        .mini-card h4 { margin: 0 0 0.35rem; font-size: 1rem; color: #102e62; }
        .mini-card p { margin: 0; color: #50648f; font-size: 0.9rem; }
        .mini-card img { width: 100%; border-radius: 8px; margin: 0.45rem 0 0.5rem; }
        .feedback-layout { display: grid; grid-template-columns: minmax(300px, 1fr) minmax(340px, 1.1fr); gap: 1rem; }
        .feedback-card { background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%); border: 1px solid #d7e4fb; border-radius: 14px; padding: 1rem; box-shadow: 0 8px 18px rgba(18, 49, 107, 0.1); }
        .feedback-card h2 { margin: 0 0 0.85rem; color: #0d2f6f; }
        .feedback-help { color: #5a6d95; margin: 0 0 1rem; }
        .feedback-form .form-group { margin-bottom: 0.8rem; }
        .feedback-form label { display: block; font-weight: 600; color: #1f3d73; margin-bottom: 0.35rem; }
        .feedback-form input, .feedback-form select, .feedback-form textarea { width: 100%; border: 1px solid #ccdaf4; border-radius: 10px; padding: 0.68rem 0.8rem; font-size: 0.93rem; box-sizing: border-box; background: #fdfefe; }
        .feedback-form input:focus, .feedback-form select:focus, .feedback-form textarea:focus { outline: none; border-color: #6d44d5; box-shadow: 0 0 0 3px rgba(109, 68, 213, 0.16); }
        .feedback-form textarea { min-height: 120px; resize: vertical; }
        .feedback-form .btn-submit { width: 100%; padding: 0.7rem; border: 0; border-radius: 8px; background: #0f56c3; color: #fff; font-weight: 700; cursor: pointer; }
        .status-chip { padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .status-pending { background: #fff2d9; color: #8f5a02; }
        .status-reviewed { background: #dff0ff; color: #1d4f96; }
        .status-resolved { background: #daf7e5; color: #1f7a43; }
        .feedback-item { border: 1px solid #dfe8f8; border-radius: 12px; padding: 0.85rem; margin-bottom: 0.75rem; background: linear-gradient(180deg, #ffffff 0%, #fafdff 100%); }
        .account-violations-wrap {
            margin-top: 0.8rem;
            border: 1px solid #e1e8f7;
            border-radius: 10px;
            overflow-x: auto;
            background: #fbfdff;
        }
        .account-violations-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }
        .account-violations-table th,
        .account-violations-table td {
            padding: 0.62rem 0.72rem;
            border-bottom: 1px solid #e7edf9;
            text-align: left;
            font-size: 0.88rem;
            color: #1d335e;
        }
        .account-violations-table th {
            background: #eef3ff;
            color: #123a7f;
            font-weight: 700;
        }
        .account-violations-table tbody tr:nth-child(even) {
            background: #fbfdff;
        }
        .account-violations-table tbody tr:hover {
            background: #f2f7ff;
        }
        .violations-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            flex-wrap: wrap;
            margin-bottom: 0.7rem;
            padding: 0.7rem 0.78rem;
            border: 1px solid #dfe8fa;
            border-radius: 10px;
            background: #f9fbff;
        }
        .violations-toolbar-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .violations-toolbar label {
            font-size: 0.8rem;
            color: #37527f;
            font-weight: 700;
        }
        .violations-toolbar input,
        .violations-toolbar select {
            border: 1px solid #ccd9f2;
            border-radius: 8px;
            padding: 0.42rem 0.52rem;
            font-size: 0.84rem;
            color: #1e3663;
            background: #ffffff;
        }
        .violations-toolbar input {
            min-width: 220px;
        }
        .violations-empty-note {
            display: none;
            margin: 0.5rem 0 0;
            color: #60749a;
            font-size: 0.88rem;
        }
        .status-tag {
            display: inline-block;
            padding: 0.2rem 0.52rem;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .status-tag.pending { background: #fff2d9; color: #8f5a02; }
        .status-tag.validated { background: #dff0ff; color: #1d4f96; }
        .status-tag.paid { background: #daf7e5; color: #1f7a43; }
        .status-tag.rejected { background: #ffdfe3; color: #9d2433; }
        @media (prefers-reduced-motion: reduce) {
            .motorist-banner,
            .analytics-card,
            .section-card {
                animation: none;
            }
            .analytics-card,
            .mini-card {
                transition: none;
            }
        }
        .feedback-meta { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.85rem; color: #4c5e87; margin-bottom: 0.45rem; }
        .feedback-message { color: #1f2f52; margin-bottom: 0.6rem; }
        .feedback-response { background: #f3f7ff; border-left: 3px solid #2e67c8; padding: 0.55rem 0.65rem; border-radius: 6px; color: #1c3e7a; }
        .empty-state { color: #6b7b9f; text-align: center; padding: 1.2rem 0.5rem; border: 1px dashed #d7e2f5; border-radius: 12px; background: #fbfdff; }
        .error-list { background: #f8d7da; color: #721c24; border-radius: 8px; padding: 0.75rem 0.9rem; margin-bottom: 0.9rem; }
        .success-box { background: #d4edda; color: #155724; border-radius: 8px; padding: 0.75rem 0.9rem; margin-bottom: 0.9rem; }
        .about-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 0.8rem; }
        html[data-theme="dark"] body {
            background: linear-gradient(180deg, #0f1624 0%, #121b2d 100%);
            color: #d8e4ff;
        }
        html[data-theme="dark"] .motorist-topnav {
            background: rgba(18, 27, 45, 0.88);
            border-bottom-color: #2c3b58;
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.28);
        }
        html[data-theme="dark"] .motorist-topnav h1,
        html[data-theme="dark"] .motorist-profile-name {
            color: #e4edff;
        }
        html[data-theme="dark"] .motorist-topnav-links a {
            color: #b9cae8;
        }
        html[data-theme="dark"] .motorist-topnav-links a:hover,
        html[data-theme="dark"] .motorist-topnav-links .active-nav {
            color: #f1e7ff;
            background: rgba(135, 93, 228, 0.22);
        }
        html[data-theme="dark"] .motorist-profile-toggle,
        html[data-theme="dark"] .motorist-menu-icon {
            background: #1b2740;
            color: #dbe7ff;
            border-color: #314561;
        }
        html[data-theme="dark"] .motorist-profile-popover,
        html[data-theme="dark"] .section-card,
        html[data-theme="dark"] .chart-card,
        html[data-theme="dark"] .analytics-card,
        html[data-theme="dark"] .feedback-card,
        html[data-theme="dark"] .mini-card,
        html[data-theme="dark"] .feedback-item {
            background: linear-gradient(180deg, #162235 0%, #1a2940 100%);
            border-color: #2d3f5d;
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.24);
        }
        html[data-theme="dark"] .analytics-card h3,
        html[data-theme="dark"] .mini-card p,
        html[data-theme="dark"] .feedback-help,
        html[data-theme="dark"] .supervisor-reports-subtitle,
        html[data-theme="dark"] .feedback-meta,
        html[data-theme="dark"] .feedback-message,
        html[data-theme="dark"] .no-chart-data,
        html[data-theme="dark"] .empty-state {
            color: #b5c6e4;
        }
        html[data-theme="dark"] .section-card h2,
        html[data-theme="dark"] .chart-card h3,
        html[data-theme="dark"] .analytics-card p,
        html[data-theme="dark"] .mini-card h4,
        html[data-theme="dark"] .feedback-card h2,
        html[data-theme="dark"] .feedback-response,
        html[data-theme="dark"] .supervisor-reports-title {
            color: #e4edff;
        }
        html[data-theme="dark"] .feedback-response {
            background: #1b3257;
            border-left-color: #4f82d9;
        }
        html[data-theme="dark"] .feedback-form input,
        html[data-theme="dark"] .feedback-form select,
        html[data-theme="dark"] .feedback-form textarea {
            background: #121d30;
            color: #deebff;
            border-color: #334966;
        }
        html[data-theme="dark"] .feedback-form input:focus,
        html[data-theme="dark"] .feedback-form select:focus,
        html[data-theme="dark"] .feedback-form textarea:focus {
            box-shadow: 0 0 0 3px rgba(130, 96, 224, 0.24);
            border-color: #7d5fd5;
        }
        html[data-theme="dark"] .account-violations-wrap,
        html[data-theme="dark"] .violations-wrap {
            background: #162235;
            border-color: #2d3f5d;
        }
        html[data-theme="dark"] .account-violations-table th,
        html[data-theme="dark"] .violations-table th {
            background: #1f2f49;
            color: #dce8ff;
            border-bottom-color: #334967;
        }
        html[data-theme="dark"] .account-violations-table td,
        html[data-theme="dark"] .violations-table td {
            color: #d5e2f8;
            border-bottom-color: #2f425f;
        }
        html[data-theme="dark"] .account-violations-table tbody tr:nth-child(even) {
            background: #192840;
        }
        html[data-theme="dark"] .account-violations-table tbody tr:hover {
            background: #22344f;
        }
        html[data-theme="dark"] .violations-toolbar {
            background: #16263d;
            border-color: #2f425f;
        }
        html[data-theme="dark"] .violations-toolbar label {
            color: #c2d5f1;
        }
        html[data-theme="dark"] .violations-toolbar input,
        html[data-theme="dark"] .violations-toolbar select {
            background: #121d30;
            border-color: #334966;
            color: #deebff;
        }
        html[data-theme="dark"] .violations-empty-note {
            color: #b8caea;
        }
        html[data-theme="dark"] .empty-state {
            background: #162235;
            border-color: #2d3f5d;
        }
        html[data-theme="dark"] .no-chart-data {
            background: #162235;
            border-color: #2d3f5d;
        }
        @media (max-width: 980px) {
            .motorist-topnav {
                flex-wrap: wrap;
                gap: 0.65rem;
                padding: 0.9rem 1rem;
            }
            .motorist-menu-icon { display: inline-flex; align-items: center; justify-content: center; }
            .motorist-profile-popover {
                top: 64px;
                right: 1rem;
                min-width: 200px;
            }
            .motorist-topnav-links {
                width: 100%;
                justify-content: flex-start;
                display: none;
                padding-top: 0.35rem;
                border-top: 1px solid #edf1f9;
            }
            .motorist-topnav-links.open { display: flex; }
            .analytics-grid { grid-template-columns: repeat(2, minmax(160px, 1fr)); }
            .charts-grid { grid-template-columns: 1fr; }
            .cards-grid { grid-template-columns: 1fr; }
            .feedback-layout, .about-grid { grid-template-columns: 1fr; }
            .motorist-page { padding: 0.8rem; }
            .motorist-banner { min-height: 46vh; padding: 2.2rem 1rem; border-radius: 16px; }
            .motorist-banner h2 { font-size: clamp(1.8rem, 7vw, 2.3rem); }
            .motorist-banner p { font-size: 0.98rem; line-height: 1.5; }
            .analytics-card { padding: 0.85rem 0.88rem; }
            .analytics-card h3 { font-size: 0.72rem; }
            .analytics-card p { font-size: 1.35rem; }
            .section-card,
            .chart-card,
            .feedback-card { border-radius: 12px; padding: 0.9rem; }
            .chart-card canvas { height: 170px !important; }
            .feedback-form input,
            .feedback-form select,
            .feedback-form textarea { font-size: 0.9rem; }
            .violations-toolbar input { min-width: 160px; }
        }
    </style>

</head>
<body>
    <nav class="motorist-topnav">
        <h1>MTMO</h1>
        <div class="motorist-topnav-links" id="motorist-topnav-links">
            <a href="#analytics">Dashboard</a>
            <a href="#feedback">Feedback</a>
            <a href="#about">About</a>
        </div>
        <?php
        $profile_name = trim((string)($_SESSION['full_name'] ?? 'Motorist'));
        $profile_initials = '';
        if ($profile_name !== '') {
            $parts = array_values(array_filter(explode(' ', $profile_name), fn($p) => trim((string)$p) !== ''));
            if (!empty($parts)) {
                $profile_initials .= strtoupper(substr($parts[0], 0, 1));
                if (count($parts) > 1) {
                    $profile_initials .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
                }
            }
        }
        if ($profile_initials === '') {
            $profile_initials = 'M';
        }
        $profile_photo = trim((string)($_SESSION['profile_photo'] ?? ''));
        ?>
        <button type="button" class="motorist-profile-toggle" id="motorist-profile-toggle" aria-label="Toggle profile">
            <?php if ($profile_photo !== ''): ?>
                <img src="../uploads/<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo" class="motorist-avatar-toggle">
            <?php else: ?>
                <span class="motorist-avatar-toggle default-avatar" aria-hidden="true"></span>
            <?php endif; ?>
        </button>
        <button type="button" class="motorist-menu-icon" id="motorist-menu-toggle" aria-label="Toggle navigation">☰</button>
        <div class="motorist-profile-popover" id="motorist-profile-popover">
            <div class="motorist-profile-head">
                <?php if ($profile_photo !== ''): ?>
                    <img src="../uploads/<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo" class="motorist-avatar">
                <?php else: ?>
                    <span class="motorist-avatar default-avatar" aria-hidden="true"></span>
                <?php endif; ?>
                <div class="motorist-profile-meta">
                    <span class="motorist-profile-name"><?php echo htmlspecialchars($profile_name !== '' ? $profile_name : 'Motorist User'); ?></span>
                </div>
            </div>
            <a class="motorist-profile-logout" href="../logout.php">Logout</a>
        </div>
    </nav>

    <div class="motorist-page">
        <section class="motorist-banner">
            <h2>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Motorist'); ?>!</h2>
            <p>Monitor your case status, view public updates, and send feedback to the traffic office in one place.</p>
        </section>

        <section class="analytics-grid" id="analytics">
            <article class="analytics-card"><h3>Total Violations</h3><p><?php echo number_format($total_violations); ?></p></article>
            <article class="analytics-card"><h3>Unpaid Violations</h3><p><?php echo number_format($unpaid_violations); ?></p></article>
            <article class="analytics-card"><h3>Submitted Concerns</h3><p><?php echo number_format($total_feedback); ?></p></article>
            <article class="analytics-card"><h3>Resolved Concerns</h3><p><?php echo number_format($resolved_feedback); ?></p></article>
        </section>

        <section class="section-card" id="my-violations">
            <h2>My Violation Records</h2>
            <div class="violations-toolbar">
                <div class="violations-toolbar-group">
                    <label for="dashboard-violation-search">Search</label>
                    <input type="search" id="dashboard-violation-search" placeholder="TOP number, violation, location">
                </div>
                <div class="violations-toolbar-group">
                    <label for="dashboard-violation-status">Status</label>
                    <select id="dashboard-violation-status">
                        <option value="all">All</option>
                        <option value="pending">Pending</option>
                        <option value="validated">Validated</option>
                        <option value="paid">Paid</option>
                        <option value="rejected">Rejected</option>
                        <option value="unpaid">Unpaid</option>
                    </select>
                </div>
            </div>
            <div class="account-violations-wrap">
                <table class="account-violations-table">
                    <thead>
                        <tr>
                            <th>TOP Number</th>
                            <th>Date</th>
                            <th>Violation</th>
                            <th>Location</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($my_violations)): ?>
                            <tr><td colspan="6">No violation records found for your account.</td></tr>
                        <?php else: ?>
                            <?php foreach ($my_violations as $violation): ?>
                                <?php $row_status = strtolower((string)($violation['status'] ?? 'pending')); ?>
                                <tr data-status="<?php echo htmlspecialchars($row_status); ?>">
                                    <td data-col="top"><?php echo htmlspecialchars((string)($violation['top_number'] ?: 'N/A')); ?></td>
                                    <td><?php echo !empty($violation['violation_date']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($violation['violation_date']))) : 'N/A'; ?></td>
                                    <td data-col="violation"><?php echo htmlspecialchars((string)($violation['violation_display'] ?? 'N/A')); ?></td>
                                    <td data-col="location"><?php echo htmlspecialchars((string)($violation['location'] ?? 'N/A')); ?></td>
                                    <td>₱<?php echo number_format((float)($violation['fine_amount'] ?? 0), 2); ?></td>
                                    <td><span class="status-tag <?php echo htmlspecialchars($row_status); ?>"><?php echo htmlspecialchars(ucfirst($row_status)); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="violations-empty-note" id="dashboard-violations-empty">No violations match your current filter.</p>
        </section>

        <section class="section-card">
            <h2 class="supervisor-reports-title">Graph Reports Transparency</h2>
            <p class="supervisor-reports-subtitle">Stay informed by monitoring recent violation trends and analytics.</p>
            <div class="charts-grid">
                <article class="chart-card">
                    <h3>Violation Trends (Last 7 Days)</h3>
                    <canvas id="supervisorTrendChart"></canvas>
                </article>
                <article class="chart-card">
                    <h3>Monthly Violation Trend</h3>
                    <canvas id="supervisorMonthlyChart"></canvas>
                </article>
                <article class="chart-card">
                    <h3>Penalty Collection Status</h3>
                    <canvas id="supervisorCollectionChart"></canvas>
                </article>
                <article class="chart-card">
                    <h3>Top Violation Locations</h3>
                    <canvas id="supervisorLocationsChart"></canvas>
                </article>
            </div>
        </section>

        <section class="section-card" id="announcements">
            <h2>Announcements</h2>
            <div class="cards-grid">
                <?php if (empty($welcome_announcements)): ?>
                    <div class="mini-card"><p>No announcements available at the moment.</p></div>
                <?php else: ?>
                    <?php foreach ($welcome_announcements as $announcement): ?>
                        <article class="mini-card">
                            <h4><?php echo htmlspecialchars($announcement['title']); ?></h4>
                            <p><?php echo date('M d, Y', strtotime($announcement['posted_at'])); ?></p>
                            <?php if (!empty($announcement['image_path'])): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($announcement['image_path']); ?>" alt="Announcement image">
                            <?php endif; ?>
                            <p><?php echo htmlspecialchars($announcement['content']); ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="section-card" id="articles">
            <h2>Articles</h2>
            <div class="cards-grid">
                <?php if (empty($welcome_articles)): ?>
                    <div class="mini-card"><p>No articles available at the moment.</p></div>
                <?php else: ?>
                    <?php foreach ($welcome_articles as $article): ?>
                        <article class="mini-card">
                            <h4><?php echo htmlspecialchars($article['title']); ?></h4>
                            <p>Published on <?php echo date('M d, Y', strtotime($article['published_at'])); ?></p>
                            <p>
                                <?php
                                $preview = strlen($article['content']) > 210 ? substr($article['content'], 0, 210) . '...' : $article['content'];
                                echo nl2br(htmlspecialchars($preview));
                                ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="section-card" id="videos">
            <h2>Tutorial Videos</h2>
            <div class="cards-grid">
                <?php if (empty($welcome_tutorial_videos)): ?>
                    <div class="mini-card"><p>No tutorial videos available at the moment.</p></div>
                <?php else: ?>
                    <?php foreach ($welcome_tutorial_videos as $video): ?>
                        <article class="mini-card">
                            <h4><?php echo htmlspecialchars($video['title']); ?></h4>
                            <p><?php echo htmlspecialchars($video['description']); ?></p>
                            <?php if (!empty($video['file_path'])): ?>
                                <video controls preload="metadata" style="width:100%; border-radius:8px; margin-top:0.55rem;">
                                    <source src="../uploads/<?php echo htmlspecialchars($video['file_path']); ?>">
                                </video>
                            <?php elseif (!empty($video['url'])): ?>
                                <p><a href="<?php echo htmlspecialchars($video['url']); ?>" target="_blank" rel="noopener noreferrer">Watch tutorial</a></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="section-card" id="feedback">
            <h2>Feedback Form (Reklamo / Concern)</h2>
            <p class="feedback-help">Submit concerns about recorded traffic violations. Your submission goes directly to the Supervisor Portal.</p>

            <div class="feedback-layout">
                <section class="feedback-card">
                    <?php if (!empty($errors)): ?>
                        <div class="error-list">
                            <?php foreach ($errors as $error): ?>
                                <div>- <?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($success !== ''): ?>
                        <div class="success-box"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <form method="POST" class="feedback-form">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="reference_number">Violation Reference Number / Ticket ID</label>
                            <input type="text" id="reference_number" name="reference_number" value="<?php echo htmlspecialchars($reference_number); ?>" placeholder="Example: TOP-2026-0001 or ticket ID" required>
                        </div>
                        <div class="form-group">
                            <label for="contact_info">Contact Information</label>
                            <input type="text" id="contact_info" name="contact_info" value="<?php echo htmlspecialchars($contact_info); ?>" placeholder="Mobile number or email address" required>
                        </div>
                        <div class="form-group">
                            <label for="concern_type">Type of Concern</label>
                            <select id="concern_type" name="concern_type" required>
                                <option value="Dispute" <?php echo $concern_type === 'Dispute' ? 'selected' : ''; ?>>Dispute</option>
                                <option value="Inquiry" <?php echo $concern_type === 'Inquiry' ? 'selected' : ''; ?>>Inquiry</option>
                                <option value="Complaint" <?php echo $concern_type === 'Complaint' ? 'selected' : ''; ?>>Complaint</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="message">Message / Description</label>
                            <textarea id="message" name="message" maxlength="2500" required><?php echo htmlspecialchars($message); ?></textarea>
                        </div>
                        <button type="submit" class="btn-submit">Submit Feedback</button>
                    </form>
                </section>

                <section class="feedback-card">
                    <h2>My Submitted Concerns</h2>
                    <?php if (empty($my_feedback)): ?>
                        <div class="empty-state">No submissions yet.</div>
                    <?php else: ?>
                        <?php foreach ($my_feedback as $item): ?>
                            <?php
                                $status_class = 'status-pending';
                                if ($item['status'] === 'Reviewed') {
                                    $status_class = 'status-reviewed';
                                } elseif ($item['status'] === 'Resolved') {
                                    $status_class = 'status-resolved';
                                }
                            ?>
                            <article class="feedback-item">
                                <div class="feedback-meta">
                                    <strong>Ref: <?php echo htmlspecialchars($item['reference_number']); ?></strong>
                                    <span class="status-chip <?php echo $status_class; ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                                </div>
                                <div class="feedback-meta">
                                    <span><?php echo htmlspecialchars($item['concern_type']); ?></span>
                                    <span><?php echo date('M d, Y h:i A', strtotime($item['created_at'])); ?></span>
                                </div>
                                <div class="feedback-message"><?php echo nl2br(htmlspecialchars($item['message'])); ?></div>
                                <?php if (!empty($item['supervisor_response'])): ?>
                                    <div class="feedback-response">
                                        <strong>Supervisor Response:</strong><br>
                                        <?php echo nl2br(htmlspecialchars($item['supervisor_response'])); ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </div>
        </section>

        <section class="section-card" id="about">
            <h2>About</h2>
            <div class="about-grid">
                <div class="mini-card">
                    <h4>About This Portal</h4>
                    <p>This dashboard helps motorists monitor traffic records, stay informed through official updates, and submit concerns to the Municipal Traffic Management Office.</p>
                </div>
                <div class="mini-card">
                    <h4>Contact Information</h4>
                    <p><?php echo htmlspecialchars($office_contact['office_name']); ?></p>
                    <p><?php echo htmlspecialchars($office_contact['address']); ?></p>
                    <p><?php echo htmlspecialchars($office_contact['phone']); ?></p>
                    <p><?php echo htmlspecialchars($office_contact['email']); ?></p>
                </div>
            </div>
        </section>

    </div>
</body>
<script>
    (function () {
        const menuToggle = document.getElementById('motorist-menu-toggle');
        const navLinks = document.getElementById('motorist-topnav-links');
        if (!menuToggle || !navLinks) return;
        menuToggle.addEventListener('click', function () {
            navLinks.classList.toggle('open');
        });
    })();

    (function () {
        const profileToggle = document.getElementById('motorist-profile-toggle');
        const profilePopover = document.getElementById('motorist-profile-popover');
        if (!profileToggle || !profilePopover) return;

        profileToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            profilePopover.classList.toggle('open');
        });

        profilePopover.addEventListener('click', function () {
            window.location.href = 'account.php';
        });

        document.addEventListener('click', function (event) {
            if (!profilePopover.contains(event.target) && !profileToggle.contains(event.target)) {
                profilePopover.classList.remove('open');
            }
        });
    })();

    (function () {
        const nav = document.getElementById('motorist-topnav-links');
        if (!nav) return;
        const links = Array.from(nav.querySelectorAll('a[href^="#"]'));
        if (!links.length) return;

        const sections = links
            .map((link) => {
                const id = link.getAttribute('href') || '';
                const target = id.startsWith('#') ? document.querySelector(id) : null;
                return target ? { link, target } : null;
            })
            .filter(Boolean);

        const setActive = (activeLink) => {
            links.forEach((link) => link.classList.remove('active-nav'));
            if (activeLink) activeLink.classList.add('active-nav');
        };

        links.forEach((link) => {
            link.addEventListener('click', function () {
                setActive(link);
            });
        });

        const updateByScroll = () => {
            const marker = window.scrollY + 140;
            let current = sections[0];
            sections.forEach((item) => {
                if (item.target.offsetTop <= marker) {
                    current = item;
                }
            });
            if (current) setActive(current.link);
        };

        updateByScroll();
        window.addEventListener('scroll', updateByScroll, { passive: true });
    })();

    (function () {
        const searchInput = document.getElementById('dashboard-violation-search');
        const statusFilter = document.getElementById('dashboard-violation-status');
        const rows = Array.from(document.querySelectorAll('.account-violations-table tbody tr[data-status]'));
        const emptyNote = document.getElementById('dashboard-violations-empty');
        if (!rows.length) return;

        const applyFilters = function () {
            const search = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
            const selectedStatus = statusFilter && statusFilter.value ? statusFilter.value : 'all';
            let visible = 0;
            rows.forEach(function (row) {
                const rowStatus = String(row.getAttribute('data-status') || '').toLowerCase();
                const topCell = row.querySelector('[data-col="top"]');
                const violationCell = row.querySelector('[data-col="violation"]');
                const locationCell = row.querySelector('[data-col="location"]');
                const text = [
                    topCell ? topCell.textContent : '',
                    violationCell ? violationCell.textContent : '',
                    locationCell ? locationCell.textContent : ''
                ].join(' ').toLowerCase();
                const statusMatches = selectedStatus === 'all' || rowStatus === selectedStatus;
                const searchMatches = search === '' || text.includes(search);
                const show = statusMatches && searchMatches;
                row.style.display = show ? '' : 'none';
                if (show) visible += 1;
            });
            if (emptyNote) {
                emptyNote.style.display = visible === 0 ? 'block' : 'none';
            }
        };

        if (searchInput) searchInput.addEventListener('input', applyFilters);
        if (statusFilter) statusFilter.addEventListener('change', applyFilters);
        applyFilters();
    })();

    (function () {
        if (typeof Chart === 'undefined') return;

        const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
        const sharedLegend = {
            labels: {
                color: isDarkTheme ? '#d6e4ff' : '#384c73',
                boxWidth: 12,
                usePointStyle: true,
                pointStyle: 'circle'
            }
        };

        const axisColor = isDarkTheme ? '#c8d8f3' : '#4f648f';
        const gridColor = isDarkTheme ? 'rgba(167, 194, 238, 0.24)' : 'rgba(33, 77, 157, 0.12)';
        const tooltipBg = isDarkTheme ? 'rgba(14, 22, 36, 0.92)' : 'rgba(17, 42, 86, 0.9)';
        const tooltipText = '#f3f8ff';
        const lineStroke = isDarkTheme ? '#6fb1ff' : '#2e67c8';
        const lineFill = isDarkTheme ? 'rgba(111, 177, 255, 0.2)' : 'rgba(46, 103, 200, 0.12)';
        const barFill = isDarkTheme ? 'rgba(90, 166, 255, 0.82)' : 'rgba(15, 86, 195, 0.75)';
        const barStroke = isDarkTheme ? '#7ab8ff' : '#0f56c3';
        const locationFill = isDarkTheme ? 'rgba(104, 178, 255, 0.82)' : 'rgba(38, 123, 255, 0.75)';
        const locationStroke = isDarkTheme ? '#84c2ff' : '#1f63cf';
        const trendLabels = <?php echo json_encode($trend_labels); ?>;
        const trendData = <?php echo json_encode($trend_data); ?>;
        const monthlyLabels = <?php echo json_encode($monthly_trend_labels); ?>;
        const monthlyData = <?php echo json_encode($monthly_trend_data); ?>;
        const collectionData = [<?php echo (int)$paid_count; ?>, <?php echo (int)$unpaid_count; ?>];
        const locationLabels = <?php echo json_encode($top_location_labels); ?>;
        const locationData = <?php echo json_encode($top_location_counts); ?>;

        const hasAnyValue = (arr) => Array.isArray(arr) && arr.some((v) => Number(v) > 0);
        const hasLabelsAndValues = (labels, values) => Array.isArray(labels) && labels.length > 0 && hasAnyValue(values);
        const showNoData = (canvasId) => {
            const canvas = document.getElementById(canvasId);
            if (!canvas || !canvas.parentElement) return;
            canvas.style.display = 'none';
            const placeholder = document.createElement('div');
            placeholder.className = 'no-chart-data';
            placeholder.textContent = 'No report data available yet.';
            canvas.parentElement.appendChild(placeholder);
        };
        const safeChart = (canvasId, ready, config) => {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            if (!ready) {
                showNoData(canvasId);
                return;
            }
            new Chart(canvas, config);
        };
        const cartesianScales = {
            y: {
                beginAtZero: true,
                ticks: { color: axisColor, precision: 0 },
                grid: { color: gridColor }
            },
            x: {
                ticks: { color: axisColor },
                grid: { display: false }
            }
        };
        const sharedPlugins = {
            tooltip: {
                backgroundColor: tooltipBg,
                titleColor: tooltipText,
                bodyColor: tooltipText
            }
        };

        safeChart('supervisorTrendChart', hasLabelsAndValues(trendLabels, trendData), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Incidents',
                    data: trendData,
                    borderColor: lineStroke,
                    backgroundColor: lineFill,
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3,
                    pointRadius: 3
                }]
            },
            options: {
                plugins: { ...sharedPlugins, legend: { display: false } },
                scales: cartesianScales
            }
        });

        safeChart('supervisorMonthlyChart', hasLabelsAndValues(monthlyLabels, monthlyData), {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Monthly Incidents',
                    data: monthlyData,
                    backgroundColor: barFill,
                    borderColor: barStroke,
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                plugins: { ...sharedPlugins, legend: { display: false } },
                scales: cartesianScales
            }
        });

        safeChart('supervisorCollectionChart', hasAnyValue(collectionData), {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Unpaid'],
                datasets: [{
                    data: collectionData,
                    backgroundColor: ['#1ea672', '#db5b5b'],
                    borderColor: ['#1a8f62', '#bf4a4a'],
                    borderWidth: 1.5
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { ...sharedPlugins, legend: { ...sharedLegend, position: 'bottom' } },
                cutout: '62%'
            }
        });

        safeChart('supervisorLocationsChart', hasLabelsAndValues(locationLabels, locationData), {
            type: 'bar',
            data: {
                labels: locationLabels,
                datasets: [{
                    label: 'Violations',
                    data: locationData,
                    backgroundColor: locationFill,
                    borderColor: locationStroke,
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                plugins: { ...sharedPlugins, legend: { display: false } },
                scales: cartesianScales
            }
        });

    })();
</script>
</html>
