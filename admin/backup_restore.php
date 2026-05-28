<?php
require_once '../auth.php';
check_role('admin');

$conn = get_db_connection();
$success = '';
$error = '';

function sql_literal($value, PDO $conn) {
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return $conn->quote((string)$value);
}

function export_database_sql(PDO $conn): string {
    $driver = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    $output = "-- MTMO backup generated at " . date('c') . "\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    if ($driver === 'mysql') {
        $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $table = (string)$table;
            if ($table === '') {
                continue;
            }
            $create_row = $conn->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $create_sql = '';
            if (isset($create_row['Create Table'])) {
                $create_sql = (string)$create_row['Create Table'];
            } else {
                $values = array_values((array)$create_row);
                $create_sql = isset($values[1]) ? (string)$values[1] : '';
            }
            $output .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $output .= $create_sql . ";\n\n";

            $rows = $conn->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns = array_map(static fn($c) => "`{$c}`", array_keys($row));
                $values = array_map(static fn($v) => sql_literal($v, $conn), array_values($row));
                $output .= "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
            }
            $output .= "\n";
        }
    } else {
        $tables = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $table = (string)$table;
            if ($table === '') {
                continue;
            }
            $stmt = $conn->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$table]);
            $create_sql = (string)($stmt->fetchColumn() ?: '');
            if ($create_sql === '') {
                continue;
            }
            $output .= "DROP TABLE IF EXISTS \"{$table}\";\n";
            $output .= $create_sql . ";\n\n";

            $rows = $conn->query("SELECT * FROM \"{$table}\"")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns = array_map(static fn($c) => "\"{$c}\"", array_keys($row));
                $values = array_map(static fn($v) => sql_literal($v, $conn), array_values($row));
                $output .= "INSERT INTO \"{$table}\" (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
            }
            $output .= "\n";
        }
    }

    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $output;
}

function split_sql_statements(string $sql): array {
    $statements = [];
    $buffer = '';
    $in_single = false;
    $in_double = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($ch === "'" && !$in_double && $prev !== '\\') {
            $in_single = !$in_single;
        } elseif ($ch === '"' && !$in_single && $prev !== '\\') {
            $in_double = !$in_double;
        }

        if ($ch === ';' && !$in_single && !$in_double) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_backup'])) {
    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        $sql = export_database_sql($conn);
        $filename = 'mtmo-backup-' . date('Ymd-His') . '.sql';
        write_audit_event((int)($_SESSION['user_id'] ?? 0), 'database_backup', 'database', 0, "Generated backup file {$filename}");
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit();
    } catch (Throwable $e) {
        app_log('error', 'admin.backup_restore.download', 'Database backup export failed.', $e->getMessage());
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        $confirm = trim((string)($_POST['confirm_restore'] ?? ''));
        if (strtoupper($confirm) !== 'RESTORE') {
            throw new RuntimeException('Type RESTORE in confirmation box to proceed.');
        }
        if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please select a valid .sql backup file.');
        }
        $tmp_path = (string)($_FILES['backup_file']['tmp_name'] ?? '');
        $name = (string)($_FILES['backup_file']['name'] ?? '');
        if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
            throw new RuntimeException('Uploaded file is not valid.');
        }
        if (!preg_match('/\.sql$/i', $name)) {
            throw new RuntimeException('Only .sql backup files are allowed.');
        }
        $sql = file_get_contents($tmp_path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Backup file is empty or unreadable.');
        }
        if (strlen($sql) > 20 * 1024 * 1024) {
            throw new RuntimeException('Backup file is too large. Maximum allowed size is 20MB.');
        }

        $statements = split_sql_statements($sql);
        if (empty($statements)) {
            throw new RuntimeException('No executable SQL statements found in backup file.');
        }

        $conn->beginTransaction();
        foreach ($statements as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $conn->exec($trimmed);
        }
        write_audit_event((int)($_SESSION['user_id'] ?? 0), 'database_restore', 'database', 0, 'Restored database from uploaded SQL backup.');
        $conn->commit();
        $success = 'Database restored successfully from backup file.';
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        app_log('error', 'admin.backup_restore.restore', 'Database restore failed.', $e->getMessage());
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup/Restore - Admin</title>
    <link rel="stylesheet" href="../style.css?v=20260429">
    <script src="../theme.js" defer></script>
    <style>
        .backup-actions {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-wrap: wrap;
            margin-top: 0.55rem;
        }
        .backup-note {
            color: #5d6f90;
            font-size: 0.86rem;
        }
        .restore-warning {
            background: #fff4e8;
            border: 1px solid #f3d8b9;
            border-radius: 10px;
            color: #7a4b1f;
            padding: 10px 12px;
            margin-bottom: 0.85rem;
            font-size: 0.9rem;
        }
        .restore-form {
            display: grid;
            gap: 0.55rem;
            margin-top: 0.35rem;
        }
        .restore-form .form-group {
            margin: 0;
        }
        .restore-form input[type="file"],
        .restore-form input[type="text"] {
            width: 100%;
            box-sizing: border-box;
        }
        .restore-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 0.15rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php $admin_sidebar_active = 'backup_restore'; include __DIR__ . '/includes/admin_sidebar.php'; ?>
        <main class="main-content">
            <header>
                <h1>Database Backup / Restore</h1>
                <div class="user-info"><?php echo htmlspecialchars((string)$_SESSION['full_name']); ?></div>
            </header>
            <p class="admin-page-intro">Backup and restore database data.</p>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
                <h2 class="admin-section-title">Create Backup</h2>
                <p class="admin-section-subtitle">Generate a full SQL backup of the current database and download it immediately.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="download_backup" value="1">
                    <div class="backup-actions">
                        <button type="submit" class="btn btn-primary">Download Backup (.sql)</button>
                        <span class="backup-note">Recommended before every major update.</span>
                    </div>
                </form>
            </div>

            <div class="card admin-danger-zone">
                <h2 class="admin-section-title">Restore from Backup</h2>
                <p class="admin-section-subtitle">Upload a SQL backup file to restore data. This will overwrite current tables and records.</p>
                <div class="restore-warning">
                    Warning: restoring a backup can replace current data. Create a fresh backup first.
                </div>
                <form method="POST" enctype="multipart/form-data" class="restore-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="restore_backup" value="1">
                    <div class="form-group">
                        <label for="backup_file">Backup File (.sql)</label>
                        <input type="file" id="backup_file" name="backup_file" accept=".sql" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_restore">Type RESTORE to confirm</label>
                        <input type="text" id="confirm_restore" name="confirm_restore" placeholder="RESTORE" required>
                    </div>
                    <div class="restore-actions">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Proceed with database restore? This action can overwrite existing data.');">Restore Database</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
