<?php
require_once '../auth.php';
check_role('admin');

$conn = get_db_connection();
ensure_security_events_table();

$filter_type = trim((string)($_GET['type'] ?? 'all'));
$filter_result = trim((string)($_GET['result'] ?? 'all'));
$search_user = trim((string)($_GET['user'] ?? ''));
$allowed_types = ['all', 'login_success', 'login_failed', 'login_role_mismatch', 'login_blocked_inactive', 'login_error'];
if (!in_array($filter_type, $allowed_types, true)) {
    $filter_type = 'all';
}
if (!in_array($filter_result, ['all', 'success', 'failed'], true)) {
    $filter_result = 'all';
}

$events = [];
try {
    $where = [];
    $params = [];
    if ($filter_type !== 'all') {
        $where[] = "event_type = :event_type";
        $params[':event_type'] = $filter_type;
    }
    if ($filter_result === 'success') {
        $where[] = "is_success = 1";
    } elseif ($filter_result === 'failed') {
        $where[] = "is_success = 0";
    }
    if ($search_user !== '') {
        $where[] = "username LIKE :username";
        $params[':username'] = '%' . $search_user . '%';
    }

    $sql = "SELECT id, event_type, username, ip_address, is_success, details, created_at FROM security_events";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY id DESC LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $events = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Events - Admin</title>
    <link rel="stylesheet" href="../style.css?v=20260429">
    <script src="../theme.js" defer></script>
    <style>
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }
        .filter-grid .form-group {
            margin: 0;
        }
        .filter-grid {
            margin-bottom: 0.3rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php $admin_sidebar_active = 'security_events'; include __DIR__ . '/includes/admin_sidebar.php'; ?>
        <main class="main-content">
            <header>
                <h1>Security Events</h1>
                <div class="user-info"><?php echo htmlspecialchars((string)$_SESSION['full_name']); ?></div>
            </header>
            <p class="admin-page-intro">Review recent security log events.</p>

            <div class="card">
                <h2 class="admin-section-title">Filter Security Logs</h2>
                <form method="GET" class="filter-grid">
                    <div class="form-group">
                        <label for="user">Username</label>
                        <input type="text" id="user" name="user" value="<?php echo htmlspecialchars($search_user); ?>" placeholder="Search username">
                    </div>
                    <div class="form-group">
                        <label for="type">Event Type</label>
                        <select id="type" name="type">
                            <?php foreach ($allowed_types as $event_type): ?>
                                <option value="<?php echo htmlspecialchars($event_type); ?>" <?php echo $filter_type === $event_type ? 'selected' : ''; ?>><?php echo htmlspecialchars($event_type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="result">Result</label>
                        <select id="result" name="result">
                            <option value="all" <?php echo $filter_result === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="success" <?php echo $filter_result === 'success' ? 'selected' : ''; ?>>Success</option>
                            <option value="failed" <?php echo $filter_result === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="security_events.php" class="btn btn-secondary">Reset</a>
                </form>
            </div>

            <div class="card">
                <h2 class="admin-section-title">Recent Security Logs</h2>
                <div class="table-scroll">
                    <table class="admin-data-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Username</th>
                                <th>IP</th>
                                <th>Result</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($events)): ?>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$event['created_at']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$event['event_type']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$event['username']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$event['ip_address']); ?></td>
                                        <td><?php echo !empty($event['is_success']) ? 'Success' : 'Failed'; ?></td>
                                        <td><?php echo htmlspecialchars((string)$event['details']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6"><div class="admin-empty-state"><strong>No security events found.</strong> Try broadening your filters.</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
