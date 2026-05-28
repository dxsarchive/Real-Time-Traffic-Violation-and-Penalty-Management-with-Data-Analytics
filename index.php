<?php
session_start();
$allowed_roles = ['motorist', 'enforcer', 'pnp_officer', 'supervisor', 'treasurer'];
$selected_role = isset($_GET['role']) ? trim($_GET['role']) : '';
$show_login = in_array($selected_role, $allowed_roles, true);
$show_roles = isset($_GET['roles']) || $show_login;
$show_public_welcome = isset($_GET['view']) && $_GET['view'] === 'welcome';
$selected_account = isset($_GET['account']) ? trim($_GET['account']) : '';
$show_officer_role_picker = isset($_GET['roles']) && !$show_login && $selected_account === 'officer';
$motorist_auth_mode = isset($_GET['auth']) && $_GET['auth'] === 'signup' ? 'signup' : 'signin';

require_once 'auth.php';

$error = '';
$maintenance_notice = '';
$auth_notice = consume_auth_notice();
$motorist_signup_error = '';
$motorist_signup_success = '';
$motorist_signup_full_name = '';
$motorist_signup_username = '';
$motorist_signup_plate = '';
$motorist_search_query = '';
$motorist_search_results = [];
$motorist_search_performed = false;
$feedback_form_success = '';
$feedback_form_errors = [];
$feedback_full_name = '';
$feedback_top_number = '';
$feedback_contact_info = '';
$feedback_concern_type = 'Type of Concern';
$feedback_message = '';
$welcome_articles = [];
$welcome_announcements = [];
$welcome_tutorial_videos = [];
$office_contact = [
    'office_name' => 'Municipal Traffic Management Office',
    'address' => 'Pototan, Iloilo',
    'phone' => '09637464431',
    'email' => 'pototanmtmo@gmail.com'
];

function normalize_plate_number(string $plate): string {
    $plate = strtoupper(trim($plate));
    $plate = preg_replace('/\s+/', ' ', $plate);
    return $plate ?? '';
}

try {
    global $pdo;
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($db_driver === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            image_path VARCHAR(500) DEFAULT '',
            posted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        try {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN image_path VARCHAR(500) DEFAULT ''");
        } catch (PDOException $e) {
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_videos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            url VARCHAR(500) NULL,
            file_path VARCHAR(500) DEFAULT '',
            description TEXT,
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        try {
            $pdo->exec("ALTER TABLE tutorial_videos ADD COLUMN file_path VARCHAR(500) DEFAULT ''");
        } catch (PDOException $e) {
        }
        try {
            $pdo->exec("ALTER TABLE tutorial_videos MODIFY url VARCHAR(500) NULL");
        } catch (PDOException $e) {
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_concerns (
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            image_path TEXT DEFAULT '',
            posted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        try {
            $columns = $pdo->query("PRAGMA table_info(announcements)")->fetchAll(PDO::FETCH_ASSOC);
            $has_image_path = false;
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'image_path') {
                    $has_image_path = true;
                    break;
                }
            }
            if (!$has_image_path) {
                $pdo->exec("ALTER TABLE announcements ADD COLUMN image_path TEXT DEFAULT ''");
            }
        } catch (PDOException $e) {
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_videos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            url TEXT,
            file_path TEXT DEFAULT '',
            description TEXT,
            sort_order INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        try {
            $columns = $pdo->query("PRAGMA table_info(tutorial_videos)")->fetchAll(PDO::FETCH_ASSOC);
            $has_file_path = false;
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'file_path') {
                    $has_file_path = true;
                    break;
                }
            }
            if (!$has_file_path) {
                $pdo->exec("ALTER TABLE tutorial_videos ADD COLUMN file_path TEXT DEFAULT ''");
            }
        } catch (PDOException $e) {
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_concerns (
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

    require_once __DIR__ . '/includes/ensure_articles_schema.php';
    ensure_articles_schema($pdo, $db_driver);

    $welcome_articles = $pdo->query("SELECT title, content, published_at, COALESCE(link_url, '') AS link_url, COALESCE(attachment_path, '') AS attachment_path FROM articles WHERE COALESCE(is_active, 1) = 1 ORDER BY published_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $welcome_announcements = $pdo->query("SELECT title, content, image_path, posted_at FROM announcements WHERE is_active = 1 ORDER BY posted_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $welcome_tutorial_videos = $pdo->query("SELECT title, url, file_path, description FROM tutorial_videos WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $welcome_articles = [];
    $welcome_announcements = [];
    $welcome_tutorial_videos = [];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['motorist_search'])) {
    $motorist_search_query = isset($_POST['search_query']) ? trim($_POST['search_query']) : '';
    $motorist_search_performed = ($motorist_search_query !== '');

    if ($motorist_search_query !== '') {
        global $pdo;
        $search_stmt = $pdo->prepare("SELECT v.*, COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') as violation_display,
                                             (SELECT e2.file_path
                                              FROM evidence e2
                                              WHERE e2.violation_id = v.id
                                              ORDER BY e2.uploaded_at DESC, e2.id DESC
                                              LIMIT 1) as file_path,
                                             m.plate, m.full_name as motorist_name, m.license_number
                                      FROM violations v
                                      LEFT JOIN penalties p ON v.penalty_id = p.id
                                      JOIN motorists m ON v.motorist_id = m.id
                                      WHERE m.plate LIKE ?
                                         OR m.full_name LIKE ?
                                         OR m.license_number LIKE ?
                                         OR v.top_number LIKE ?
                                      ORDER BY v.violation_date DESC");
        $search_stmt->execute([
            '%' . $motorist_search_query . '%',
            '%' . $motorist_search_query . '%',
            '%' . $motorist_search_query . '%',
            '%' . $motorist_search_query . '%'
        ]);
        $motorist_search_results = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback_home'])) {
    $feedback_full_name = isset($_POST['feedback_full_name']) ? trim($_POST['feedback_full_name']) : '';
    $feedback_top_number = isset($_POST['feedback_top_number']) ? trim($_POST['feedback_top_number']) : '';
    $feedback_contact_info = isset($_POST['feedback_contact_info']) ? trim($_POST['feedback_contact_info']) : '';
    $feedback_concern_type = isset($_POST['feedback_concern_type']) ? trim($_POST['feedback_concern_type']) : 'Type of Concern';
    $feedback_message = isset($_POST['feedback_message']) ? trim($_POST['feedback_message']) : '';

    if ($feedback_full_name === '') {
        $feedback_form_errors[] = 'Full Name is required.';
    }
    if ($feedback_top_number === '') {
        $feedback_form_errors[] = 'TOP NUMBER is required.';
    }
    if ($feedback_contact_info === '') {
        $feedback_form_errors[] = 'Contact Information is required.';
    }
    if ($feedback_message === '') {
        $feedback_form_errors[] = 'Message / Description is required.';
    }

    $allowed_concern_types = ['Dispute', 'Inquiry', 'Complaint'];
    if (!in_array($feedback_concern_type, $allowed_concern_types, true)) {
        $feedback_form_errors[] = 'Invalid concern type selected.';
    }

    $matched_violation = null;
    if (empty($feedback_form_errors)) {
        $normalized_reference = trim($feedback_top_number);

        $find_violation_stmt = $pdo->prepare("SELECT id FROM violations WHERE top_number = ? LIMIT 1");
        $find_violation_stmt->execute([$normalized_reference]);
        $matched_violation = $find_violation_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$matched_violation) {
            $find_violation_case_stmt = $pdo->prepare("SELECT id FROM violations WHERE UPPER(TRIM(top_number)) = UPPER(?) LIMIT 1");
            $find_violation_case_stmt->execute([$normalized_reference]);
            $matched_violation = $find_violation_case_stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$matched_violation && ctype_digit($normalized_reference)) {
            $find_violation_id_stmt = $pdo->prepare("SELECT id FROM violations WHERE id = ? LIMIT 1");
            $find_violation_id_stmt->execute([(int)$normalized_reference]);
            $matched_violation = $find_violation_id_stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$matched_violation && ctype_digit($normalized_reference)) {
            // Accept short numeric ticket fragments (e.g. "4841" for "TOP-...-4841")
            $find_violation_suffix_stmt = $pdo->prepare("SELECT id
                                                         FROM violations
                                                         WHERE TRIM(top_number) LIKE ?
                                                         ORDER BY violation_date DESC
                                                         LIMIT 1");
            $find_violation_suffix_stmt->execute(['%-' . $normalized_reference]);
            $matched_violation = $find_violation_suffix_stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$matched_violation) {
            // Final fallback: partial reference match
            $find_violation_partial_stmt = $pdo->prepare("SELECT id
                                                          FROM violations
                                                          WHERE UPPER(TRIM(top_number)) LIKE UPPER(?)
                                                          ORDER BY violation_date DESC
                                                          LIMIT 1");
            $find_violation_partial_stmt->execute(['%' . $normalized_reference . '%']);
            $matched_violation = $find_violation_partial_stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$matched_violation) {
            $feedback_form_errors[] = 'Invalid TOP NUMBER. Please check and try again.';
        }
    }

    if (empty($feedback_form_errors)) {
        try {
            $system_user_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $system_user_stmt->execute(['guest_feedback_system']);
            $system_user = $system_user_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$system_user) {
                $random_seed = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : uniqid('guest_fb_', true);
                $generated_password = password_hash($random_seed, PASSWORD_BCRYPT);

                try {
                    $create_user_stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status, contact_info)
                                                       VALUES (?, ?, ?, 'motorist', 'active', ?)");
                    $create_user_stmt->execute([
                        'guest_feedback_system',
                        $generated_password,
                        'Guest Feedback Submissions',
                        'System Generated'
                    ]);
                } catch (PDOException $e) {
                    // Backward compatibility for older schemas without contact_info column.
                    $create_user_stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status)
                                                       VALUES (?, ?, ?, 'motorist', 'active')");
                    $create_user_stmt->execute([
                        'guest_feedback_system',
                        $generated_password,
                        'Guest Feedback Submissions'
                    ]);
                }

                $system_user_stmt->execute(['guest_feedback_system']);
                $system_user = $system_user_stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$system_user) {
                // Final fallback in case guest user creation is blocked.
                $fallback_user_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'motorist' ORDER BY id ASC LIMIT 1");
                $fallback_user_stmt->execute();
                $system_user = $fallback_user_stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$system_user) {
                throw new RuntimeException('Unable to route feedback right now. Please try again later.');
            }

            $insert_feedback_stmt = $pdo->prepare("INSERT INTO feedback_concerns (
                motorist_user_id, full_name, reference_number, violation_id, contact_info, concern_type, message, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $insert_feedback_stmt->execute([
                (int)$system_user['id'],
                $feedback_full_name,
                $feedback_top_number,
                (int)$matched_violation['id'],
                $feedback_contact_info,
                $feedback_concern_type,
                $feedback_message
            ]);

            $feedback_form_success = 'Successfully submitted. Your feedback has been forwarded to the Supervisor Portal for review.';
            $feedback_full_name = '';
            $feedback_top_number = '';
            $feedback_contact_info = '';
            $feedback_concern_type = 'Dispute';
            $feedback_message = '';
        } catch (Throwable $e) {
            $feedback_form_errors[] = 'Unable to submit feedback right now. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['motorist_signup'])) {
    $motorist_signup_full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $motorist_signup_username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $motorist_signup_plate = normalize_plate_number((string)($_POST['plate'] ?? ''));
    $signup_password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $signup_confirm_password = isset($_POST['confirm_password']) ? trim($_POST['confirm_password']) : '';

    $motorist_auth_mode = 'signup';
    if ($motorist_signup_full_name === '' || $motorist_signup_username === '' || $motorist_signup_plate === '' || $signup_password === '' || $signup_confirm_password === '') {
        $motorist_signup_error = 'All fields are required.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9 -]{1,13}[A-Z0-9]$/', $motorist_signup_plate)) {
        $motorist_signup_error = 'Plate number format is invalid.';
    } elseif ($signup_password !== $signup_confirm_password) {
        $motorist_signup_error = 'Passwords do not match.';
    } elseif (strlen($signup_password) < 6) {
        $motorist_signup_error = 'Password must be at least 6 characters long.';
    } else {
        try {
            $pdo->beginTransaction();
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check_stmt->execute([$motorist_signup_username]);
            if ($check_stmt->fetch()) {
                $motorist_signup_error = 'Username already exists.';
            } else {
                $plate_check_stmt = $pdo->prepare("SELECT id FROM motorists WHERE UPPER(TRIM(COALESCE(plate, ''))) = ? LIMIT 1");
                $plate_check_stmt->execute([$motorist_signup_plate]);
                if ($plate_check_stmt->fetch()) {
                    $motorist_signup_error = 'Plate number is already registered.';
                } else {
                $hashed_password = password_hash($signup_password, PASSWORD_DEFAULT);
                $insert_stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, 'motorist', 'active')");
                $insert_stmt->execute([$motorist_signup_username, $hashed_password, $motorist_signup_full_name]);
                $new_user_id = (int)$pdo->lastInsertId();
                $generated_license_number = 'TEMP-' . str_pad((string)$new_user_id, 6, '0', STR_PAD_LEFT);
                $motorist_insert_stmt = $pdo->prepare("INSERT INTO motorists (user_id, license_number, full_name, plate) VALUES (?, ?, ?, ?)");
                $motorist_insert_stmt->execute([$new_user_id, $generated_license_number, $motorist_signup_full_name, $motorist_signup_plate]);
                $motorist_signup_success = 'Registration successful! You can now log in.';
                $motorist_auth_mode = 'signin';
                $motorist_signup_full_name = '';
                $motorist_signup_username = '';
                $motorist_signup_plate = '';
                }
            }
            if ($motorist_signup_error === '') {
                $pdo->commit();
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $db_error_message = strtolower((string)$e->getMessage());
            if (strpos($db_error_message, 'license_number') !== false && (strpos($db_error_message, 'duplicate') !== false || strpos($db_error_message, 'unique') !== false)) {
                $motorist_signup_error = 'Registration failed due to duplicate temporary license value. Please try again.';
            } else {
                $motorist_signup_error = 'Registration failed. Please try again.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['motorist_search']) && !isset($_POST['submit_feedback_home']) && !isset($_POST['motorist_signup'])) {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';

    try {
        validate_csrf_or_throw($_POST['csrf_token'] ?? '');
        if (login($username, $password, $role)) {
            if (!empty($_SESSION['must_change_password'])) {
                header("Location: force_password_change.php");
                exit();
            }

            switch ($_SESSION['role']) {
                case 'enforcer':
                    header("Location: enforcer/dashboard.php");
                    break;
                case 'supervisor':
                    header("Location: supervisor/dashboard.php");
                    break;
                case 'treasurer':
                    header("Location: treasurer/dashboard.php");
                    break;
                case 'motorist':
                    header("Location: motorist/dashboard.php");
                    break;
                case 'pnp_officer':
                    header("Location: pnp_officer/dashboard.php");
                    break;
            }
            exit();
        }
        
        $error = 'Invalid username or password';
    } catch (Throwable $e) {
        app_log('warning', 'public.login', 'Public login request failed.', $e->getMessage());
        $error = $e->getMessage();
    }
}

if (isset($_GET['maintenance']) && isset($_SESSION['maintenance_notice'])) {
    $maintenance_notice = (string)$_SESSION['maintenance_notice'];
    unset($_SESSION['maintenance_notice']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $show_login ? 'Login' : ($show_public_welcome ? 'Welcome' : 'Get Started'); ?> - Traffic Management System</title>
    <link rel="stylesheet" href="style.css?v=20260427">
    <script src="theme.js" defer></script>
    <style>
        .portal-page {
                    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                }

                /* Login page hard override to prevent collapsed layout */
                body.login-page {
                    min-height: 100vh;
                    margin: 0;
                    padding: 16px;
                    background: radial-gradient(circle at 20% 20%, #d9e7ff 0%, #c8dbff 24%, #eef3fb 100%);
                }

                body.login-page .auth-shell {
                    width: min(1240px, 100%);
                    min-height: min(740px, calc(100vh - 32px));
                    margin: 0 auto;
                    display: grid;
                    grid-template-columns: minmax(0, 1.08fr) minmax(430px, 0.92fr);
                    background: #ffffff;
                    border: 1px solid #dbe5f5;
                    border-radius: 22px;
                    box-shadow: 0 24px 55px rgba(20, 47, 101, 0.2);
                    overflow: hidden;
                }

                body.login-page .auth-showcase {
                    padding: 2.2rem 2.5rem;
                    background:
                        linear-gradient(135deg, rgba(28, 88, 176, 0.58) 0%, rgba(76, 146, 233, 0.52) 100%),
                        url('assets_login_bg.php') center center / cover no-repeat;
                }
                body.login-page .auth-showcase .auth-back-link {
                    position: absolute;
                    top: 1rem;
                    left: 1.3rem;
                    z-index: 2;
                    display: inline-flex;
                    align-items: center;
                    padding: 0.35rem 0.6rem;
                    border-radius: 999px;
                    background: rgba(8, 24, 58, 0.36);
                    border: 1px solid rgba(255, 255, 255, 0.25);
                    text-shadow: none;
                }
                html[data-theme="dark"] body.login-page .auth-showcase {
                    background:
                        linear-gradient(135deg, rgba(9, 35, 86, 0.72) 0%, rgba(29, 86, 168, 0.66) 100%),
                        url('assets_login_bg.php') center center / cover no-repeat;
                }
                html[data-theme="dark"] body.login-page .auth-card .form-group input[type="text"],
                html[data-theme="dark"] body.login-page .auth-card .form-group input[type="password"] {
                    background: #0f1a2c;
                    border-color: #3a527d;
                    color: #eaf2ff;
                }
                html[data-theme="dark"] body.login-page .auth-card .form-group input[type="text"]::placeholder,
                html[data-theme="dark"] body.login-page .auth-card .form-group input[type="password"]::placeholder {
                    color: #95abd0;
                    opacity: 1;
                }
                html[data-theme="dark"] body.login-page .motorist-auth-form .form-group label,
                html[data-theme="dark"] body.login-page .auth-card .form-group label {
                    color: #ccdaf3;
                }
                html[data-theme="dark"] body.login-page .motorist-auth-form-side .auth-header p,
                html[data-theme="dark"] body.login-page .motorist-auth-inline-note p,
                html[data-theme="dark"] body.login-page .motorist-auth-switch {
                    color: #a9bddf;
                }
                html[data-theme="dark"] body.login-page .auth-card .form-group input:-webkit-autofill,
                html[data-theme="dark"] body.login-page .auth-card .form-group input:-webkit-autofill:hover,
                html[data-theme="dark"] body.login-page .auth-card .form-group input:-webkit-autofill:focus {
                    -webkit-text-fill-color: #eaf2ff;
                    -webkit-box-shadow: 0 0 0px 1000px #0f1a2c inset;
                    transition: background-color 5000s ease-in-out 0s;
                }
                body.login-page .auth-showcase,
                body.login-page .auth-showcase h1,
                body.login-page .auth-showcase h2,
                body.login-page .auth-showcase h3,
                body.login-page .auth-showcase p,
                body.login-page .auth-showcase .auth-intro,
                body.login-page .auth-showcase .back-link {
                    color: #f2f7ff;
                    text-shadow: 0 3px 12px rgba(7, 18, 42, 0.45);
                }

                body.login-page .auth-panel {
                    background: linear-gradient(180deg, #ffffff 0%, #f7f9fd 100%);
                    padding: 1.15rem 1.2rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                body.login-page .auth-card {
                    max-width: 500px;
                    width: 100%;
                    border-radius: 18px;
                    border: 1px solid #e5eaf4;
                    box-shadow: 0 12px 34px rgba(16, 46, 107, 0.12);
                    background: linear-gradient(180deg, #ffffff 0%, #fcfdff 100%);
                }
                body.login-page .auth-card .form-group input[type="text"],
                body.login-page .auth-card .form-group input[type="password"] {
                    min-height: 44px;
                    padding: 0.78rem 0.86rem;
                    font-size: 0.96rem;
                }
                body.login-page .auth-card .auth-submit {
                    min-height: 44px;
                    font-size: 0.98rem;
                    font-weight: 700;
                }

                body.login-page .motorist-auth-card {
                    max-width: 820px;
                    border-radius: 16px;
                    overflow: hidden;
                    display: block;
                    background: #ffffff;
                    border: 1px solid #e2e8f6;
                    box-shadow: 0 14px 32px rgba(23, 46, 96, 0.14);
                }

                .motorist-auth-form-side {
                    padding: 1.35rem 1.5rem 1.1rem;
                    max-width: 460px;
                    margin: 0 auto;
                }

                .motorist-auth-form-side .auth-header {
                    text-align: center;
                    margin-bottom: 0.8rem;
                }

                .motorist-auth-form-side .auth-header h2 {
                    margin-bottom: 0.3rem;
                    font-size: 1.75rem;
                }

                .motorist-auth-form-side .auth-header p {
                    color: #627296;
                    font-size: 0.9rem;
                }

                .motorist-auth-form-side .auth-form {
                    display: grid;
                    gap: 0.52rem;
                }

                .motorist-auth-form .form-group {
                    margin-bottom: 0.2rem;
                }

                .motorist-auth-form .form-group label {
                    display: block;
                    font-size: 0.88rem;
                    font-weight: 600;
                    color: #334a76;
                    margin-bottom: 0.35rem;
                }

                .motorist-auth-form .form-group input[type="text"],
                .motorist-auth-form .form-group input[type="password"] {
                    background: #f5f7fc;
                    border: 1px solid #e2e8f6;
                    border-radius: 10px;
                    padding: 0.74rem 0.82rem;
                    width: 100%;
                    box-sizing: border-box;
                    font-size: 0.95rem;
                }

                .motorist-auth-form-side .auth-submit {
                    width: 100%;
                    border-radius: 999px;
                    margin-top: 0.3rem;
                }

                .motorist-auth-inline-note {
                    margin-top: 0.55rem;
                    text-align: center;
                }

                .motorist-auth-inline-note p {
                    margin: 0.28rem 0;
                    color: #5f6e8f;
                    font-size: 0.92rem;
                }

                .motorist-signup-btn {
                    color: #1f5fd3;
                    text-decoration: none;
                    font-weight: 700;
                }

                .motorist-auth-switch {
                    margin-top: 0.18rem;
                    text-align: center;
                    font-size: 0.92rem;
                    color: #5f6e8f;
                }

                .motorist-auth-switch a {
                    color: #1f5fd3;
                    font-weight: 700;
                    text-decoration: none;
                }

                @media (max-width: 980px) {
                    body.login-page {
                        padding: 0;
                        background: #eef2f9;
                    }

                    body.login-page .auth-shell {
                        width: 100%;
                        min-height: 100vh;
                        border: none;
                        border-radius: 0;
                        box-shadow: none;
                        grid-template-columns: 1fr;
                    }

                    body.login-page .auth-showcase {
                        padding: 4.2rem 1.4rem 2.2rem;
                    }
                    body.login-page .auth-showcase .auth-back-link {
                        top: 0.8rem;
                        left: 0.9rem;
                    }

                    body.login-page .motorist-auth-card {
                        grid-template-columns: 1fr;
                    }
                }

                body.landing-page {
                    min-height: 100vh;
                    margin: 0;
                    background:
                        linear-gradient(rgba(14, 26, 52, 0.58), rgba(14, 26, 52, 0.58)),
                        url('assets/images/pototan-hall-wide.png') center center / cover no-repeat;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 1.2rem;
                }
                html[data-theme="dark"] body.landing-page {
                    background:
                        linear-gradient(rgba(10, 18, 36, 0.68), rgba(10, 18, 36, 0.68)),
                        url('assets/images/pototan-hall-wide.png') center center / cover no-repeat;
                }

                .landing-shell {
                    width: min(1200px, 100%);
                    background: transparent;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: min(760px, calc(100vh - 2.4rem));
                }

                .landing-left {
                    padding: clamp(1.2rem, 4vw, 2rem);
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    text-align: center;
                    max-width: 820px;
                    background: transparent;
                    border-radius: 0;
                    box-shadow: none;
                    backdrop-filter: none;
                }

                .landing-nav {
                    display: none;
                }

                .landing-logo {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.55rem;
                    font-weight: 700;
                    color: #1d2334;
                    font-size: 0.95rem;
                }

                .landing-logo-dot {
                    width: 14px;
                    height: 14px;
                    border-radius: 50%;
                    background: #f6bf2f;
                    box-shadow: 12px 0 0 #f6bf2f;
                }

                .landing-nav-links {
                    display: inline-flex;
                    gap: 1rem;
                    color: #606b82;
                    font-size: 0.92rem;
                }

                .landing-heading {
                    margin: 0;
                    color: #ffffff;
                    line-height: 1.1;
                    font-size: clamp(2rem, 5vw, 4rem);
                    max-width: 720px;
                    text-shadow: 0 10px 26px rgba(7, 15, 34, 0.5);
                    opacity: 0;
                    transform: translateY(20px);
                    animation: landingFadeUp 700ms ease-out 120ms forwards;
                }

                .landing-copy {
                    margin: 1rem 0 0;
                    max-width: 700px;
                    color: #eef4ff;
                    line-height: 1.6;
                    font-size: 1.03rem;
                    text-shadow: 0 8px 20px rgba(7, 15, 34, 0.45);
                    opacity: 0;
                    transform: translateY(18px);
                    animation: landingFadeUp 720ms ease-out 280ms forwards;
                }

                .landing-cta-wrap {
                    margin-top: 1.8rem;
                    display: inline-block;
                    padding: 0;
                }

                .landing-cta {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 220px;
                    padding: 0.9rem 1.4rem;
                    border-radius: 999px;
                    background: linear-gradient(90deg, #6c4df6 0%, #5a35ea 100%);
                    color: #ffffff;
                    text-decoration: none;
                    font-weight: 700;
                    opacity: 0;
                    transform: translateY(16px) scale(0.98);
                    animation: landingFadeUp 720ms ease-out 440ms forwards, landingPulse 3.5s ease-in-out 1400ms infinite;
                }

                body.landing-page {
                    animation: landingBackgroundDrift 16s ease-in-out infinite alternate;
                }

                @keyframes landingFadeUp {
                    from {
                        opacity: 0;
                        transform: translateY(22px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                @keyframes landingPulse {
                    0%, 100% {
                        box-shadow: 0 6px 20px rgba(73, 51, 190, 0.35);
                    }
                    50% {
                        box-shadow: 0 10px 30px rgba(90, 53, 234, 0.5);
                    }
                }

                @keyframes landingBackgroundDrift {
                    from {
                        background-position: center center;
                    }
                    to {
                        background-position: center 44%;
                    }
                }

                .landing-right {
                    position: relative;
                    background:
                        radial-gradient(circle at 72% 34%, rgba(255, 255, 255, 0.42) 0%, rgba(255, 255, 255, 0) 43%),
                        linear-gradient(155deg, #e2b6f3 0%, #bda9f2 45%, #a8a0f2 100%);
                    display: flex;
                    align-items: flex-end;
                    justify-content: center;
                    padding: 2.2rem;
                }

                .landing-photo-card {
                    width: min(520px, 100%);
                    background: #ffffff;
                    border-radius: 28px 28px 0 0;
                    box-shadow: 0 18px 45px rgba(67, 55, 129, 0.28);
                    padding: 2rem 1.6rem;
                    text-align: center;
                }

                .landing-photo-card img {
                    width: min(300px, 72%);
                    height: auto;
                    object-fit: contain;
                }

                .landing-photo-title {
                    margin: 0.9rem 0 0;
                    color: #2b3150;
                    font-weight: 700;
                }

                .landing-dot {
                    position: absolute;
                    border-radius: 50%;
                }

                .landing-dot.yellow {
                    width: 72px;
                    height: 72px;
                    top: 80px;
                    left: 28px;
                    background: #f8bf2e;
                }

                .landing-dot.pink {
                    width: 58px;
                    height: 58px;
                    top: 280px;
                    left: 64px;
                    background: #f43f77;
                }

                body.landing-page .landing-right {
                    display: none;
                }

                @media (max-width: 980px) {
                    .landing-shell {
                        grid-template-columns: 1fr;
                    }

                    .landing-right {
                        min-height: 340px;
                    }

                    .landing-dot.yellow,
                    .landing-dot.pink {
                        display: none;
                    }
                }

                @media (prefers-reduced-motion: reduce) {
                    body.landing-page {
                        animation: none;
                    }
                    .landing-heading,
                    .landing-copy,
                    .landing-cta {
                        animation: none;
                        opacity: 1;
                        transform: none;
                    }
                }

    </style>

</head>
<body class="<?php echo $show_login ? 'login-page' : (($show_roles || $show_public_welcome) ? 'portal-page' : 'landing-page'); ?>">
    <?php if (!$show_roles && !$show_public_welcome): ?>
        <div class="landing-shell">
            <section class="landing-left">
                <h1 class="landing-heading">Real-Time Traffic Violation and Penalty Management with Data Analytics</h1>
                <p class="landing-copy">
                    We are glad to have you here. Access traffic violation services, check records,
                    and continue securely through our official digital platform.
                </p>
                <div class="landing-cta-wrap">
                    <a href="index.php?roles=1" class="landing-cta">Get started</a>
                </div>
            </section>
            <section class="landing-right">
                <span class="landing-dot yellow"></span>
                <span class="landing-dot pink"></span>
                <div class="landing-photo-card">
                    <img
                        src="assets/images/pototan-logo-no-bg.png"
                        alt="Municipal Traffic Management Office Logo"
                        onerror="this.style.display='none';"
                    >
                    <p class="landing-photo-title">Municipal Traffic Management Office</p>
                </div>
            </section>
        </div>
    <?php elseif (!$show_roles): ?>
        <!-- Welcome Page -->
        <div class="welcome-page-shell">
            <header class="welcome-topbar">
                <div class="welcome-brand">
                    <span class="welcome-brand-fallback">MTMO</span>
                    <img
                        src="assets/images/pototan-logo-no-bg.png"
                        alt="Municipal Traffic Management Office Logo"
                        class="welcome-brand-logo"
                        onerror="this.style.display='none';"
                    >
                    <span>Municipal Traffic Management Office</span>
                </div>

                <a href="index.php?roles=1" class="btn btn-primary top-login-btn">Login</a>
            </header>
            <section class="welcome-hero">
                <span id="home"></span>
                <p class="welcome-kicker">Municipal Government System</p>
                <h1>Real-Time Traffic Violation and Penalty Management</h1>
                <p class="welcome-subtitle">
                    Streamline traffic enforcement, case validation, and payment workflows
                    with a secure and centralized digital platform.
                </p>

                <div class="motorist-lookup-card">
                    <h3>Motorist Violation Lookup</h3>
                    <p>Search using plate number, motorist name, license number, or TOP number.</p>
                    <form method="POST" action="index.php?view=welcome" class="motorist-lookup-form">
                        <input type="text" id="search_query" name="search_query" value="<?php echo htmlspecialchars($motorist_search_query); ?>" placeholder="Enter plate number, name, license number, or TOP number" required>
                        <button type="submit" name="motorist_search" class="btn btn-primary">Search</button>
                    </form>

                    <?php if ($motorist_search_performed): ?>
                        <div class="motorist-lookup-results" id="motorist-lookup-results">
                            <?php if (empty($motorist_search_results)): ?>
                                <div class="lookup-empty-state">No violations found for your search.</div>
                            <?php else: ?>
                                <table class="lookup-table">
                                    <thead>
                                        <tr>
                                            <th>TOP #</th>
                                            <th>Violation</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($motorist_search_results as $result): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($result['top_number']); ?></td>
                                                <td><?php echo htmlspecialchars($result['violation_display']); ?></td>
                                                <td><?php echo !empty($result['violation_date']) ? htmlspecialchars(date('M d, Y', strtotime($result['violation_date']))) : 'N/A'; ?></td>
                                                <td>₱<?php echo number_format((float)$result['fine_amount'], 2); ?></td>
                                                <td><span class="badge badge-<?php echo htmlspecialchars($result['status']); ?>"><?php echo ucfirst($result['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="welcome-announcements" id="about">
                    <h3>Announcements</h3>
                    <?php if (empty($welcome_announcements)): ?>
                        <div class="welcome-article-empty">No announcements available at the moment.</div>
                    <?php else: ?>
                        <div class="welcome-announcement-list">
                            <?php foreach ($welcome_announcements as $announcement): ?>
                                <article class="welcome-announcement-item">
                                    <h4><?php echo htmlspecialchars($announcement['title']); ?></h4>
                                    <p class="welcome-article-date">
                                        <?php echo date('M d, Y', strtotime($announcement['posted_at'])); ?>
                                    </p>
                                    <?php if (!empty($announcement['image_path'])): ?>
                                        <img
                                            src="uploads/<?php echo htmlspecialchars($announcement['image_path']); ?>"
                                            alt="Announcement image"
                                            class="welcome-announcement-image"
                                        >
                                    <?php endif; ?>
                                    <p><?php echo htmlspecialchars($announcement['content']); ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="welcome-videos" id="contact">
                    <h3>Tutorial Videos</h3>
                    <p>Use these quick guides to complete your settlement correctly and faster.</p>
                    <?php if (empty($welcome_tutorial_videos)): ?>
                        <div class="welcome-article-empty">No tutorial videos available at the moment.</div>
                    <?php else: ?>
                        <div class="welcome-videos-grid">
                            <?php foreach ($welcome_tutorial_videos as $video): ?>
                                <article class="welcome-video-card">
                                    <h4><?php echo htmlspecialchars($video['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($video['description']); ?></p>
                                    <?php if (!empty($video['file_path'])): ?>
                                        <video class="welcome-video-player" controls preload="metadata">
                                            <source src="uploads/<?php echo htmlspecialchars($video['file_path']); ?>">
                                            Your browser does not support HTML5 video.
                                        </video>
                                    <?php elseif (!empty($video['url'])): ?>
                                        <a href="<?php echo htmlspecialchars($video['url']); ?>" target="_blank" rel="noopener noreferrer" class="welcome-video-link">Watch Tutorial</a>
                                    <?php else: ?>
                                        <span class="welcome-video-link">No video file</span>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="welcome-articles">
                    <h3>Articles</h3>
                    <div class="welcome-articles-grid">
                        <?php if (empty($welcome_articles)): ?>
                            <article class="welcome-article-card">
                                <h4>No Articles Available</h4>
                                <p class="welcome-article-date">Help Center</p>
                                <p>Additional tutorials and guides will be posted here soon.</p>
                            </article>
                        <?php else: ?>
                            <?php foreach ($welcome_articles as $article): ?>
                                <article class="welcome-article-card">
                                    <h4><?php echo htmlspecialchars($article['title']); ?></h4>
                                    <p class="welcome-article-date">
                                        Published on <?php echo date('M d, Y', strtotime($article['published_at'])); ?>
                                    </p>
                                    <p>
                                        <?php
                                        $preview = strlen($article['content']) > 260 ? substr($article['content'], 0, 260) . '...' : $article['content'];
                                        echo nl2br(htmlspecialchars($preview));
                                        ?>
                                    </p>
                                    <?php
                                    $ext_link = trim((string)($article['link_url'] ?? ''));
                                    $pdf_path = trim((string)($article['attachment_path'] ?? ''));
                                    ?>
                                    <?php if ($ext_link !== '' || $pdf_path !== ''): ?>
                                        <p class="welcome-article-actions">
                                            <?php if ($ext_link !== ''): ?>
                                                <a class="welcome-article-link" href="<?php echo htmlspecialchars($ext_link); ?>" target="_blank" rel="noopener noreferrer">Open link</a>
                                            <?php endif; ?>
                                            <?php if ($pdf_path !== ''): ?>
                                                <a class="welcome-article-link" href="uploads/<?php echo htmlspecialchars($pdf_path); ?>" target="_blank" rel="noopener noreferrer">View PDF</a>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="motorist-lookup-card" id="feedback-form">
                    <h3>Feedback Form (Reklamo / Concern)</h3>
                    <p>Submit your complaint or concern about a recorded traffic violation. Sign in as Motorist to complete submission.</p>
                    <?php if ($feedback_form_success !== ''): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($feedback_form_success); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($feedback_form_errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($feedback_form_errors as $feedback_error): ?>
                                <div><?php echo htmlspecialchars($feedback_error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="index.php?view=welcome#feedback-form" class="motorist-lookup-form">
                        <input type="text" name="feedback_full_name" placeholder="Full Name" value="<?php echo htmlspecialchars($feedback_full_name); ?>" required>
                        <input type="text" name="feedback_top_number" placeholder="TOP NUMBER" value="<?php echo htmlspecialchars($feedback_top_number); ?>" required>
                        <input type="text" name="feedback_contact_info" placeholder="Contact Information" value="<?php echo htmlspecialchars($feedback_contact_info); ?>" required>
                        <select name="feedback_concern_type" required>
                            <option value="">Type of Concern</option>
                            <option value="Dispute" <?php echo $feedback_concern_type === 'Dispute' ? 'selected' : ''; ?>>Dispute</option>
                            <option value="Inquiry" <?php echo $feedback_concern_type === 'Inquiry' ? 'selected' : ''; ?>>Inquiry</option>
                            <option value="Complaint" <?php echo $feedback_concern_type === 'Complaint' ? 'selected' : ''; ?>>Complaint</option>
                        </select>
                        <textarea name="feedback_message" placeholder="Message / Description" rows="3" required><?php echo htmlspecialchars($feedback_message); ?></textarea>
                        <button type="submit" name="submit_feedback_home" class="btn btn-primary">Submit Feedback</button>
                    </form>
                </div>

                <footer class="welcome-footer">
                    <div class="welcome-footer-grid">
                        <section class="welcome-footer-col">
                            <div class="welcome-footer-brand">
                                <span class="welcome-footer-logo-fallback">MTMO</span>
                                <img
                                    src="assets/images/pototan-logo-no-bg.png"
                                    alt="Municipal Traffic Management Office Logo"
                                    class="welcome-footer-logo"
                                    onerror="this.style.display='none';"
                                >
                                <div>
                                    <h3>Municipal Traffic Management Office</h3>
                                    <p><?php echo htmlspecialchars($office_contact['address']); ?></p>
                                </div>
                            </div>
                            <p class="welcome-footer-text">
                                Real-Time Traffic Violation and Penalty Management System
                            </p>
                        </section>

                        <section class="welcome-footer-col" id="about">
                            <h4>About</h4>
                            <p class="welcome-footer-text">
                                A centralized platform for traffic enforcement, validation,
                                settlement support, and public violation lookup.
                            </p>
                        </section>

                        <section class="welcome-footer-col" id="contact">
                            <h4>Contact Info</h4>
                            <ul class="welcome-footer-list">
                                <li><?php echo htmlspecialchars($office_contact['office_name']); ?></li>
                                <li><?php echo htmlspecialchars($office_contact['address']); ?></li>
                                <li><?php echo htmlspecialchars($office_contact['phone']); ?></li>
                                <li><?php echo htmlspecialchars($office_contact['email']); ?></li>
                            </ul>
                        </section>
                    </div>
                    <div class="welcome-footer-bottom">
                        &copy; <?php echo date('Y'); ?> Municipal Traffic Management Office. All rights reserved.
                        <span style="margin-left:8px; font-size:0.85em; opacity:0.75;">| <a href="admin/index.php">Admin</a></span>
                    </div>
                </footer>
            </section>
        </div>
    <?php elseif (!$show_login): ?>
        <!-- Portal Selection Page -->
        <div class="portal-container">
            <a href="<?php echo $show_officer_role_picker ? 'index.php?roles=1' : 'index.php'; ?>" class="portal-back-link">← Back</a>
            <div class="portal-header">
                <h1>
                    <?php
                    if ($show_officer_role_picker) {
                        echo 'Choose Officer Role';
                    } else {
                        echo 'Choose Account Type';
                    }
                    ?>
                </h1>
                <p>
                    <?php
                    if ($show_officer_role_picker) {
                        echo 'Select your assigned role before signing in.';
                    } else {
                        echo 'Select how you want to sign in.';
                    }
                    ?>
                </p>
                <div class="portal-warning">
                    Use only your assigned account credentials.
                </div>
            </div>
            
            <div class="portal-grid<?php echo $show_officer_role_picker ? ' officer-role-grid' : ''; ?>">
                <?php if ($show_officer_role_picker): ?>
                    <a href="index.php?roles=1&role=supervisor" class="portal-card analyst-card">
                        <div class="portal-icon analyst-icon">📊</div>
                        <h2>Supervisor</h2>
                        <p>Monitor cases and review escalations</p>
                    </a>
                    <a href="index.php?roles=1&role=pnp_officer" class="portal-card supervisor-card">
                        <div class="portal-icon supervisor-icon">🛡️</div>
                        <h2>PNP Officer</h2>
                        <p>Validate documents and release vehicles</p>
                    </a>
                    <a href="index.php?roles=1&role=treasurer" class="portal-card treasurer-card">
                        <div class="portal-icon treasurer-icon">🏛️</div>
                        <h2>Municipal Treasurer</h2>
                        <p>Handle payment and settlement records</p>
                    </a>
                    <a href="index.php?roles=1&role=enforcer" class="portal-card enforcer-card">
                        <div class="portal-icon enforcer-icon">🚗</div>
                        <h2>Traffic Enforcer</h2>
                        <p>Issue tickets and record violations</p>
                    </a>
                <?php else: ?>
                    <!-- Motorist -->
                    <a href="index.php?roles=1&role=motorist" class="portal-card analyst-card">
                        <div class="portal-icon analyst-icon">🪪</div>
                        <h2>Motorist</h2>
                        <p>Sign in or create an account from the login page</p>
                    </a>
                    
                    <!-- Officer -->
                    <a href="index.php?roles=1&account=officer" class="portal-card enforcer-card">
                        <div class="portal-icon enforcer-icon">🚗</div>
                        <h2>Officer</h2>
                        <p>Sign in with your specific officer role</p>
                    </a>
                <?php endif; ?>
                
            </div>
            
            <div class="portal-footer">
                <p>&copy; 2025 Municipal Traffic Management Office. All rights reserved.</p>
            </div>
        </div>
    <?php else: ?>
        <!-- Login Page -->
        <div class="auth-shell">
            <section class="auth-showcase">
                <a href="<?php echo $selected_role === 'motorist' ? 'index.php?roles=1' : 'index.php?roles=1&account=officer'; ?>" class="back-link auth-back-link">← Back to Role Selection</a>
                <div class="auth-brand">
                    <span class="auth-brand-fallback">MTMO</span>
                    <img
                        src="assets/images/pototan-logo-no-bg.png"
                        alt="Municipal Traffic Management Office Logo"
                        class="auth-brand-logo"
                        onerror="this.style.display='none';"
                    >
                    <div>
                        <h1>Municipal Traffic Management Office</h1>
                        <p>Penalty Management System</p>
                    </div>
                </div>
                <h2>Welcome Back</h2>
                <p class="auth-intro">Manage records, monitor cases, and process violations from one secure platform.</p>

                <p class="auth-intro">
                    Real-time monitoring keeps users updated on active cases and status changes.
                    Streamlined workflows keep ticketing, validation, and reporting in one centralized system.
                </p>
            </section>

            <section class="auth-panel">
                    <div class="login-container auth-card<?php echo $selected_role === 'motorist' ? ' motorist-auth-card' : ''; ?>">
                        <div class="<?php echo $selected_role === 'motorist' ? 'motorist-auth-form-side' : ''; ?>">
                        <div class="login-header auth-header">
                            <div class="login-role-badge auth-role-badge">
                                <?php
                                $role_names = [
                                    'enforcer' => 'Traffic Enforcer',
                                    'pnp_officer' => 'PNP Officer',
                                    'supervisor' => 'Supervisor',
                                    'treasurer' => 'Municipal Treasurer',
                                    'motorist' => 'Motorist'
                                ];
                                    echo isset($role_names[$selected_role]) ? $role_names[$selected_role] : 'User';
                                ?>
                            </div>
                            <h2>
                                <?php
                                if ($selected_role === 'motorist' && $motorist_auth_mode === 'signup') {
                                    echo 'Create Account';
                                } else {
                                    echo 'Sign In';
                                }
                                ?>
                            </h2>
                            <p>
                                <?php
                                if ($selected_role === 'motorist' && $motorist_auth_mode === 'signup') {
                                    echo 'Complete the form to create your motorist account.';
                                } else {
                                    echo 'Enter your credentials to access your account.';
                                }
                                ?>
                            </p>
                        </div>

                        <?php if ($selected_role === 'motorist' && $motorist_auth_mode === 'signup'): ?>
                            <?php if ($motorist_signup_error !== ''): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($motorist_signup_error); ?></div>
                            <?php endif; ?>
                            <form method="POST" action="" class="auth-form motorist-auth-form">
                                <input type="hidden" name="motorist_signup" value="1">
                                <div class="form-group">
                                    <label for="signup_full_name">Full Name</label>
                                    <input type="text" id="signup_full_name" name="full_name" value="<?php echo htmlspecialchars($motorist_signup_full_name); ?>" placeholder="Enter full name" required autofocus>
                                </div>
                                <div class="form-group">
                                    <label for="signup_username">Username</label>
                                    <input type="text" id="signup_username" name="username" value="<?php echo htmlspecialchars($motorist_signup_username); ?>" placeholder="Choose username" required>
                                </div>
                                <div class="form-group">
                                    <label for="signup_plate">Plate Number</label>
                                    <input type="text" id="signup_plate" name="plate" value="<?php echo htmlspecialchars($motorist_signup_plate); ?>" placeholder="e.g. ABC 1234" pattern="[A-Za-z0-9][A-Za-z0-9 -]{1,13}[A-Za-z0-9]" title="Use letters, numbers, spaces, or dash (3-15 chars)." required>
                                </div>
                                <div class="form-group">
                                    <label for="signup_password">Password</label>
                                    <input type="password" id="signup_password" name="password" placeholder="Create password" required>
                                </div>
                                <div class="form-group">
                                    <label for="signup_confirm_password">Confirm Password</label>
                                    <input type="password" id="signup_confirm_password" name="confirm_password" placeholder="Confirm password" required>
                                </div>
                                <button type="submit" class="btn btn-primary auth-submit">Sign Up</button>
                            </form>
                            <div class="motorist-auth-switch">
                                Already have an account? <a href="index.php?roles=1&role=motorist">Sign in</a>
                            </div>
                        <?php else: ?>
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?php echo $error; ?></div>
                            <?php endif; ?>
                            <?php if ($auth_notice !== ''): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($auth_notice); ?></div>
                            <?php endif; ?>
                            <?php if ($maintenance_notice !== ''): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($maintenance_notice); ?></div>
                            <?php endif; ?>
                            <?php if ($selected_role === 'motorist' && $motorist_signup_success !== ''): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($motorist_signup_success); ?></div>
                            <?php endif; ?>
                            <form method="POST" action="" class="auth-form<?php echo $selected_role === 'motorist' ? ' motorist-auth-form' : ''; ?>">
                                <input type="hidden" name="role" value="<?php echo htmlspecialchars($selected_role); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" name="username" placeholder="Enter username" required autofocus>
                                </div>
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input type="password" id="password" name="password" placeholder="Enter password" required>
                                </div>
                                <label class="remember-wrap">
                                    <input type="checkbox" name="remember_me">
                                    <span>Remember me</span>
                                </label>
                                <button type="submit" class="btn btn-primary auth-submit">Sign In</button>
                            </form>
                            <?php if ($selected_role === 'motorist'): ?>
                                <div class="motorist-auth-inline-note">
                                    <p>If you do not have an account, <a href="index.php?roles=1&role=motorist&auth=signup" class="motorist-signup-btn">Sign up here</a>.</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="login-footer auth-footer">
                            <p>&copy; 2026 Traffic Management Unit. All rights reserved.</p>
                        </div>
                    </div>
                    </div>
            </section>
        </div>
    <?php endif; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search_query');
            const resultsPanel = document.getElementById('motorist-lookup-results');
            if (!searchInput || !resultsPanel) {
                return;
            }

            searchInput.addEventListener('input', function() {
                if (searchInput.value.trim() === '') {
                    resultsPanel.style.display = 'none';
                } else {
                    resultsPanel.style.display = '';
                }
            });
        });
    </script>
</body>
</html>
