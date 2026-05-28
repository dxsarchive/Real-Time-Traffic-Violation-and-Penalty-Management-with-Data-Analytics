<?php
require_once '../auth.php';
check_role('supervisor');

global $pdo;
$conn = $pdo;
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

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

$notice = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_feedback'])) {
    $feedback_id = (int)($_POST['feedback_id'] ?? 0);
    $status = trim($_POST['status'] ?? 'Pending');
    $response = trim($_POST['supervisor_response'] ?? '');
    $allowed_status = ['Pending', 'Reviewed', 'Resolved'];

    if ($feedback_id <= 0) {
        $errors[] = 'Invalid feedback record selected.';
    }
    if (!in_array($status, $allowed_status, true)) {
        $errors[] = 'Invalid status selected.';
    }
    if (strlen($response) > 3000) {
        $errors[] = 'Response is too long. Please limit to 3000 characters.';
    }

    if (empty($errors)) {
        $update_stmt = $conn->prepare("UPDATE feedback_concerns
                                       SET status = ?, supervisor_response = ?, supervisor_id = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                                       WHERE id = ?");
        $update_stmt->execute([$status, $response, (int)$_SESSION['user_id'], $feedback_id]);
        $notice = 'Feedback concern has been updated successfully.';
    }
}

$filter_status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$where = [];
$params = [];

if ($filter_status !== 'all' && in_array($filter_status, ['Pending', 'Reviewed', 'Resolved'], true)) {
    $where[] = "fc.status = ?";
    $params[] = $filter_status;
}

if ($search !== '') {
    $where[] = "(fc.full_name LIKE ? OR fc.reference_number LIKE ? OR fc.message LIKE ?)";
    $search_like = '%' . $search . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

$date_pattern = '/^\d{4}-\d{2}-\d{2}$/';
$start_valid = $start_date === '' || (preg_match($date_pattern, $start_date) && strtotime($start_date) !== false);
$end_valid = $end_date === '' || (preg_match($date_pattern, $end_date) && strtotime($end_date) !== false);

if (!$start_valid) {
    $errors[] = 'Invalid start date format. Use YYYY-MM-DD.';
}
if (!$end_valid) {
    $errors[] = 'Invalid end date format. Use YYYY-MM-DD.';
}

if ($start_valid && $start_date !== '') {
    $where[] = "DATE(fc.created_at) >= ?";
    $params[] = $start_date;
}
if ($end_valid && $end_date !== '') {
    $where[] = "DATE(fc.created_at) <= ?";
    $params[] = $end_date;
}
if ($start_valid && $end_valid && $start_date !== '' && $end_date !== '' && $start_date > $end_date) {
    $errors[] = 'Start date cannot be later than end date.';
}

$where_clause = '';
if (!empty($where)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && empty($errors)) {
    $export_stmt = $conn->prepare("SELECT fc.id, fc.full_name, fc.reference_number, fc.contact_info, fc.concern_type, fc.message,
                                          fc.status, fc.supervisor_response, fc.created_at, fc.reviewed_at, u.full_name AS supervisor_name
                                   FROM feedback_concerns fc
                                   LEFT JOIN users u ON u.id = fc.supervisor_id
                                   $where_clause
                                   ORDER BY fc.created_at DESC");
    $export_stmt->execute($params);
    $rows = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="feedback_complaints_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Full Name', 'Reference Number', 'Contact Info', 'Concern Type', 'Message', 'Status', 'Supervisor Response', 'Supervisor', 'Submitted At', 'Reviewed At']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['full_name'],
            $row['reference_number'],
            $row['contact_info'],
            $row['concern_type'],
            $row['message'],
            $row['status'],
            $row['supervisor_response'],
            $row['supervisor_name'],
            $row['created_at'],
            $row['reviewed_at']
        ]);
    }
    fclose($output);
    exit;
}

$feedback_stmt = $conn->prepare("SELECT fc.*, u.full_name AS supervisor_name
                                 FROM feedback_concerns fc
                                 LEFT JOIN users u ON u.id = fc.supervisor_id
                                 $where_clause
                                 ORDER BY fc.created_at DESC");
$feedback_stmt->execute($params);
$feedback_items = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

$status_counts_raw = $conn->query("SELECT status, COUNT(*) AS cnt FROM feedback_concerns GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$status_counts = ['Pending' => 0, 'Reviewed' => 0, 'Resolved' => 0];
foreach ($status_counts_raw as $row) {
    $status_key = $row['status'] ?? '';
    if (isset($status_counts[$status_key])) {
        $status_counts[$status_key] = (int)$row['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Feedback Management</title>
    <link rel="stylesheet" href="../style.css?v=20260426">
    <script src="../theme.js" defer></script>
    <style>
        .feedback-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.9rem; margin-bottom: 1rem; }
                .feedback-stat-card { background: #fff; border: 1px solid #dbe6fa; border-radius: 10px; padding: 0.9rem; box-shadow: 0 4px 12px rgba(17, 47, 105, 0.06); }
                .feedback-stat-card h3 { margin: 0 0 0.35rem; color: #334f84; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; }
                .feedback-stat-card .value { font-size: 1.85rem; font-weight: 800; color: #0d2f6f; }
                .feedback-controls { background: #fff; border: 1px solid #dbe6fa; border-radius: 10px; padding: 0.9rem; margin-bottom: 1rem; display: flex; gap: 0.6rem; flex-wrap: wrap; }
                .feedback-controls input, .feedback-controls select { border: 1px solid #cfdcf6; border-radius: 8px; padding: 0.58rem 0.68rem; min-width: 180px; }
                .feedback-controls label { font-size: 0.78rem; color: #4b608c; font-weight: 700; display: flex; flex-direction: column; gap: 0.3rem; }
                .feedback-list { display: grid; gap: 0.8rem; }
                .feedback-item { background: #fff; border: 1px solid #dbe6fa; border-radius: 12px; box-shadow: 0 4px 12px rgba(17, 47, 105, 0.06); overflow: hidden; }
                .feedback-header { padding: 0.8rem 1rem; border-bottom: 1px solid #e5edfb; display: flex; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; }
                .feedback-body { padding: 0.9rem 1rem 1rem; }
                .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.55rem; margin-bottom: 0.75rem; }
                .meta-key { font-size: 0.76rem; color: #5a6d95; text-transform: uppercase; letter-spacing: 0.06em; }
                .meta-value { color: #1f2f52; font-weight: 600; }
                .message-box { background: #f8fbff; border: 1px solid #dbe9fb; border-radius: 8px; padding: 0.7rem; margin-bottom: 0.75rem; }
                .status-chip { padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
                .status-pending { background: #fff2d9; color: #8f5a02; }
                .status-reviewed { background: #dff0ff; color: #1d4f96; }
                .status-resolved { background: #daf7e5; color: #1f7a43; }
                .response-form textarea { width: 100%; border: 1px solid #cfdcf6; border-radius: 8px; padding: 0.6rem 0.7rem; min-height: 82px; margin-bottom: 0.55rem; }
                .response-row { display: flex; gap: 0.55rem; flex-wrap: wrap; }
                .response-row select { border: 1px solid #cfdcf6; border-radius: 8px; padding: 0.55rem 0.65rem; }
                .response-row button, .feedback-controls button, .feedback-controls a {
                    border: none; border-radius: 8px; padding: 0.4rem 0.7rem; text-decoration: none; font-weight: 700; cursor: pointer; font-size: 0.82rem; line-height: 1.1;
                }
                .btn-primary { background: #0f56c3; color: #fff; }
                .btn-secondary { background: #e8eefb; color: #22457f; }
                .feedback-controls .btn-primary {
                    padding: 0.72rem 1.3rem;
                    font-size: 0.98rem;
                    border-radius: 12px;
                    line-height: 1.1;
                    font-weight: 700;
                    min-width: 170px;
                    text-align: center;
                }
                .notice-ok { background: #d4edda; color: #155724; border-radius: 8px; padding: 0.75rem 0.9rem; margin-bottom: 0.8rem; }
                .notice-err { background: #f8d7da; color: #721c24; border-radius: 8px; padding: 0.75rem 0.9rem; margin-bottom: 0.8rem; }
                .active-filters { background: #eef4ff; border: 1px solid #d4e1fb; color: #24477f; border-radius: 10px; padding: 0.7rem 0.85rem; margin-bottom: 0.8rem; font-size: 0.88rem; }
                .active-filters strong { color: #17396f; }
                .empty-state { background: #fff; border: 1px dashed #cfdcf6; border-radius: 10px; color: #5f7198; padding: 1rem; text-align: center; }
                html[data-theme="dark"] .active-filters {
                    background: #162235;
                    border-color: #2b3a52;
                    color: #e6edfb;
                }
                html[data-theme="dark"] .active-filters strong {
                    color: #f3f7ff;
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
<?php $supervisor_sidebar_active = 'feedback'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
        <main class="main-content">
            <header>
                <h1>Motorist Feedback and Complaints</h1>
                <div class="user-info"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
            </header>

            <?php if ($notice !== ''): ?>
                <div class="notice-ok"><?php echo htmlspecialchars($notice); ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="notice-err">
                    <?php foreach ($errors as $error): ?>
                        <div>- <?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="feedback-stats">
                <article class="feedback-stat-card">
                    <h3>Pending</h3>
                    <div class="value"><?php echo number_format($status_counts['Pending']); ?></div>
                </article>
                <article class="feedback-stat-card">
                    <h3>Reviewed</h3>
                    <div class="value"><?php echo number_format($status_counts['Reviewed']); ?></div>
                </article>
                <article class="feedback-stat-card">
                    <h3>Resolved</h3>
                    <div class="value"><?php echo number_format($status_counts['Resolved']); ?></div>
                </article>
            </section>

            <form method="GET" class="feedback-controls">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, ref, or message">
                <select name="status">
                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Reviewed" <?php echo $filter_status === 'Reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                    <option value="Resolved" <?php echo $filter_status === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                </select>
                <label>
                    From Date
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </label>
                <label>
                    To Date
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </label>
                <button type="submit" class="btn-primary">Filter</button>
                <button type="submit" name="export" value="csv" class="btn-primary">Export CSV</button>
                <a href="feedback.php" class="btn-secondary">Reset</a>
            </form>

            <?php
                $active_parts = [];
                if ($filter_status !== 'all') {
                    $active_parts[] = 'Status: ' . $filter_status;
                }
                if ($search !== '') {
                    $active_parts[] = 'Search: "' . $search . '"';
                }
                if ($start_date !== '' && $end_date !== '') {
                    $active_parts[] = 'Date: ' . $start_date . ' to ' . $end_date;
                } elseif ($start_date !== '') {
                    $active_parts[] = 'From: ' . $start_date;
                } elseif ($end_date !== '') {
                    $active_parts[] = 'Until: ' . $end_date;
                }
                if (empty($active_parts)) {
                    $active_filters_text = 'All complaints (no filters applied)';
                } else {
                    $active_filters_text = implode(' | ', $active_parts);
                }
            ?>
            <div class="active-filters">
                <strong>Active Filters:</strong> <?php echo htmlspecialchars($active_filters_text); ?>
            </div>

            <section class="feedback-list">
                <?php if (empty($feedback_items)): ?>
                    <div class="empty-state">No feedback concerns match your current filter.</div>
                <?php else: ?>
                    <?php foreach ($feedback_items as $item): ?>
                        <?php
                            $status_class = 'status-pending';
                            if ($item['status'] === 'Reviewed') {
                                $status_class = 'status-reviewed';
                            } elseif ($item['status'] === 'Resolved') {
                                $status_class = 'status-resolved';
                            }
                        ?>
                        <article class="feedback-item">
                            <div class="feedback-header">
                                <strong><?php echo htmlspecialchars($item['full_name']); ?></strong>
                                <span class="status-chip <?php echo $status_class; ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                            </div>
                            <div class="feedback-body">
                                <div class="meta-grid">
                                    <div>
                                        <div class="meta-key">Reference #</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($item['reference_number']); ?></div>
                                    </div>
                                    <div>
                                        <div class="meta-key">Contact</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($item['contact_info']); ?></div>
                                    </div>
                                    <div>
                                        <div class="meta-key">Concern Type</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($item['concern_type']); ?></div>
                                    </div>
                                    <div>
                                        <div class="meta-key">Submitted</div>
                                        <div class="meta-value"><?php echo date('M d, Y h:i A', strtotime($item['created_at'])); ?></div>
                                    </div>
                                </div>

                                <div class="message-box"><?php echo nl2br(htmlspecialchars($item['message'])); ?></div>

                                <form method="POST" class="response-form">
                                    <input type="hidden" name="feedback_id" value="<?php echo (int)$item['id']; ?>">
                                    <textarea name="supervisor_response" maxlength="3000" placeholder="Write your response to the motorist..."><?php echo htmlspecialchars($item['supervisor_response'] ?? ''); ?></textarea>
                                    <div class="response-row">
                                        <select name="status">
                                            <option value="Pending" <?php echo $item['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="Reviewed" <?php echo $item['status'] === 'Reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                            <option value="Resolved" <?php echo $item['status'] === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        </select>
                                        <button type="submit" name="update_feedback" value="1" class="btn-primary">Update Concern</button>
                                    </div>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
