<?php
require_once '../auth.php';
check_role('supervisor');

global $pdo;
$conn = $pdo;
$db_driver = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ensure_contact_info_column(PDO $conn, string $driver): void
{
    if ($driver === 'mysql') {
        try {
            $conn->exec("ALTER TABLE users ADD COLUMN contact_info VARCHAR(120) DEFAULT ''");
        } catch (PDOException $e) {
            // Column already exists on most systems.
        }
        return;
    }

    try {
        $columns = $conn->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $has_contact_info = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'contact_info') {
                $has_contact_info = true;
                break;
            }
        }
        if (!$has_contact_info) {
            $conn->exec("ALTER TABLE users ADD COLUMN contact_info TEXT DEFAULT ''");
        }
    } catch (PDOException $e) {
    }
}

ensure_contact_info_column($conn, $db_driver);

$allowed_roles = ['pnp_officer', 'enforcer', 'treasurer'];
$allowed_status = ['active', 'rejected', 'pending'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $supervisor_id = (int)($_SESSION['user_id'] ?? 0);

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if (in_array($action, ['create', 'update', 'deactivate', 'activate', 'delete'], true)) {
            $conn->beginTransaction();
        }
        if ($action === 'create') {
            $full_name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $role = trim($_POST['role'] ?? '');
            $contact_info = trim($_POST['contact_info'] ?? '');

            if ($full_name === '' || $username === '' || $password === '' || $role === '') {
                throw new RuntimeException('Full name, username, password, and role are required.');
            }
            if (strlen($full_name) > 100 || !preg_match('/^[a-zA-Z0-9 .\'-]{2,100}$/', $full_name)) {
                throw new RuntimeException('Full name must be 2-100 characters and contain valid characters only.');
            }
            if (!in_array($role, $allowed_roles, true)) {
                throw new RuntimeException('Invalid role selected.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('Password must be at least 8 characters long.');
            }
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
                throw new RuntimeException('Username must be 3-50 characters and use letters, numbers, dot, underscore, or hyphen.');
            }
            if (strlen($contact_info) > 120) {
                throw new RuntimeException('Contact information must be 120 characters or less.');
            }

            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check_stmt->execute([$username]);
            if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Username already exists. Please choose a different username.');
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $insert_stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, status, contact_info, must_change_password) VALUES (?, ?, ?, ?, 'active', ?, 1)");
            $insert_stmt->execute([$username, $password_hash, $full_name, $role, $contact_info]);
            $new_user_id = (int)$conn->lastInsertId();

            write_audit_event($supervisor_id, 'create_user', 'users', $new_user_id, "Created {$role} account: {$username}");

            $message = 'User account created successfully. The user will be required to change password on first login.';
        } elseif ($action === 'update') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $role = trim($_POST['role'] ?? '');
            $status = trim($_POST['status'] ?? '');
            $contact_info = trim($_POST['contact_info'] ?? '');
            $new_password = (string)($_POST['new_password'] ?? '');

            if ($user_id <= 0 || $full_name === '' || $username === '' || $role === '') {
                throw new RuntimeException('Missing required fields for update.');
            }
            if (!in_array($role, $allowed_roles, true)) {
                throw new RuntimeException('Invalid role selected.');
            }
            if (!in_array($status, $allowed_status, true)) {
                throw new RuntimeException('Invalid account status.');
            }
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
                throw new RuntimeException('Username must be 3-50 characters and use letters, numbers, dot, underscore, or hyphen.');
            }
            if (strlen($contact_info) > 120) {
                throw new RuntimeException('Contact information must be 120 characters or less.');
            }
            if ($new_password !== '' && strlen($new_password) < 8) {
                throw new RuntimeException('New password must be at least 8 characters long.');
            }

            $target_stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ?");
            $target_stmt->execute([$user_id]);
            $target_user = $target_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target_user) {
                throw new RuntimeException('User account not found.');
            }
            if (!in_array($target_user['role'], $allowed_roles, true)) {
                throw new RuntimeException('Only PNP, Enforcer, and Treasurer accounts can be edited here.');
            }

            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
            $check_stmt->execute([$username, $user_id]);
            if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Username already exists. Please choose a different username.');
            }

            if ($new_password !== '') {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, status = ?, contact_info = ?, password = ? WHERE id = ?");
                $update_stmt->execute([$full_name, $username, $role, $status, $contact_info, $password_hash, $user_id]);
            } else {
                $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, status = ?, contact_info = ? WHERE id = ?");
                $update_stmt->execute([$full_name, $username, $role, $status, $contact_info, $user_id]);
            }

            write_audit_event($supervisor_id, 'update_user', 'users', $user_id, "Updated account: {$username} ({$role}, {$status})");

            $message = 'User account updated successfully.';
        } elseif ($action === 'deactivate') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id <= 0) {
                throw new RuntimeException('Invalid user account.');
            }

            $target_stmt = $conn->prepare("SELECT id, role, username FROM users WHERE id = ?");
            $target_stmt->execute([$user_id]);
            $target_user = $target_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target_user || !in_array($target_user['role'], $allowed_roles, true)) {
                throw new RuntimeException('User account not found or not manageable from this page.');
            }

            $update_stmt = $conn->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
            $update_stmt->execute([$user_id]);

            write_audit_event($supervisor_id, 'deactivate_user', 'users', $user_id, "Deactivated account: {$target_user['username']}");

            $message = 'User account deactivated.';
        } elseif ($action === 'activate') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id <= 0) {
                throw new RuntimeException('Invalid user account.');
            }

            $target_stmt = $conn->prepare("SELECT id, role, username FROM users WHERE id = ?");
            $target_stmt->execute([$user_id]);
            $target_user = $target_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target_user || !in_array($target_user['role'], $allowed_roles, true)) {
                throw new RuntimeException('User account not found or not manageable from this page.');
            }

            $update_stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $update_stmt->execute([$user_id]);

            write_audit_event($supervisor_id, 'activate_user', 'users', $user_id, "Activated account: {$target_user['username']}");

            $message = 'User account activated.';
        } elseif ($action === 'delete') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id <= 0) {
                throw new RuntimeException('Invalid user account.');
            }

            $target_stmt = $conn->prepare("SELECT id, role, username FROM users WHERE id = ?");
            $target_stmt->execute([$user_id]);
            $target_user = $target_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target_user || !in_array($target_user['role'], $allowed_roles, true)) {
                throw new RuntimeException('User account not found or not manageable from this page.');
            }

            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->execute([$user_id]);

            write_audit_event($supervisor_id, 'delete_user', 'users', $user_id, "Deleted account: {$target_user['username']}");

            $message = 'User account deleted permanently.';
        }
        if ($conn->inTransaction()) {
            $conn->commit();
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        app_log('error', 'supervisor.user_management.db', 'Supervisor account management database error.', $e->getMessage());
        if (stripos($e->getMessage(), 'foreign key') !== false) {
            $error = 'Cannot delete this account because it is linked to operational records. Deactivate it instead.';
        } else {
            $error = 'Database error: ' . $e->getMessage();
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        app_log('warning', 'supervisor.user_management.action', 'Supervisor account management action failed.', $e->getMessage());
        $error = $e->getMessage();
    }
}

// Search, filtering, and pagination controls
$search_query = trim($_GET['q'] ?? '');
$filter_role = trim($_GET['role'] ?? 'all');
$filter_status = trim($_GET['status'] ?? 'all');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 5;

$filter_clauses = ["role IN ('pnp_officer', 'enforcer', 'treasurer')"];
$filter_params = [];

if ($search_query !== '') {
    $filter_clauses[] = "(full_name LIKE ? OR username LIKE ? OR COALESCE(contact_info, '') LIKE ?)";
    $search_like = '%' . $search_query . '%';
    $filter_params[] = $search_like;
    $filter_params[] = $search_like;
    $filter_params[] = $search_like;
}
if (in_array($filter_role, $allowed_roles, true)) {
    $filter_clauses[] = "role = ?";
    $filter_params[] = $filter_role;
}
if (in_array($filter_status, $allowed_status, true)) {
    $filter_clauses[] = "status = ?";
    $filter_params[] = $filter_status;
}

$where_sql = "WHERE " . implode(' AND ', $filter_clauses);

$count_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM users $where_sql");
$count_stmt->execute($filter_params);
$filtered_total = (int)($count_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

$total_pages = max(1, (int)ceil($filtered_total / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$list_sql = "SELECT id, full_name, username, role, status, COALESCE(contact_info, '') AS contact_info, created_at
             FROM users
             $where_sql
             ORDER BY created_at DESC, id DESC
             LIMIT $per_page OFFSET $offset";
$users_stmt = $conn->prepare($list_sql);
$users_stmt->execute($filter_params);
$managed_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$base_query_params = [];
if ($search_query !== '') {
    $base_query_params['q'] = $search_query;
}
if (in_array($filter_role, $allowed_roles, true)) {
    $base_query_params['role'] = $filter_role;
}
if (in_array($filter_status, $allowed_status, true)) {
    $base_query_params['status'] = $filter_status;
}

// Overall account statistics (not limited by current filters/pagination)
$stats_stmt = $conn->query("SELECT status, COUNT(*) AS count
                            FROM users
                            WHERE role IN ('pnp_officer', 'enforcer', 'treasurer')
                            GROUP BY status");
$stats_rows = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_accounts = 0;
$active_accounts = 0;
$inactive_accounts = 0;
$pending_accounts = 0;
foreach ($stats_rows as $stat_row) {
    $count = (int)($stat_row['count'] ?? 0);
    $status_key = (string)($stat_row['status'] ?? '');
    $total_accounts += $count;
    if ($status_key === 'active') {
        $active_accounts += $count;
    } elseif ($status_key === 'pending') {
        $pending_accounts += $count;
    } else {
        $inactive_accounts += $count;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Supervisor</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="../theme.js" defer></script>
    <style>
        .user-layout {
                    display: grid;
                    grid-template-columns: 1fr;
                    gap: 1rem;
                }
                .quick-stats {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                    gap: 0.75rem;
                    margin-bottom: 1rem;
                }
                .quick-stat {
                    background: var(--surface);
                    border: 1px solid var(--border-color);
                    border-radius: 10px;
                    padding: 0.75rem 0.9rem;
                    box-shadow: var(--dash-shadow-sm, 0 1px 2px rgba(15, 23, 42, 0.04));
                }
                .quick-stat-label {
                    font-size: 0.75rem;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: var(--muted-text);
                    font-weight: 700;
                }
                .quick-stat-value {
                    font-size: 1.4rem;
                    color: var(--text-color);
                    font-weight: 800;
                    line-height: 1.1;
                }
                .compact-field {
                    width: 100%;
                }
                .row-actions {
                    display: flex;
                    gap: 0.4rem;
                    flex-wrap: wrap;
                    margin-top: 0.55rem;
                }
                .account-form {
                    border: 1px solid var(--border-color);
                    border-radius: 10px;
                    padding: 0.85rem;
                    margin-bottom: 0.7rem;
                    background: var(--surface);
                }
                .account-form strong {
                    color: var(--text-color);
                }
                .account-grid {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(180px, 1fr));
                    gap: 0.55rem;
                }
                .field-note {
                    margin-top: 0.4rem;
                    color: var(--muted-text);
                    font-size: 0.8rem;
                }
                .role-pill {
                    font-size: 0.72rem;
                    font-weight: 700;
                    padding: 0.16rem 0.5rem;
                    border-radius: 999px;
                    border: 1px solid var(--border-color);
                    background: rgba(42, 125, 225, 0.12);
                    color: var(--primary-color);
                    margin-left: 0.35rem;
                }
                .toolbar-grid {
                    display: grid;
                    grid-template-columns: 2fr 1fr 1fr auto auto;
                    gap: 0.55rem;
                    align-items: end;
                    margin-bottom: 0.85rem;
                }
                .toolbar-grid .form-group {
                    margin-bottom: 0;
                }
                .toolbar-grid .btn {
                    min-height: 42px;
                    min-width: 112px;
                    margin: 0;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    padding-top: 0.55rem;
                    padding-bottom: 0.55rem;
                }
                .pagination-bar {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 0.5rem;
                    margin-top: 0.8rem;
                }
                .pagination-links {
                    display: flex;
                    gap: 0.35rem;
                    flex-wrap: wrap;
                }
                .create-account-launch {
                    margin-bottom: 0.85rem;
                }
                .create-account-modal-backdrop {
                    position: fixed;
                    inset: 0;
                    background: rgba(10, 20, 42, 0.6);
                    display: none;
                    align-items: center;
                    justify-content: center;
                    padding: 1rem;
                    z-index: 2200;
                }
                .create-account-modal-backdrop.open { display: flex; }
                .create-account-modal {
                    width: min(560px, 100%);
                    max-height: 92vh;
                    overflow-y: auto;
                    background: var(--surface);
                    border: 1px solid var(--border-color);
                    border-radius: 12px;
                    box-shadow: 0 24px 56px rgba(9, 22, 48, 0.32);
                }
                .create-account-modal-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 0.7rem;
                    border-bottom: 1px solid var(--border-color);
                    padding: 0.9rem 1rem;
                    position: sticky;
                    top: 0;
                    background: var(--surface);
                }
                .create-account-modal-head h2 {
                    margin: 0;
                    color: var(--text-color);
                    font-size: 1.1rem;
                }
                .create-account-modal-close {
                    border: 0;
                    background: transparent;
                    color: var(--muted-text);
                    font-size: 1.4rem;
                    line-height: 1;
                    font-weight: 700;
                    cursor: pointer;
                }
                .create-account-modal-body {
                    padding: 1rem;
                }
                .page-link {
                    display: inline-block;
                    text-decoration: none;
                    padding: 0.3rem 0.6rem;
                    border: 1px solid var(--border-color);
                    border-radius: 7px;
                    color: var(--primary-color);
                    background: var(--surface);
                    font-weight: 700;
                    font-size: 0.82rem;
                }
                .page-link:hover {
                    filter: brightness(1.06);
                }
                .page-link.active {
                    background: var(--dash-sidebar-active, #3d6df0);
                    border-color: var(--dash-sidebar-active, #3d6df0);
                    color: #fff;
                }
                .list-meta {
                    color: var(--muted-text);
                    font-size: 0.84rem;
                    margin-bottom: 0.6rem;
                }
                @media (max-width: 1080px) {
                    .user-layout {
                        grid-template-columns: 1fr;
                    }
                    .toolbar-grid {
                        grid-template-columns: 1fr 1fr;
                    }
                }
                @media (max-width: 760px) {
                    .account-grid {
                        grid-template-columns: 1fr;
                    }
                    .toolbar-grid {
                        grid-template-columns: 1fr;
                    }
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
<?php $supervisor_sidebar_active = 'users'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

        <main class="main-content">
            <header>
                <h1>Account Management</h1>
                <div style="display:flex;align-items:center;gap:0.55rem;flex-wrap:wrap;">
                    <button type="button" class="btn btn-primary" id="open-create-account-modal">Open Create Account Form</button>
                    <div class="user-info"><?php echo h((string)$_SESSION['full_name']); ?></div>
                </div>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert alert-success"><?php echo h($message); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo h($error); ?></div>
            <?php endif; ?>

            <div class="quick-stats">
                <div class="quick-stat"><div class="quick-stat-label">Total Accounts</div><div class="quick-stat-value"><?php echo number_format($total_accounts); ?></div></div>
                <div class="quick-stat"><div class="quick-stat-label">Active</div><div class="quick-stat-value"><?php echo number_format($active_accounts); ?></div></div>
                <div class="quick-stat"><div class="quick-stat-label">Inactive</div><div class="quick-stat-value"><?php echo number_format($inactive_accounts); ?></div></div>
                <div class="quick-stat"><div class="quick-stat-label">Pending</div><div class="quick-stat-value"><?php echo number_format($pending_accounts); ?></div></div>
            </div>

            <div class="user-layout">
                <section class="card">
                    <h2>Manage Existing Accounts</h2>
                    <form method="GET" class="toolbar-grid">
                        <div class="form-group">
                            <label>Search (name, username, contact)</label>
                            <input class="compact-field" type="text" name="q" value="<?php echo h($search_query); ?>" placeholder="Search users...">
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <select class="compact-field" name="role">
                                <option value="all">All Roles</option>
                                <option value="pnp_officer" <?php echo $filter_role === 'pnp_officer' ? 'selected' : ''; ?>>PNP Officer</option>
                                <option value="enforcer" <?php echo $filter_role === 'enforcer' ? 'selected' : ''; ?>>Traffic Enforcer</option>
                                <option value="treasurer" <?php echo $filter_role === 'treasurer' ? 'selected' : ''; ?>>Treasurer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select class="compact-field" name="status">
                                <option value="all">All Status</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a class="btn btn-secondary" href="user_management.php">Reset</a>
                    </form>
                    <div class="list-meta">
                        Showing <?php echo number_format(count($managed_users)); ?> of <?php echo number_format($filtered_total); ?> matching account(s).
                    </div>
                    <?php if (empty($managed_users)): ?>
                        <p>No accounts match the current filters.</p>
                    <?php else: ?>
                        <?php foreach ($managed_users as $user_item): ?>
                            <form method="POST" class="account-form">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?php echo (int)$user_item['id']; ?>">
                                <div style="margin-bottom:0.6rem;">
                                    <strong><?php echo h((string)$user_item['full_name']); ?></strong>
                                    <span class="role-pill"><?php echo h(strtoupper((string)$user_item['role'])); ?></span>
                                </div>
                                <div class="account-grid">
                                    <div class="form-group">
                                        <label>Full Name</label>
                                        <input class="compact-field" type="text" name="full_name" value="<?php echo h((string)$user_item['full_name']); ?>" required maxlength="100">
                                    </div>
                                    <div class="form-group">
                                        <label>Username</label>
                                        <input class="compact-field" type="text" name="username" value="<?php echo h((string)$user_item['username']); ?>" required maxlength="50" pattern="[a-zA-Z0-9_.-]{3,50}">
                                    </div>
                                    <div class="form-group">
                                        <label>Role</label>
                                        <select class="compact-field" name="role" required>
                                            <option value="pnp_officer" <?php echo $user_item['role'] === 'pnp_officer' ? 'selected' : ''; ?>>PNP Officer</option>
                                            <option value="enforcer" <?php echo $user_item['role'] === 'enforcer' ? 'selected' : ''; ?>>Traffic Enforcer</option>
                                            <option value="treasurer" <?php echo $user_item['role'] === 'treasurer' ? 'selected' : ''; ?>>Treasurer</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select class="compact-field" name="status" required>
                                            <option value="active" <?php echo $user_item['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="pending" <?php echo $user_item['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="rejected" <?php echo $user_item['status'] === 'rejected' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Contact Information</label>
                                        <input class="compact-field" type="text" name="contact_info" value="<?php echo h((string)$user_item['contact_info']); ?>" maxlength="120" placeholder="Phone number or email">
                                    </div>
                                    <div class="form-group">
                                        <label>New Password (optional)</label>
                                        <input class="compact-field" type="password" name="new_password" minlength="8" placeholder="Leave blank to keep current password">
                                    </div>
                                </div>
                                <div class="row-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                                </div>
                            </form>
                            <div class="row-actions" style="margin-bottom:1rem;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$user_item['id']; ?>">
                                    <button type="submit" class="btn btn-success btn-sm">Activate</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="deactivate">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$user_item['id']; ?>">
                                    <button type="submit" class="btn btn-warning btn-sm">Deactivate</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$user_item['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this account permanently? This cannot be undone.');">Delete</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-bar">
                                <div class="list-meta">Page <?php echo number_format($page); ?> of <?php echo number_format($total_pages); ?></div>
                                <div class="pagination-links">
                                    <?php
                                    for ($p = 1; $p <= $total_pages; $p++):
                                        $page_params = $base_query_params;
                                        $page_params['page'] = $p;
                                        $page_url = 'user_management.php?' . http_build_query($page_params);
                                    ?>
                                        <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo h($page_url); ?>"><?php echo number_format($p); ?></a>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
            <div class="create-account-modal-backdrop" id="create-account-modal-backdrop" aria-hidden="true">
                <div class="create-account-modal" role="dialog" aria-modal="true" aria-labelledby="create-account-modal-title">
                    <div class="create-account-modal-head">
                        <h2 id="create-account-modal-title">Create New Account</h2>
                        <button type="button" class="create-account-modal-close" id="close-create-account-modal" aria-label="Close create account modal">&times;</button>
                    </div>
                    <div class="create-account-modal-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="action" value="create">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input class="compact-field" type="text" name="full_name" required maxlength="100">
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <input class="compact-field" type="text" name="username" required maxlength="50" pattern="[a-zA-Z0-9_.-]{3,50}">
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input class="compact-field" type="password" name="password" required minlength="8">
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <select class="compact-field" name="role" required>
                                    <option value="">Select role</option>
                                    <option value="pnp_officer">PNP Officer</option>
                                    <option value="enforcer">Traffic Enforcer</option>
                                    <option value="treasurer">Treasurer</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Contact Information (optional)</label>
                                <input class="compact-field" type="text" name="contact_info" maxlength="120" placeholder="Phone number or email">
                            </div>
                            <button type="submit" class="btn btn-primary">Create Account</button>
                            <div class="field-note">Passwords are encrypted using secure hashing before storage.</div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
        (function () {
            const modal = document.getElementById('create-account-modal-backdrop');
            const openBtn = document.getElementById('open-create-account-modal');
            const closeBtn = document.getElementById('close-create-account-modal');
            if (!modal || !openBtn || !closeBtn) {
                return;
            }
            const openModal = function () {
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
            };
            const closeModal = function () {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            };
            openBtn.addEventListener('click', openModal);
            closeBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeModal();
                }
            });
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && (trim((string)($_POST['action'] ?? '')) === 'create')): ?>
            openModal();
            <?php endif; ?>
        }());
    </script>
</body>
</html>
