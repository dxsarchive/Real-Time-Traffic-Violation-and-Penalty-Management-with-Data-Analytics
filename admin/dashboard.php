<?php
require_once '../auth.php';
check_role('admin');

$conn = get_db_connection();
$success = '';
$error = '';
$temp_roles = ['supervisor', 'pnp_officer', 'enforcer', 'treasurer'];
$created_temp_password = '';
$user_search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$dob_search = isset($_GET['dob_q']) ? trim((string)$_GET['dob_q']) : '';
$user_role_filter = isset($_GET['role']) ? trim((string)$_GET['role']) : '';
$user_status_filter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$edit_user_id = isset($_GET['edit_user_id']) ? (int)$_GET['edit_user_id'] : 0;
$allowed_role_filters = ['all', 'admin', 'supervisor', 'pnp_officer', 'treasurer', 'enforcer', 'motorist'];
$allowed_status_filters = ['all', 'active', 'pending', 'rejected', 'inactive'];
function admin_table_has_column(PDO $conn, bool $is_mysql, string $table_name, string $column_name): bool {
    try {
        if ($is_mysql) {
            $stmt = $conn->prepare("SHOW COLUMNS FROM `$table_name` LIKE ?");
            $stmt->execute([$column_name]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        }
        $rows = $conn->query("PRAGMA table_info($table_name)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === $column_name) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}
if (!in_array($user_role_filter, $allowed_role_filters, true)) {
    $user_role_filter = 'all';
}
if (!in_array($user_status_filter, $allowed_status_filters, true)) {
    $user_status_filter = 'all';
}
$is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$has_motorist_dob = admin_table_has_column($conn, $is_mysql, 'motorists', 'date_of_birth');

if (!$has_motorist_dob) {
    try {
        if ($is_mysql) {
            $conn->exec("ALTER TABLE motorists ADD COLUMN date_of_birth DATE NULL");
        } else {
            $conn->exec("ALTER TABLE motorists ADD COLUMN date_of_birth TEXT");
        }
        $has_motorist_dob = true;
    } catch (Throwable $e) {
        $has_motorist_dob = admin_table_has_column($conn, $is_mysql, 'motorists', 'date_of_birth');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_temp_account'])) {
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = trim((string)($_POST['role'] ?? ''));
    $contact_info = trim((string)($_POST['contact_info'] ?? ''));
    $auto_password = isset($_POST['auto_password']) && $_POST['auto_password'] === '1';

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if ($full_name === '' || $username === '' || $role === '') {
            throw new RuntimeException('Full name, username, and role are required.');
        }
        if (strlen($full_name) > 100) {
            throw new RuntimeException('Full name must be 100 characters or less.');
        }
        if (!preg_match('/^[\p{L}\p{M}0-9 .\'-]{2,100}$/u', $full_name)) {
            throw new RuntimeException('Full name contains invalid characters.');
        }
        if (!in_array($role, $temp_roles, true)) {
            throw new RuntimeException('Invalid role for temporary account.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            throw new RuntimeException('Username must be 3-50 characters and use letters, numbers, dot, underscore, or hyphen.');
        }
        if ($auto_password) {
            $password = 'Tmp@' . substr(bin2hex(random_bytes(6)), 0, 8);
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Temporary password must be at least 8 characters.');
        }
        if (strlen($contact_info) > 120) {
            throw new RuntimeException('Contact information must be 120 characters or less.');
        }

        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->execute([$username]);
        if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Username already exists. Please choose another one.');
        }

        $conn->beginTransaction();
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $insert_stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, status, contact_info, must_change_password) VALUES (?, ?, ?, ?, 'active', ?, 1)");
        $insert_stmt->execute([$username, $password_hash, $full_name, $role, $contact_info]);
        $new_user_id = (int)$conn->lastInsertId();

        $admin_id = (int)($_SESSION['user_id'] ?? 0);
        write_audit_event($admin_id, 'create_temp_account', 'users', $new_user_id, "Created temporary {$role} account: {$username}");
        $conn->commit();

        $success = 'Temporary account created successfully. Password reset is required on first login.';
        $created_temp_password = $password;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        app_log('error', 'admin.dashboard.create_temp_account', 'Failed to create temporary officer account.', $e->getMessage());
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance'])) {
    $maintenance_enabled = isset($_POST['maintenance_enabled']) && $_POST['maintenance_enabled'] === '1';
    $maintenance_message = trim((string)($_POST['maintenance_message'] ?? ''));
    $maintenance_confirm = trim((string)($_POST['maintenance_confirm'] ?? ''));
    if ($maintenance_message === '') {
        $maintenance_message = 'System is temporarily under maintenance. Please try again later.';
    }

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if ($maintenance_enabled && strtoupper($maintenance_confirm) !== 'ENABLE MAINTENANCE') {
            throw new RuntimeException('Type ENABLE MAINTENANCE to turn maintenance mode ON.');
        }
        $updated_by = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System Admin');
        if (set_maintenance_state($maintenance_enabled, $maintenance_message, $updated_by)) {
            $admin_id = (int)($_SESSION['user_id'] ?? 0);
            write_audit_event($admin_id, 'maintenance_toggle', 'maintenance_state', 0, 'Maintenance mode set to ' . ($maintenance_enabled ? 'enabled' : 'disabled'));
            $success = $maintenance_enabled ? 'Maintenance mode is now ON.' : 'Maintenance mode is now OFF.';
        } else {
            $error = 'Unable to update maintenance mode. Please check file permissions.';
        }
    } catch (Throwable $e) {
        app_log('warning', 'admin.dashboard.maintenance_toggle', 'Failed maintenance toggle request.', $e->getMessage());
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_lifecycle_action'])) {
    $action = trim((string)($_POST['user_lifecycle_action'] ?? ''));
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if (!in_array($action, ['delete', 'recover'], true)) {
            throw new RuntimeException('Invalid user lifecycle action.');
        }
        if ($target_user_id <= 0) {
            throw new RuntimeException('Invalid target user.');
        }

        $target_stmt = $conn->prepare("SELECT id, username, role, status FROM users WHERE id = ? LIMIT 1");
        $target_stmt->execute([$target_user_id]);
        $target_user = $target_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target_user) {
            throw new RuntimeException('User account not found.');
        }

        $current_admin_id = (int)($_SESSION['user_id'] ?? 0);
        if ($target_user_id === $current_admin_id) {
            throw new RuntimeException('You cannot delete or recover your own account while logged in.');
        }
        if (($target_user['role'] ?? '') === 'admin') {
            throw new RuntimeException('Admin accounts cannot be modified from this action.');
        }

        if ($action === 'delete') {
            $update_stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
            $update_stmt->execute([$target_user_id]);
            write_audit_event($current_admin_id, 'user_soft_delete', 'users', $target_user_id, "Soft-deleted user: " . (string)$target_user['username']);
            $success = 'User account deleted (set to inactive).';
        } else {
            $update_stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $update_stmt->execute([$target_user_id]);
            write_audit_event($current_admin_id, 'user_recover', 'users', $target_user_id, "Recovered user: " . (string)$target_user['username']);
            $success = 'User account recovered successfully.';
        }
    } catch (Throwable $e) {
        app_log('warning', 'admin.dashboard.user_lifecycle', 'Failed user lifecycle action.', $e->getMessage());
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_enforcer_update'])) {
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));
    $contact_info = trim((string)($_POST['contact_info'] ?? ''));
    $allowed_status_values = ['active', 'pending', 'rejected', 'inactive'];

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if ($target_user_id <= 0) {
            throw new RuntimeException('Invalid traffic enforcer account.');
        }
        if ($full_name === '' || strlen($full_name) > 100) {
            throw new RuntimeException('Full name is required and must be 100 characters or less.');
        }
        if (!preg_match('/^[\p{L}\p{M}0-9 .\'-]{2,100}$/u', $full_name)) {
            throw new RuntimeException('Full name contains invalid characters.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            throw new RuntimeException('Username must be 3-50 characters and use letters, numbers, dot, underscore, or hyphen.');
        }
        if (!in_array($status, $allowed_status_values, true)) {
            throw new RuntimeException('Invalid status selected.');
        }
        if (strlen($contact_info) > 120) {
            throw new RuntimeException('Contact information must be 120 characters or less.');
        }

        $target_stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
        $target_stmt->execute([$target_user_id]);
        $target_user = $target_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target_user || (string)($target_user['role'] ?? '') !== 'enforcer') {
            throw new RuntimeException('Only traffic enforcer records can be edited from this form.');
        }

        $duplicate_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
        $duplicate_stmt->execute([$username, $target_user_id]);
        if ($duplicate_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Username already exists. Please choose another one.');
        }

        $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, status = ?, contact_info = ? WHERE id = ?");
        $update_stmt->execute([$full_name, $username, $status, $contact_info, $target_user_id]);
        write_audit_event((int)($_SESSION['user_id'] ?? 0), 'update_enforcer_user', 'users', $target_user_id, 'Updated traffic enforcer account details');
        $success = 'Traffic enforcer account updated successfully.';
        $edit_user_id = 0;
    } catch (Throwable $e) {
        app_log('warning', 'admin.dashboard.edit_enforcer', 'Failed to edit traffic enforcer account.', $e->getMessage());
        $error = $e->getMessage();
        $edit_user_id = $target_user_id;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_user_lifecycle_action'])) {
    $action = trim((string)($_POST['bulk_user_lifecycle_action'] ?? ''));
    $target_ids = $_POST['target_user_ids'] ?? [];
    if (!is_array($target_ids)) {
        $target_ids = [];
    }
    $target_ids = array_values(array_unique(array_map('intval', $target_ids)));
    $target_ids = array_values(array_filter($target_ids, fn($id) => $id > 0));

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if (!in_array($action, ['bulk_delete', 'bulk_recover'], true)) {
            throw new RuntimeException('Invalid bulk action.');
        }
        if (empty($target_ids)) {
            throw new RuntimeException('Please select at least one user.');
        }

        $current_admin_id = (int)($_SESSION['user_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count($target_ids), '?'));
        $load_stmt = $conn->prepare("SELECT id, username, role, status FROM users WHERE id IN ($placeholders)");
        $load_stmt->execute($target_ids);
        $rows = $load_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            throw new RuntimeException('No valid users found for bulk action.');
        }

        $ids_to_update = [];
        $updated_count = 0;
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $role = (string)($row['role'] ?? '');
            $status = (string)($row['status'] ?? '');
            if ($id === $current_admin_id || $role === 'admin') {
                continue;
            }
            if ($action === 'bulk_delete' && $status !== 'inactive') {
                $ids_to_update[] = $id;
            }
            if ($action === 'bulk_recover' && $status === 'inactive') {
                $ids_to_update[] = $id;
            }
        }
        if (empty($ids_to_update)) {
            throw new RuntimeException('No eligible users selected for this bulk action.');
        }

        $update_placeholders = implode(',', array_fill(0, count($ids_to_update), '?'));
        if ($action === 'bulk_delete') {
            $update_stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($update_placeholders)");
            $update_stmt->execute($ids_to_update);
            foreach ($ids_to_update as $uid) {
                write_audit_event($current_admin_id, 'user_soft_delete', 'users', (int)$uid, 'Bulk soft delete');
            }
            $updated_count = count($ids_to_update);
            $success = $updated_count . ' user account(s) deleted (set to inactive).';
        } else {
            $update_stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id IN ($update_placeholders)");
            $update_stmt->execute($ids_to_update);
            foreach ($ids_to_update as $uid) {
                write_audit_event($current_admin_id, 'user_recover', 'users', (int)$uid, 'Bulk recover');
            }
            $updated_count = count($ids_to_update);
            $success = $updated_count . ' user account(s) recovered.';
        }
    } catch (Throwable $e) {
        app_log('warning', 'admin.dashboard.user_lifecycle.bulk', 'Failed bulk user lifecycle action.', $e->getMessage());
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_motorist_dob'])) {
    $motorist_id = (int)($_POST['motorist_id'] ?? 0);
    $date_of_birth = trim((string)($_POST['date_of_birth'] ?? ''));
    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if ($motorist_id <= 0) {
            throw new RuntimeException('Invalid motorist selected.');
        }
        if ($date_of_birth === '') {
            throw new RuntimeException('Date of birth is required.');
        }
        $dob_date = DateTime::createFromFormat('Y-m-d', $date_of_birth);
        $valid_format = $dob_date && $dob_date->format('Y-m-d') === $date_of_birth;
        if (!$valid_format) {
            throw new RuntimeException('Invalid date format for date of birth.');
        }
        $today = new DateTime('today');
        if ($dob_date > $today) {
            throw new RuntimeException('Date of birth cannot be in the future.');
        }

        $stmt = $conn->prepare("UPDATE motorists SET date_of_birth = ? WHERE id = ?");
        $stmt->execute([$date_of_birth, $motorist_id]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('No changes were made for this motorist.');
        }
        $admin_id = (int)($_SESSION['user_id'] ?? 0);
        write_audit_event($admin_id, 'motorist_dob_backfill', 'motorists', $motorist_id, "Backfilled DOB to {$date_of_birth}");
        $success = 'Motorist date of birth updated successfully.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$maintenance = get_maintenance_state();
$users = [];
$roles_breakdown = [];
$statuses_breakdown = [];
$motorists_missing_dob = [];

try {
    $stats = [];
    $stats['users'] = (int)($conn->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0);
    $stats['violations'] = (int)($conn->query("SELECT COUNT(*) FROM violations")->fetchColumn() ?: 0);
    $stats['pending'] = (int)($conn->query("SELECT COUNT(*) FROM violations WHERE status = 'pending'")->fetchColumn() ?: 0);
    $stats['validated'] = (int)($conn->query("SELECT COUNT(*) FROM violations WHERE status = 'validated'")->fetchColumn() ?: 0);
    $stats['paid'] = (int)($conn->query("SELECT COUNT(*) FROM violations WHERE status = 'paid'")->fetchColumn() ?: 0);

    $role_rows = $conn->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($role_rows as $row) {
        $roles_breakdown[(string)$row['role']] = (int)$row['cnt'];
    }

    $status_rows = $conn->query("SELECT status, COUNT(*) AS cnt FROM users GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($status_rows as $row) {
        $statuses_breakdown[(string)$row['status']] = (int)$row['cnt'];
    }

    $where = [];
    $params = [];
    if ($user_search !== '') {
        $where[] = "(username LIKE :q OR full_name LIKE :q)";
        $params[':q'] = '%' . $user_search . '%';
    }
    if ($user_role_filter !== 'all') {
        $where[] = "role = :role";
        $params[':role'] = $user_role_filter;
    }
    if ($user_status_filter !== 'all') {
        $where[] = "status = :status";
        $params[':status'] = $user_status_filter;
    }

    $sql = "SELECT id, username, full_name, role, status, created_at, COALESCE(contact_info, '') AS contact_info, COALESCE(must_change_password, 0) AS must_change_password
            FROM users";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY id DESC LIMIT 120";
    $users_stmt = $conn->prepare($sql);
    $users_stmt->execute($params);
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($has_motorist_dob) {
        $dob_where = ["(date_of_birth IS NULL OR TRIM(COALESCE(date_of_birth, '')) = '')"];
        $dob_params = [];
        if ($dob_search !== '') {
            $dob_where[] = "(full_name LIKE :dob_q OR license_number LIKE :dob_q OR plate LIKE :dob_q)";
            $dob_params[':dob_q'] = '%' . $dob_search . '%';
        }
        $dob_sql = "SELECT id, full_name, license_number, plate, date_of_birth
                    FROM motorists
                    WHERE " . implode(' AND ', $dob_where) . "
                    ORDER BY id DESC
                    LIMIT 100";
        $dob_stmt = $conn->prepare($dob_sql);
        $dob_stmt->execute($dob_params);
        $motorists_missing_dob = $dob_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $stats = ['users' => 0, 'violations' => 0, 'pending' => 0, 'validated' => 0, 'paid' => 0];
    $users = [];
    $roles_breakdown = [];
    $statuses_breakdown = [];
    $motorists_missing_dob = [];
    app_log('error', 'admin.dashboard.metrics', 'Failed to load admin dashboard metrics.', $e->getMessage());
    $error = 'Some admin data could not be loaded. Please refresh or verify database access.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Admin - Control Center</title>
    <link rel="stylesheet" href="../style.css?v=20260429">
    <script src="../theme.js" defer></script>
    <style>
        body {
            background: linear-gradient(180deg, #f4f7fd 0%, #e8eef9 100%);
        }
        .main-content {
            padding-bottom: 2rem;
        }
        .main-content > header {
            margin-bottom: 0.9rem;
        }
        .main-content > .alert {
            margin-bottom: 0.85rem;
        }
        .admin-hero {
            background: linear-gradient(135deg, #0f4ca6 0%, #246ed4 54%, #2a84e9 100%);
            border-radius: 16px;
            color: #fff;
            padding: 1.2rem 1.4rem;
            margin-bottom: 1rem;
            box-shadow: 0 16px 30px rgba(19, 61, 134, 0.22);
        }
        .stats-grid {
            margin-bottom: 1rem;
        }
        .admin-hero p {
            margin: 0.4rem 0 0;
            color: rgba(255, 255, 255, 0.9);
        }
        .admin-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 1rem;
        }
        .card + .card {
            margin-top: 1rem;
        }
        .admin-chip-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .admin-chip {
            background: #eef3ff;
            border: 1px solid #d8e2fb;
            border-radius: 999px;
            color: #25457a;
            font-size: 0.84rem;
            padding: 5px 10px;
        }
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            min-height: 40px;
        }
        .checkbox-row input[type="checkbox"] {
            margin: 0;
            width: 16px;
            height: 16px;
            accent-color: #246ed4;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .checkbox-row label {
            margin: 0;
            line-height: 1.2;
            cursor: pointer;
        }
        .admin-toolbar {
            display: grid;
            grid-template-columns: 1.4fr 0.8fr 0.8fr auto;
            gap: 10px;
            align-items: end;
            margin-bottom: 12px;
        }
        .admin-toolbar .btn {
            height: 40px;
        }
        .table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border-radius: 12px;
            border: 1px solid #dbe5f5;
            background: #fff;
        }
        .table-modern thead th {
            background: #f3f7ff;
            color: #244372;
            font-size: 0.84rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .table-modern th, .table-modern td {
            padding: 10px 12px;
            border-bottom: 1px solid #edf2fb;
            text-align: left;
        }
        .table-modern tbody tr:hover {
            background: #f8fbff;
        }
        .badge-pill {
            display: inline-block;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.76rem;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .role-admin { background: #fce9f8; color: #922a79; border-color: #f7cdea; }
        .role-supervisor { background: #e8f1ff; color: #264c93; border-color: #cfe1ff; }
        .role-pnp_officer { background: #e8f7ff; color: #1f5672; border-color: #cae9fa; }
        .role-treasurer { background: #ecfff3; color: #27603f; border-color: #c9f1d8; }
        .role-enforcer { background: #fff3e9; color: #894b20; border-color: #f5dbca; }
        .role-motorist { background: #f5f5f5; color: #444; border-color: #e2e2e2; }
        .status-active { background: #ecfff3; color: #27603f; border-color: #c9f1d8; }
        .status-pending { background: #fff8e7; color: #7d5c12; border-color: #f6e5b4; }
        .status-rejected { background: #ffeaea; color: #8f2323; border-color: #f5c8c8; }
        .status-inactive { background: #f1f1f1; color: #555; border-color: #d8d8d8; }
        .users-action-cell {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .users-action-cell form {
            margin: 0;
        }
        .bulk-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .bulk-actions-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .bulk-selected-count {
            font-size: 0.84rem;
            color: #385381;
            background: #eef3ff;
            border: 1px solid #d9e4fb;
            border-radius: 999px;
            padding: 4px 10px;
            display: inline-flex;
            align-items: center;
            min-height: 28px;
        }
        .btn-danger-soft {
            background: #c0392b;
            color: #fff;
            border: 1px solid #ab2f24;
        }
        .btn-danger-soft:hover {
            background: #a93226;
        }
        .btn-success-soft {
            background: #1f8f57;
            color: #fff;
            border: 1px solid #1a7a4a;
        }
        .btn-success-soft:hover {
            background: #1a7a4a;
        }
        @media (max-width: 980px) {
            .admin-grid {
                grid-template-columns: 1fr;
            }
            .admin-toolbar {
                grid-template-columns: 1fr;
            }
            .main-content > header {
                margin-bottom: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php $admin_sidebar_active = 'dashboard'; include __DIR__ . '/includes/admin_sidebar.php'; ?>

        <main class="main-content">
            <header>
                <h1>Admin Control Center</h1>
                <div class="user-info"><?php echo htmlspecialchars((string)$_SESSION['full_name']); ?></div>
            </header>
            <p class="admin-page-intro">Manage users, security, and core system controls from one place.</p>
            <div class="admin-hero">
                <h2>Central Operations Dashboard</h2>
                <p>Monitor the entire system, manage access, and control maintenance from one secure panel.</p>
            </div>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($created_temp_password !== ''): ?>
                <div class="alert alert-warning">
                    <strong>Temporary password:</strong> <?php echo htmlspecialchars($created_temp_password); ?>
                    <br>Share this securely. User must change password after first login.
                </div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Users</h3>
                    <div class="value"><?php echo number_format($stats['users']); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Violations</h3>
                    <div class="value"><?php echo number_format($stats['violations']); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending Cases</h3>
                    <div class="value"><?php echo number_format($stats['pending']); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Validated</h3>
                    <div class="value"><?php echo number_format($stats['validated']); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Paid</h3>
                    <div class="value"><?php echo number_format($stats['paid']); ?></div>
                </div>
            </div>

            <div class="admin-grid">
                <div class="card admin-danger-zone">
                    <h2 class="admin-section-title">System Maintenance Mode</h2>
                    <p>When enabled, only admin users can access protected portals. Other users will be redirected to login with your maintenance message.</p>
                    <form method="POST">
                        <input type="hidden" name="toggle_maintenance" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <div class="form-group">
                            <label for="maintenance_message">Maintenance Message</label>
                            <textarea id="maintenance_message" name="maintenance_message" rows="3"><?php echo htmlspecialchars((string)$maintenance['message']); ?></textarea>
                        </div>
                        <div class="form-group checkbox-row">
                            <input type="checkbox" id="maintenance_enabled" name="maintenance_enabled" value="1" <?php echo !empty($maintenance['enabled']) ? 'checked' : ''; ?>>
                            <label for="maintenance_enabled">Enable maintenance mode</label>
                        </div>
                        <div class="form-group">
                            <label for="maintenance_confirm">Confirmation Phrase (required when enabling)</label>
                            <input type="text" id="maintenance_confirm" name="maintenance_confirm" placeholder="ENABLE MAINTENANCE">
                        </div>
                        <button type="submit" class="btn btn-primary">Save Maintenance Setting</button>
                    </form>
                    <p class="admin-section-subtitle" style="margin-top: 10px;">
                        <strong>Status:</strong>
                        <?php echo !empty($maintenance['enabled']) ? 'ACTIVE' : 'INACTIVE'; ?>
                        <?php if (!empty($maintenance['updated_at'])): ?>
                            | <strong>Last Update:</strong> <?php echo htmlspecialchars((string)$maintenance['updated_at']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="card">
                    <h2 class="admin-section-title">Create Temporary Officer Account</h2>
                    <p>Create temporary accounts for officer roles. Motorists are excluded because they sign up on their own.</p>
                    <form method="POST">
                        <input type="hidden" name="create_temp_account" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <div class="form-group">
                            <label for="temp_full_name">Full Name</label>
                            <input type="text" id="temp_full_name" name="full_name" required maxlength="100" placeholder="Enter complete name">
                        </div>
                        <div class="form-group">
                            <label for="temp_username">Username</label>
                            <input type="text" id="temp_username" name="username" required maxlength="50" pattern="[a-zA-Z0-9_.-]{3,50}" placeholder="e.g. supervisor_temp01">
                        </div>
                        <div class="form-group">
                            <label for="temp_password">Temporary Password</label>
                            <input type="password" id="temp_password" name="password" required minlength="8" placeholder="At least 8 characters">
                        </div>
                        <div class="form-group checkbox-row">
                            <input type="checkbox" id="auto_password" name="auto_password" value="1">
                            <label for="auto_password">Auto-generate temporary password (recommended)</label>
                        </div>
                        <div class="form-group">
                            <label for="temp_role">Officer Role</label>
                            <select id="temp_role" name="role" required>
                                <option value="">Select role</option>
                                <option value="supervisor">Supervisor</option>
                                <option value="pnp_officer">PNP Officer</option>
                                <option value="enforcer">Traffic Enforcer</option>
                                <option value="treasurer">Treasurer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="temp_contact_info">Contact Information (optional)</label>
                            <input type="text" id="temp_contact_info" name="contact_info" maxlength="120" placeholder="Phone or email">
                        </div>
                        <button type="submit" class="btn btn-primary">Create Temporary Account</button>
                    </form>
                </div>
            </div>

            <div class="card" id="users-management">
                <h2 class="admin-section-title">Users Management</h2>
                <p class="admin-section-subtitle">View and monitor all system accounts. Use filters to quickly inspect specific user groups.</p>

                <form method="GET" class="admin-toolbar">
                    <div class="form-group" style="margin:0;">
                        <label for="q">Search User</label>
                        <input type="text" id="q" name="q" value="<?php echo htmlspecialchars($user_search); ?>" placeholder="Username or full name">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            <option value="all" <?php echo $user_role_filter === 'all' ? 'selected' : ''; ?>>All roles</option>
                            <option value="admin" <?php echo $user_role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="supervisor" <?php echo $user_role_filter === 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                            <option value="pnp_officer" <?php echo $user_role_filter === 'pnp_officer' ? 'selected' : ''; ?>>PNP Officer</option>
                            <option value="treasurer" <?php echo $user_role_filter === 'treasurer' ? 'selected' : ''; ?>>Treasurer</option>
                            <option value="enforcer" <?php echo $user_role_filter === 'enforcer' ? 'selected' : ''; ?>>Enforcer</option>
                            <option value="motorist" <?php echo $user_role_filter === 'motorist' ? 'selected' : ''; ?>>Motorist</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="all" <?php echo $user_status_filter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                            <option value="active" <?php echo $user_status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="pending" <?php echo $user_status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="rejected" <?php echo $user_status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="inactive" <?php echo $user_status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="dashboard.php" class="btn btn-secondary">Reset</a>
                </form>

                <div class="admin-chip-wrap" style="margin-bottom:10px;">
                    <span class="admin-chip">Total shown: <?php echo count($users); ?></span>
                    <?php foreach ($statuses_breakdown as $status_name => $status_count): ?>
                        <span class="admin-chip"><?php echo htmlspecialchars($status_name); ?>: <?php echo (int)$status_count; ?></span>
                    <?php endforeach; ?>
                </div>

                <form method="POST" id="bulk-users-form" class="bulk-actions-row" onsubmit="return confirm('Apply bulk action to selected users?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <div class="bulk-actions-controls">
                        <label for="bulk_action" style="margin:0;">Bulk Action</label>
                        <select id="bulk_action" name="bulk_user_lifecycle_action" required>
                            <option value="">Select action</option>
                            <option value="bulk_delete">Bulk Delete (Set Inactive)</option>
                            <option value="bulk_recover">Bulk Recover (Set Active)</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">Apply to Selected</button>
                        <span class="bulk-selected-count" id="bulk-selected-count">0 users selected</span>
                    </div>
                    <small>Select users from the table below. Admin and current account are protected.</small>
                </form>

                <div class="table-scroll">
                    <table class="table-modern admin-data-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all-users" aria-label="Select all users"></th>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Password Policy</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                    <td>
                                        <?php
                                            $is_self = (int)$u['id'] === (int)($_SESSION['user_id'] ?? 0);
                                            $is_admin_user = (string)$u['role'] === 'admin';
                                        ?>
                                        <input
                                            type="checkbox"
                                            class="user-row-selector"
                                            name="target_user_ids[]"
                                            value="<?php echo (int)$u['id']; ?>"
                                            form="bulk-users-form"
                                            <?php echo ($is_self || $is_admin_user) ? 'disabled' : ''; ?>
                                        >
                                    </td>
                                    <td><?php echo (int)$u['id']; ?></td>
                                    <td><?php echo htmlspecialchars((string)$u['username']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$u['full_name']); ?></td>
                                    <td><span class="badge-pill role-<?php echo htmlspecialchars((string)$u['role']); ?>"><?php echo htmlspecialchars((string)$u['role']); ?></span></td>
                                    <td><span class="badge-pill status-<?php echo htmlspecialchars((string)$u['status']); ?>"><?php echo htmlspecialchars((string)$u['status']); ?></span></td>
                                    <td>
                                        <?php if (!empty($u['must_change_password'])): ?>
                                            <span class="badge-pill status-pending">Must Change</span>
                                        <?php else: ?>
                                            <span class="badge-pill status-active">OK</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo !empty($u['created_at']) ? htmlspecialchars((string)$u['created_at']) : '-'; ?></td>
                                    <td>
                                        <div class="users-action-cell">
                                            <?php if ((string)$u['role'] === 'enforcer'): ?>
                                                <?php
                                                    $edit_params = [];
                                                    if ($user_search !== '') { $edit_params['q'] = $user_search; }
                                                    if ($user_role_filter !== 'all') { $edit_params['role'] = $user_role_filter; }
                                                    if ($user_status_filter !== 'all') { $edit_params['status'] = $user_status_filter; }
                                                    $edit_params['edit_user_id'] = (int)$u['id'];
                                                    $edit_url = 'dashboard.php?' . http_build_query($edit_params);
                                                ?>
                                                <a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars($edit_url); ?>">Edit</a>
                                            <?php endif; ?>
                                            <?php if ((string)$u['status'] !== 'inactive'): ?>
                                                <form method="POST" onsubmit="return confirm('Delete this user account? You can recover it later.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                    <input type="hidden" name="user_lifecycle_action" value="delete">
                                                    <input type="hidden" name="target_user_id" value="<?php echo (int)$u['id']; ?>">
                                                    <button type="submit" class="btn btn-danger-soft btn-sm">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" onsubmit="return confirm('Recover this user account?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                    <input type="hidden" name="user_lifecycle_action" value="recover">
                                                    <input type="hidden" name="target_user_id" value="<?php echo (int)$u['id']; ?>">
                                                    <button type="submit" class="btn btn-success-soft btn-sm">Recover</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9"><div class="admin-empty-state"><strong>No users found.</strong> Adjust filters or clear search terms.</div></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($edit_user_id > 0): ?>
                    <?php
                        $edit_target_user = null;
                        foreach ($users as $candidate_user) {
                            if ((int)$candidate_user['id'] === $edit_user_id) {
                                $edit_target_user = $candidate_user;
                                break;
                            }
                        }
                    ?>
                    <?php if ($edit_target_user && (string)$edit_target_user['role'] === 'enforcer'): ?>
                        <?php
                            $close_params = [];
                            if ($user_search !== '') { $close_params['q'] = $user_search; }
                            if ($user_role_filter !== 'all') { $close_params['role'] = $user_role_filter; }
                            if ($user_status_filter !== 'all') { $close_params['status'] = $user_status_filter; }
                            $close_url = 'dashboard.php' . (!empty($close_params) ? '?' . http_build_query($close_params) : '');
                        ?>
                        <div class="profile-modal-overlay" role="dialog" aria-modal="true" aria-label="Edit traffic enforcer">
                            <div class="profile-card profile-modal-card">
                                <div class="profile-modal-close-top">
                                    <a class="btn profile-modal-close profile-modal-close-x" href="<?php echo htmlspecialchars($close_url); ?>" aria-label="Close edit modal">&times;</a>
                                </div>
                                <h3>Edit Traffic Enforcer Record</h3>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="save_enforcer_update" value="1">
                                    <input type="hidden" name="target_user_id" value="<?php echo (int)$edit_target_user['id']; ?>">
                                    <div class="admin-toolbar admin-two-col admin-form-tight" style="margin-bottom: 8px;">
                                        <div class="form-group" style="margin:0;">
                                            <label>Full Name</label>
                                            <input type="text" name="full_name" required maxlength="100" value="<?php echo htmlspecialchars((string)$edit_target_user['full_name']); ?>">
                                        </div>
                                        <div class="form-group" style="margin:0;">
                                            <label>Username</label>
                                            <input type="text" name="username" required maxlength="50" pattern="[a-zA-Z0-9_.-]{3,50}" value="<?php echo htmlspecialchars((string)$edit_target_user['username']); ?>">
                                        </div>
                                        <div class="form-group" style="margin:0;">
                                            <label>Status</label>
                                            <select name="status" required>
                                                <option value="active" <?php echo (string)$edit_target_user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="pending" <?php echo (string)$edit_target_user['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="rejected" <?php echo (string)$edit_target_user['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                <option value="inactive" <?php echo (string)$edit_target_user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            </select>
                                        </div>
                                        <div class="form-group" style="margin:0;">
                                            <label>Contact Information</label>
                                            <input type="text" name="contact_info" maxlength="120" value="<?php echo htmlspecialchars((string)$edit_target_user['contact_info']); ?>" placeholder="Phone or email">
                                        </div>
                                    </div>
                                    <div class="admin-actions-compact">
                                        <button type="submit" class="btn btn-primary btn-sm">Save Enforcer Changes</button>
                                        <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($close_url); ?>">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="card" id="motorist-dob-backfill">
                <h2 class="admin-section-title">Motorist DOB Backfill</h2>
                <p class="admin-section-subtitle">Fill missing date of birth values for existing motorists to power age-group analytics reports.</p>
                <?php if (!$has_motorist_dob): ?>
                    <div class="alert alert-danger">The `date_of_birth` column is not available in the `motorists` table.</div>
                <?php elseif (empty($motorists_missing_dob)): ?>
                    <div class="alert alert-success">All listed motorists already have date of birth values.</div>
                <?php else: ?>
                    <form method="GET" class="admin-toolbar" style="grid-template-columns: 1.6fr auto; margin-bottom: 10px;">
                        <div class="form-group" style="margin:0;">
                            <label for="dob_q">Search Missing DOB Records</label>
                            <input type="text" id="dob_q" name="dob_q" value="<?php echo htmlspecialchars($dob_search); ?>" placeholder="Full name, license number, or plate">
                        </div>
                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>
                    <div class="table-scroll">
                        <table class="table-modern admin-data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Full Name</th>
                                    <th>License</th>
                                    <th>Plate</th>
                                    <th>Date of Birth</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($motorists_missing_dob as $m): ?>
                                    <tr>
                                    <td><?php echo (int)$m['id']; ?></td>
                                    <td><?php echo htmlspecialchars((string)($m['full_name'] ?: 'N/A')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($m['license_number'] ?: 'N/A')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($m['plate'] ?: 'N/A')); ?></td>
                                    <td>
                                        <form method="POST" class="admin-action-row" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="save_motorist_dob" value="1">
                                            <input type="hidden" name="motorist_id" value="<?php echo (int)$m['id']; ?>">
                                            <input type="date" name="date_of_birth" required class="admin-date-input">
                                    </td>
                                    <td>
                                            <button type="submit" class="btn btn-primary btn-sm">Save DOB</button>
                                        </form>
                                    </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        (function () {
            const autoPassword = document.getElementById('auto_password');
            const tempPassword = document.getElementById('temp_password');
            if (!autoPassword || !tempPassword) {
                return;
            }
            const syncState = function () {
                if (autoPassword.checked) {
                    tempPassword.value = '';
                    tempPassword.required = false;
                    tempPassword.disabled = true;
                } else {
                    tempPassword.disabled = false;
                    tempPassword.required = true;
                }
            };
            autoPassword.addEventListener('change', syncState);
            syncState();
        }());
        (function () {
            const bulkForm = document.querySelector('.bulk-actions-row');
            const selectAll = document.getElementById('select-all-users');
            const rowSelectors = Array.from(document.querySelectorAll('.user-row-selector'));
            const selectedCountChip = document.getElementById('bulk-selected-count');
            if (!bulkForm || !selectAll || rowSelectors.length === 0) {
                return;
            }
            const enabledSelectors = rowSelectors.filter(function (cb) { return !cb.disabled; });
            const syncSelectedCount = function () {
                if (!selectedCountChip) {
                    return;
                }
                const selectedCount = enabledSelectors.filter(function (cb) { return cb.checked; }).length;
                selectedCountChip.textContent = selectedCount + ' user' + (selectedCount === 1 ? '' : 's') + ' selected';
            };
            selectAll.addEventListener('change', function () {
                enabledSelectors.forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
                syncSelectedCount();
            });
            enabledSelectors.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    const allChecked = enabledSelectors.length > 0 && enabledSelectors.every(function (item) { return item.checked; });
                    selectAll.checked = allChecked;
                    syncSelectedCount();
                });
            });
            syncSelectedCount();
        }());
    </script>
</body>
</html>
