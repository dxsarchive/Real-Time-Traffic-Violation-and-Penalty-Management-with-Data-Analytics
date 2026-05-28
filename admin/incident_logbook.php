<?php
require_once '../auth.php';
check_role('admin');

$conn = get_db_connection();
$message = '';
$error = '';
$filter_status = trim((string)($_GET['status'] ?? 'all'));
$filter_severity = trim((string)($_GET['severity'] ?? 'all'));
$search_title = trim((string)($_GET['q'] ?? ''));
$allowed_status_filter = ['all', 'open', 'in_progress', 'resolved'];
$allowed_severity_filter = ['all', 'low', 'medium', 'high', 'critical'];
if (!in_array($filter_status, $allowed_status_filter, true)) {
    $filter_status = 'all';
}
if (!in_array($filter_severity, $allowed_severity_filter, true)) {
    $filter_severity = 'all';
}

try {
    $driver = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $conn->exec("CREATE TABLE IF NOT EXISTS incident_logbook (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'medium',
            details TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            reported_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    } else {
        $conn->exec("CREATE TABLE IF NOT EXISTS incident_logbook (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            severity TEXT NOT NULL DEFAULT 'medium',
            details TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            reported_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if ($action === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $severity = trim((string)($_POST['severity'] ?? 'medium'));
            $details = trim((string)($_POST['details'] ?? ''));
            if ($title === '' || $details === '') {
                throw new RuntimeException('Title and details are required.');
            }
            if (strlen($title) > 180) {
                throw new RuntimeException('Title must be 180 characters or less.');
            }
            if (strlen($details) > 5000) {
                throw new RuntimeException('Details must be 5000 characters or less.');
            }
            if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
                $severity = 'medium';
            }
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO incident_logbook (title, severity, details, status, reported_by) VALUES (?, ?, ?, 'open', ?)");
            $stmt->execute([$title, $severity, $details, (int)($_SESSION['user_id'] ?? 0)]);
            $new_id = (int)$conn->lastInsertId();
            write_audit_event((int)($_SESSION['user_id'] ?? 0), 'incident_create', 'incident_logbook', $new_id, "Created incident: {$title}");
            $conn->commit();
            $message = 'Incident logged successfully.';
        } elseif ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'open'));
            if ($id <= 0 || !in_array($status, ['open', 'in_progress', 'resolved'], true)) {
                throw new RuntimeException('Invalid incident update request.');
            }
            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE incident_logbook SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$status, $id]);
            write_audit_event((int)($_SESSION['user_id'] ?? 0), 'incident_status_update', 'incident_logbook', $id, "Incident status changed to {$status}");
            $conn->commit();
            $message = 'Incident status updated.';
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        app_log('warning', 'admin.incident_logbook.action', 'Incident logbook action failed.', $e->getMessage());
        $error = $e->getMessage();
    }
}

$incidents = [];
try {
    $where = [];
    $params = [];
    if ($filter_status !== 'all') {
        $where[] = "status = :status";
        $params[':status'] = $filter_status;
    }
    if ($filter_severity !== 'all') {
        $where[] = "severity = :severity";
        $params[':severity'] = $filter_severity;
    }
    if ($search_title !== '') {
        $where[] = "title LIKE :q";
        $params[':q'] = '%' . $search_title . '%';
    }
    $sql = "SELECT id, title, severity, details, status, created_at, updated_at FROM incident_logbook";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY id DESC LIMIT 150";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    app_log('error', 'admin.incident_logbook.list', 'Failed to load incident list.', $e->getMessage());
    $incidents = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Logbook - Admin</title>
    <link rel="stylesheet" href="../style.css?v=20260429">
    <script src="../theme.js" defer></script>
    <style>
        .inline-status-form {
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .inline-status-form select {
            min-width: 120px;
        }
        .incident-filter-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr 0.8fr auto auto;
            gap: 0.55rem;
            align-items: end;
            margin-bottom: 0.9rem;
        }
        .incident-filter-grid .form-group {
            margin: 0;
        }
        .badge-pill {
            display: inline-block;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.76rem;
            font-weight: 700;
            border: 1px solid transparent;
            text-transform: capitalize;
        }
        .sev-low { background: #ecfff3; color: #27603f; border-color: #c9f1d8; }
        .sev-medium { background: #fff8e7; color: #7d5c12; border-color: #f6e5b4; }
        .sev-high { background: #ffeaea; color: #8f2323; border-color: #f5c8c8; }
        .sev-critical { background: #fce9f8; color: #922a79; border-color: #f7cdea; }
        .st-open { background: #e8f1ff; color: #264c93; border-color: #cfe1ff; }
        .st-in_progress { background: #fff8e7; color: #7d5c12; border-color: #f6e5b4; }
        .st-resolved { background: #ecfff3; color: #27603f; border-color: #c9f1d8; }
        .empty-incidents {
            padding: 16px;
            border: 1px dashed #d6e3f8;
            border-radius: 10px;
            background: #f8fbff;
            color: #4e6287;
            text-align: center;
        }
        .empty-incidents strong {
            color: #1d3f73;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php $admin_sidebar_active = 'incident_logbook'; include __DIR__ . '/includes/admin_sidebar.php'; ?>
        <main class="main-content">
            <header>
                <h1>Incident Logbook</h1>
                <div class="user-info"><?php echo htmlspecialchars((string)$_SESSION['full_name']); ?></div>
            </header>
            <p class="admin-page-intro">Track incidents and update resolution status.</p>

            <?php if ($message !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
                <h2 class="admin-section-title">Report New Incident</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" id="title" name="title" required maxlength="180" placeholder="Brief incident title">
                    </div>
                    <div class="form-group">
                        <label for="severity">Severity</label>
                        <select id="severity" name="severity">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="details">Details</label>
                        <textarea id="details" name="details" rows="4" required placeholder="Describe the incident and actions needed..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Add to Logbook</button>
                </form>
            </div>

            <div class="card">
                <h2 class="admin-section-title">Recent Incidents</h2>
                <form method="GET" class="incident-filter-grid">
                    <div class="form-group">
                        <label for="q">Search Title</label>
                        <input type="text" id="q" name="q" value="<?php echo htmlspecialchars($search_title); ?>" placeholder="Search incident title...">
                    </div>
                    <div class="form-group">
                        <label for="status_filter">Status</label>
                        <select id="status_filter" name="status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="open" <?php echo $filter_status === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $filter_status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="severity_filter">Severity</label>
                        <select id="severity_filter" name="severity">
                            <option value="all" <?php echo $filter_severity === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="low" <?php echo $filter_severity === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $filter_severity === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $filter_severity === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="critical" <?php echo $filter_severity === 'critical' ? 'selected' : ''; ?>>Critical</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="incident_logbook.php" class="btn btn-secondary">Reset</a>
                </form>
                <div class="table-scroll">
                    <table class="admin-data-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Details</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($incidents)): ?>
                                <?php foreach ($incidents as $incident): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$incident['title']); ?></td>
                                        <td><span class="badge-pill sev-<?php echo htmlspecialchars((string)$incident['severity']); ?>"><?php echo htmlspecialchars((string)$incident['severity']); ?></span></td>
                                        <td><span class="badge-pill st-<?php echo htmlspecialchars((string)$incident['status']); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$incident['status'])); ?></span></td>
                                        <td><?php echo nl2br(htmlspecialchars((string)$incident['details'])); ?></td>
                                        <td><?php echo !empty($incident['created_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$incident['created_at']))) : '-'; ?></td>
                                        <td>
                                            <form method="POST" class="inline-status-form">
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int)$incident['id']; ?>">
                                                <select name="status">
                                                    <option value="open" <?php echo $incident['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                                    <option value="in_progress" <?php echo $incident['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                    <option value="resolved" <?php echo $incident['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                </select>
                                                <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Update this incident status?');">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="admin-empty-state">
                                            <strong>No incidents found.</strong><br>
                                            Try changing filters, or create a new incident above.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
