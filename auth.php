<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

function request_ip_address() {
    return isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
}

function set_auth_notice($message) {
    $_SESSION['auth_notice'] = trim((string)$message);
}

function consume_auth_notice() {
    $message = isset($_SESSION['auth_notice']) ? (string)$_SESSION['auth_notice'] : '';
    unset($_SESSION['auth_notice']);
    return $message;
}

function csrf_token() {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_or_throw($token) {
    $expected = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
    $actual = trim((string)$token);
    if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
        throw new RuntimeException('Security check failed. Please refresh and try again.');
    }
}

function write_audit_event($user_id, $action, $table_name, $record_id, $details) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([
        (int)$user_id,
        (string)$action,
        (string)$table_name,
        (int)$record_id,
        (string)$details
    ]);
}

function ensure_app_logs_table() {
    global $pdo;
    static $initialized = false;
    if ($initialized) {
        return;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                severity VARCHAR(20) NOT NULL,
                source VARCHAR(80) NOT NULL,
                message TEXT NOT NULL,
                context TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                severity TEXT NOT NULL,
                source TEXT NOT NULL,
                message TEXT NOT NULL,
                context TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }
    } catch (Throwable $e) {
    }
    $initialized = true;
}

function app_log($severity, $source, $message, $context = '') {
    global $pdo;
    try {
        ensure_app_logs_table();
        $stmt = $pdo->prepare("INSERT INTO app_logs (severity, source, message, context) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            strtolower(trim((string)$severity)),
            trim((string)$source),
            trim((string)$message),
            trim((string)$context)
        ]);
    } catch (Throwable $e) {
    }
}

function ensure_user_password_policy_columns() {
    global $pdo;
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
            } catch (PDOException $e) {
            }
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL");
            } catch (PDOException $e) {
            }
        } else {
            try {
                $columns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
                $has_must_change = false;
                $has_changed_at = false;
                foreach ($columns as $column) {
                    if (($column['name'] ?? '') === 'must_change_password') {
                        $has_must_change = true;
                    }
                    if (($column['name'] ?? '') === 'password_changed_at') {
                        $has_changed_at = true;
                    }
                }
                if (!$has_must_change) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0");
                }
                if (!$has_changed_at) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at DATETIME");
                }
            } catch (PDOException $e) {
            }
        }
    } catch (Throwable $e) {
    }

    $initialized = true;
}

function ensure_security_events_table() {
    global $pdo;
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS security_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(80) NOT NULL,
                username VARCHAR(80) DEFAULT '',
                ip_address VARCHAR(64) DEFAULT '',
                is_success TINYINT(1) NOT NULL DEFAULT 0,
                details TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS security_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                username TEXT DEFAULT '',
                ip_address TEXT DEFAULT '',
                is_success INTEGER NOT NULL DEFAULT 0,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }
    } catch (Throwable $e) {
    }

    $initialized = true;
}

function log_security_event($event_type, $username, $details, $is_success) {
    global $pdo;
    try {
        ensure_security_events_table();
        $ip_address = request_ip_address();
        $stmt = $pdo->prepare("INSERT INTO security_events (event_type, username, ip_address, is_success, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            (string)$event_type,
            trim((string)$username),
            $ip_address,
            $is_success ? 1 : 0,
            (string)$details
        ]);
    } catch (Throwable $e) {
    }
}

function is_login_rate_limited($username) {
    global $pdo;
    try {
        ensure_security_events_table();
        $username = trim((string)$username);
        $ip_address = request_ip_address();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM security_events
                               WHERE is_success = 0
                                 AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                                 AND (username = ? OR ip_address = ?)");
        try {
            $stmt->execute([$username, $ip_address]);
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM security_events
                                   WHERE is_success = 0
                                     AND created_at >= datetime('now', '-15 minutes')
                                     AND (username = ? OR ip_address = ?)");
            $stmt->execute([$username, $ip_address]);
        }
        $failed_count = (int)$stmt->fetchColumn();
        return $failed_count >= 7;
    } catch (Throwable $e) {
        return false;
    }
}

function login_rate_limit_remaining_seconds($username) {
    global $pdo;
    try {
        ensure_security_events_table();
        $username = trim((string)$username);
        $ip_address = request_ip_address();
        $stmt = $pdo->prepare("SELECT created_at FROM security_events
                               WHERE is_success = 0
                                 AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                                 AND (username = ? OR ip_address = ?)
                               ORDER BY created_at ASC");
        try {
            $stmt->execute([$username, $ip_address]);
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT created_at FROM security_events
                                   WHERE is_success = 0
                                     AND created_at >= datetime('now', '-15 minutes')
                                     AND (username = ? OR ip_address = ?)
                                   ORDER BY created_at ASC");
            $stmt->execute([$username, $ip_address]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) < 7) {
            return 0;
        }
        $oldest = strtotime((string)$rows[0]);
        if ($oldest === false) {
            return 0;
        }
        $remaining = ($oldest + (15 * 60)) - time();
        return max(0, (int)$remaining);
    } catch (Throwable $e) {
        return 0;
    }
}

function role_dashboard_path($role) {
    switch ((string)$role) {
        case 'enforcer':
            return 'enforcer/dashboard.php';
        case 'supervisor':
            return 'supervisor/dashboard.php';
        case 'treasurer':
            return 'treasurer/dashboard.php';
        case 'motorist':
            return 'motorist/dashboard.php';
        case 'pnp_officer':
            return 'pnp_officer/dashboard.php';
        case 'admin':
            return 'admin/dashboard.php';
        default:
            return 'index.php';
    }
}

ensure_user_password_policy_columns();

function maintenance_state_file_path() {
    return __DIR__ . '/maintenance_state.json';
}

function get_maintenance_state() {
    $state = [
        'enabled' => false,
        'message' => 'System is temporarily under maintenance. Please try again later.',
        'updated_at' => null,
        'updated_by' => null
    ];

    $file = maintenance_state_file_path();
    if (!file_exists($file)) {
        return $state;
    }

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return $state;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $state;
    }

    $state['enabled'] = !empty($decoded['enabled']);
    if (isset($decoded['message']) && is_string($decoded['message']) && trim($decoded['message']) !== '') {
        $state['message'] = trim($decoded['message']);
    }
    $state['updated_at'] = isset($decoded['updated_at']) ? (string)$decoded['updated_at'] : null;
    $state['updated_by'] = isset($decoded['updated_by']) ? (string)$decoded['updated_by'] : null;

    return $state;
}

function set_maintenance_state($enabled, $message, $updated_by) {
    $payload = [
        'enabled' => (bool)$enabled,
        'message' => trim((string)$message) !== '' ? trim((string)$message) : 'System is temporarily under maintenance. Please try again later.',
        'updated_at' => date('c'),
        'updated_by' => trim((string)$updated_by)
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return @file_put_contents(maintenance_state_file_path(), $json) !== false;
}

function login($username, $password, $selected_role = null) {
    global $pdo;
    ensure_user_password_policy_columns();

    // Sanitize inputs
    $username = trim($username);
    $password = trim($password);

    if (empty($username) || empty($password)) {
        return false;
    }

    if (is_login_rate_limited($username)) {
        $remaining = login_rate_limit_remaining_seconds($username);
        $wait_text = $remaining > 0 ? "Please wait {$remaining} seconds before trying again." : 'Please try again shortly.';
        set_auth_notice("Too many failed login attempts. {$wait_text}");
        log_security_event('login_rate_limited', $username, 'Too many failed attempts in 15-minute window.', false);
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username, password, full_name, role, status, COALESCE(must_change_password, 0) AS must_change_password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Check if user account is active
            if ($user['status'] !== 'active') {
                set_auth_notice('Your account is currently inactive. Please contact the administrator to recover your account.');
                log_security_event('login_blocked_inactive', $username, 'Login blocked because account status is not active.', false);
                return false;
            }

            // Enforce role-specific portal login
            if ($selected_role && $user['role'] !== $selected_role) {
                $role_label_map = [
                    'admin' => 'Admin',
                    'supervisor' => 'Supervisor',
                    'pnp_officer' => 'PNP Officer',
                    'treasurer' => 'Treasurer',
                    'enforcer' => 'Traffic Enforcer',
                    'motorist' => 'Motorist'
                ];
                $expected_role_label = isset($role_label_map[$user['role']]) ? $role_label_map[$user['role']] : ucfirst((string)$user['role']);
                set_auth_notice("This account belongs to {$expected_role_label}. Please use the correct portal role.");
                log_security_event('login_role_mismatch', $username, 'Attempted role login mismatch.', false);
                return false;
            }

            // Regenerate session ID for security (only if no output sent)
            if (!headers_sent()) {
                session_regenerate_id(true);
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            $_SESSION['must_change_password'] = !empty($user['must_change_password']);
            log_security_event('login_success', $username, 'User login successful.', true);
            return true;
        }
        log_security_event('login_failed', $username, 'Invalid username or password.', false);
    } catch (PDOException $e) {
        app_log('error', 'auth.login', 'Login process encountered database error.', $e->getMessage());
        log_security_event('login_error', $username, 'Login process encountered a database error.', false);
        return false;
    }

    return false;
}

function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['login_time']) &&
           (time() - $_SESSION['login_time']) < 3600; // 1 hour session timeout
}

function check_role($role) {
    $current_role = $_SESSION['role'] ?? '';
    if (!is_logged_in() || ($current_role !== $role && $current_role !== 'admin')) {
        header("Location: ../index.php");
        exit();
    }

    $maintenance = get_maintenance_state();
    if (!empty($maintenance['enabled']) && $current_role !== 'admin') {
        $_SESSION['maintenance_notice'] = $maintenance['message'];
        header("Location: ../index.php?maintenance=1");
        exit();
    }

    if (!empty($_SESSION['must_change_password'])) {
        $current_script = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        if ($current_script !== 'force_password_change.php') {
            header("Location: ../force_password_change.php");
            exit();
        }
    }
}

function logout() {
    session_unset();
    session_destroy();
    header("Location: index.php?roles=1");
    exit();
}

/**
 * Setup role-specific data on first login
 * Creates necessary records and sample data for each role
 */
function setup_role_data($user_id, $role, $username, $full_name) {
    global $pdo;
    
    try {
        switch ($role) {
            case 'motorist':
                // Create motorist profile if it doesn't exist
                $stmt = $pdo->prepare("SELECT id FROM motorists WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    // Generate license number from username
                    $license_number = strtoupper($username) . '-DL';
                    
                    $ins_stmt = $pdo->prepare("INSERT INTO motorists (user_id, license_number, full_name) VALUES (?, ?, ?)");
                    $ins_stmt->execute([$user_id, $license_number, $full_name]);
                }
                
                // Check if there's a validated violation to show in dashboard
                $motorist_id_stmt = $pdo->prepare("SELECT id FROM motorists WHERE user_id = ?");
                $motorist_id_stmt->execute([$user_id]);
                $motorist = $motorist_id_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($motorist) {
                    // Create sample violation if none exists
                    $v_check = $pdo->prepare("SELECT id FROM violations WHERE motorist_id = ?");
                    $v_check->execute([$motorist['id']]);
                    
                    if (!$v_check->fetch()) {
                        // Get a random enforcer
                        $enf_stmt = $pdo->query("SELECT id FROM users WHERE role = 'enforcer' LIMIT 1");
                        $enforcer = $enf_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($enforcer) {
                            // Get a random penalty
                            $pen_stmt = $pdo->query("SELECT id, fine_amount FROM penalties ORDER BY RANDOM() LIMIT 1");
                            $penalty = $pen_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($penalty) {
                                $top_number = 'TOP-' . time() . '-' . rand(1000, 9999);
                                
                                $v_ins = $pdo->prepare("INSERT INTO violations (motorist_id, enforcer_id, penalty_id, location, fine_amount, top_number, status) VALUES (?, ?, ?, ?, ?, ?, 'validated')");
                                $v_ins->execute([$motorist['id'], $enforcer['id'], $penalty['id'], 'Municipal Plaza', $penalty['fine_amount'], $top_number]);
                                
                                // Insert offense count
                                $off_stmt = $pdo->prepare("INSERT INTO motorist_offense_counts (motorist_id, offense_count, last_violation_at) VALUES (?, 1, NOW())");
                                $off_stmt->execute([$motorist['id']]);
                            }
                        }
                    }
                }
                break;
                
            case 'enforcer':
                // Create sample violation for this enforcer to see in their dashboard
                $v_check = $pdo->prepare("SELECT id FROM violations WHERE enforcer_id = ?");
                $v_check->execute([$user_id]);
                
                if (!$v_check->fetch()) {
                    // Get a random penalty
                    $pen_stmt = $pdo->query("SELECT id, fine_amount FROM penalties ORDER BY RANDOM() LIMIT 1");
                    $penalty = $pen_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($penalty) {
                        // Create or get a test motorist
                        $moto_check = $pdo->query("SELECT id FROM motorists LIMIT 1");
                        $motorist = $moto_check->fetch(PDO::FETCH_ASSOC);
                        
                        if ($motorist) {
                            $top_number = 'TOP-' . time() . '-' . rand(1000, 9999);
                            
                            $v_ins = $pdo->prepare("INSERT INTO violations (motorist_id, enforcer_id, penalty_id, location, fine_amount, top_number, status) VALUES (?, ?, ?, ?, ?, ?, 'validated')");
                            $v_ins->execute([$motorist['id'], $user_id, $penalty['id'], 'Main Highway', $penalty['fine_amount'], $top_number]);
                        }
                    }
                }
                break;
                
            case 'supervisor':
                // Ensure there are violations to manage
                $v_count = $pdo->query("SELECT COUNT(*) as cnt FROM violations")->fetch(PDO::FETCH_ASSOC);
                
                if ($v_count['cnt'] == 0) {
                    // Create sample violations for the supervisor to validate
                    $enf_stmt = $pdo->query("SELECT id FROM users WHERE role = 'enforcer' LIMIT 1");
                    $enforcer = $enf_stmt->fetch(PDO::FETCH_ASSOC);
                    $moto_stmt = $pdo->query("SELECT id FROM motorists LIMIT 1");
                    $motorist = $moto_stmt->fetch(PDO::FETCH_ASSOC);
                    $pen_stmt = $pdo->query("SELECT id, fine_amount FROM penalties LIMIT 1");
                    $penalty = $pen_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($enforcer && $motorist && $penalty) {
                        // Create a pending violation
                        $top_number = 'TOP-' . time() . '-' . rand(1000, 9999);
                        $v_ins = $pdo->prepare("INSERT INTO violations (motorist_id, enforcer_id, penalty_id, location, fine_amount, top_number, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                        $v_ins->execute([$motorist['id'], $enforcer['id'], $penalty['id'], 'Downtown Area', $penalty['fine_amount'], $top_number]);
                        
                        // Also create a validated violation for payment
                        $top_number2 = 'TOP-' . time() . '-' . rand(1000, 9999);
                        $v_ins2 = $pdo->prepare("INSERT INTO violations (motorist_id, enforcer_id, penalty_id, location, fine_amount, top_number, status) VALUES (?, ?, ?, ?, ?, ?, 'validated')");
                        $v_ins2->execute([$motorist['id'], $enforcer['id'], $penalty['id'], 'City Hall', $penalty['fine_amount'], $top_number2]);
                    }
                }
                break;
                
            case 'treasurer':
                // Ensure there's a validated violation ready for payment
                $v_check = $pdo->query("SELECT id FROM violations WHERE status = 'validated' LIMIT 1");
                if (!$v_check->fetch()) {
                    $enf_stmt = $pdo->query("SELECT id FROM users WHERE role = 'enforcer' LIMIT 1");
                    $enforcer = $enf_stmt->fetch(PDO::FETCH_ASSOC);
                    $moto_stmt = $pdo->query("SELECT id FROM motorists LIMIT 1");
                    $motorist = $moto_stmt->fetch(PDO::FETCH_ASSOC);
                    $pen_stmt = $pdo->query("SELECT id, fine_amount FROM penalties LIMIT 1");
                    $penalty = $pen_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($enforcer && $motorist && $penalty) {
                        $top_number = 'TOP-' . time() . '-' . rand(1000, 9999);
                        $v_ins = $pdo->prepare("INSERT INTO violations (motorist_id, enforcer_id, penalty_id, location, fine_amount, top_number, status) VALUES (?, ?, ?, ?, ?, ?, 'validated')");
                        $v_ins->execute([$motorist['id'], $enforcer['id'], $penalty['id'], 'Public Market', $penalty['fine_amount'], $top_number]);
                    }
                }
                break;
                
            case 'pnp_officer':
                // No additional setup needed for PNP officer
                break;
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error setting up role data: " . $e->getMessage());
        return false;
    }
}
