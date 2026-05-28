<?php
/**
 * Enables admin role support on existing databases and creates admin1 account.
 * Run in browser: http://localhost/enable_admin_role.php
 */
require_once 'db.php';

echo "<h2>Enable Admin Role</h2>";

$password_hash = password_hash('password123', PASSWORD_DEFAULT);

try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('enforcer', 'supervisor', 'treasurer', 'motorist', 'pnp_officer', 'admin') NOT NULL");
        echo "<p>✅ MySQL role ENUM updated.</p>";
    } else {
        // SQLite: rebuild users table to expand CHECK constraint.
        $pdo->beginTransaction();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            full_name TEXT NOT NULL,
            contact_info TEXT DEFAULT '',
            role TEXT NOT NULL CHECK (role IN ('enforcer', 'supervisor', 'treasurer', 'motorist', 'pnp_officer', 'admin')),
            status TEXT DEFAULT 'active' CHECK (status IN ('pending', 'active', 'rejected')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("INSERT INTO users_new (id, username, password, full_name, contact_info, role, status, created_at)
                    SELECT id, username, password, full_name, COALESCE(contact_info, ''), role, status, created_at FROM users");
        $pdo->exec("DROP TABLE users");
        $pdo->exec("ALTER TABLE users_new RENAME TO users");
        $pdo->commit();
        echo "<p>✅ SQLite role CHECK updated.</p>";
    }

    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute(['admin1']);
    if ($check->fetch()) {
        $update = $pdo->prepare("UPDATE users SET password = ?, role = 'admin', status = 'active', full_name = ? WHERE username = ?");
        $update->execute([$password_hash, 'System Administrator', 'admin1']);
        echo "<p>✅ admin1 already existed; updated to admin role.</p>";
    } else {
        $insert = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, 'admin', 'active')");
        $insert->execute(['admin1', $password_hash, 'System Administrator']);
        echo "<p>✅ admin1 account created.</p>";
    }

    echo "<p><strong>Login:</strong> admin1 / password123</p>";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
