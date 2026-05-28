<?php
require_once '../auth.php';
check_role('motorist');

global $pdo;
$conn = $pdo;

$user_id = (int)($_SESSION['user_id'] ?? 0);
$full_name = (string)($_SESSION['full_name'] ?? 'Motorist User');
$username = (string)($_SESSION['username'] ?? 'N/A');
$edit_success = '';
$edit_error = '';
$profile_photo = '';
$should_open_edit_modal = false;

function normalize_plate_number(string $plate): string {
    $plate = strtoupper(trim($plate));
    $plate = preg_replace('/\s+/', ' ', $plate);
    return $plate ?? '';
}

function store_profile_photo(PDO $conn, int $user_id, array $file): string {
    $file_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($file_error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Failed to upload photo.');
    }

    $tmp_path = (string)($file['tmp_name'] ?? '');
    $original_name = (string)($file['name'] ?? '');
    $file_size = (int)($file['size'] ?? 0);
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed_ext, true)) {
        throw new RuntimeException('Profile photo must be JPG, PNG, or WEBP.');
    }
    if ($file_size > 2 * 1024 * 1024) {
        throw new RuntimeException('Profile photo must be 2MB or less.');
    }

    $upload_root = realpath(__DIR__ . '/../uploads');
    if ($upload_root === false) {
        $upload_root = __DIR__ . '/../uploads';
        if (!is_dir($upload_root)) {
            mkdir($upload_root, 0777, true);
        }
    }
    $profile_dir = rtrim($upload_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'profile_photos';
    if (!is_dir($profile_dir)) {
        mkdir($profile_dir, 0777, true);
    }

    $file_name = 'motorist_' . $user_id . '_' . time() . '.' . $ext;
    $dest_path = $profile_dir . DIRECTORY_SEPARATOR . $file_name;
    if (!move_uploaded_file($tmp_path, $dest_path)) {
        throw new RuntimeException('Unable to save uploaded photo.');
    }

    return 'profile_photos/' . $file_name;
}

try {
    $db_driver = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($db_driver === 'mysql') {
        try {
            $conn->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT ''");
        } catch (Throwable $e) {
        }
    } else {
        try {
            $columns = $conn->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
            $has_profile_photo = false;
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'profile_photo') {
                    $has_profile_photo = true;
                    break;
                }
            }
            if (!$has_profile_photo) {
                $conn->exec("ALTER TABLE users ADD COLUMN profile_photo TEXT DEFAULT ''");
            }
        } catch (Throwable $e) {
        }
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile_photo'])) {
    try {
        if (!isset($_FILES['profile_photo']) || !is_array($_FILES['profile_photo']) || (int)($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Please choose a photo to upload.');
        }
        $relative_path = store_profile_photo($conn, $user_id, $_FILES['profile_photo']);
        $update_photo_stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
        $update_photo_stmt->execute([$relative_path, $user_id]);
        $_SESSION['profile_photo'] = $relative_path;
        $profile_photo = $relative_path;
        $edit_success = 'Profile photo updated successfully.';
    } catch (Throwable $e) {
        $edit_error = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update profile photo.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $should_open_edit_modal = true;
    $new_full_name = trim((string)($_POST['full_name'] ?? ''));
    $new_contact = trim((string)($_POST['contact_number'] ?? ''));
    $new_address = trim((string)($_POST['address'] ?? ''));
    $new_plate = normalize_plate_number((string)($_POST['plate'] ?? ''));
    $new_license = trim((string)($_POST['license_number'] ?? ''));

    if ($new_full_name === '') {
        $edit_error = 'Full name is required.';
    } elseif ($new_plate === '') {
        $edit_error = 'Plate number is required.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9 -]{1,13}[A-Z0-9]$/', $new_plate)) {
        $edit_error = 'Plate number format is invalid.';
    } elseif ($new_license === '') {
        $edit_error = 'License number is required.';
    } else {
        try {
            $conn->beginTransaction();

            $plate_check_stmt = $conn->prepare("SELECT id FROM motorists WHERE UPPER(TRIM(COALESCE(plate, ''))) = ? AND user_id <> ? LIMIT 1");
            $plate_check_stmt->execute([$new_plate, $user_id]);
            if ($plate_check_stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Plate number is already registered to another account.');
            }

            $update_user_stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            $update_user_stmt->execute([$new_full_name, $user_id]);

            $profile_stmt = $conn->prepare("SELECT id FROM motorists WHERE user_id = ? LIMIT 1");
            $profile_stmt->execute([$user_id]);
            $existing_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_profile) {
                $update_motorist_stmt = $conn->prepare("UPDATE motorists
                                                        SET full_name = ?, contact_number = ?, address = ?, plate = ?, license_number = ?
                                                        WHERE user_id = ?");
                $update_motorist_stmt->execute([$new_full_name, $new_contact, $new_address, $new_plate, $new_license, $user_id]);
            } else {
                $insert_motorist_stmt = $conn->prepare("INSERT INTO motorists (user_id, full_name, contact_number, address, plate, license_number)
                                                        VALUES (?, ?, ?, ?, ?, ?)");
                $insert_motorist_stmt->execute([$user_id, $new_full_name, $new_contact, $new_address, $new_plate, $new_license]);
            }

            if (isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo']) && (int)($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $relative_path = store_profile_photo($conn, $user_id, $_FILES['profile_photo']);
                $update_photo_stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                $update_photo_stmt->execute([$relative_path, $user_id]);
                $_SESSION['profile_photo'] = $relative_path;
            }

            $conn->commit();
            $_SESSION['full_name'] = $new_full_name;
            $full_name = $new_full_name;
            $edit_success = 'Profile updated successfully.';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $edit_error = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update profile.';
        }
    }
}

$motorist_profile = null;
$violations = [];
$offense_by_status = [];
$offense_by_type = [];
$total_violations = 0;

try {
    $profile_stmt = $conn->prepare("SELECT m.id, m.license_number, m.full_name, m.address, m.contact_number, m.plate,
                                           COALESCE(u.profile_photo, '') AS profile_photo
                                    FROM users u
                                    LEFT JOIN motorists m ON m.user_id = u.id
                                    WHERE u.id = ?
                                    LIMIT 1");
    $profile_stmt->execute([$user_id]);
    $motorist_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
    $profile_photo = trim((string)($motorist_profile['profile_photo'] ?? ($_SESSION['profile_photo'] ?? '')));
    $_SESSION['profile_photo'] = $profile_photo;

    if ($motorist_profile && !empty($motorist_profile['id'])) {
        $violations_stmt = $conn->prepare("SELECT v.top_number, v.violation_date, v.location, v.fine_amount, v.status,
                                                  COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') AS violation_display
                                           FROM violations v
                                           LEFT JOIN penalties p ON v.penalty_id = p.id
                                           WHERE v.motorist_id = ?
                                           ORDER BY v.violation_date DESC");
        $violations_stmt->execute([(int)$motorist_profile['id']]);
        $violations = $violations_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $fallback_stmt = $conn->prepare("SELECT v.top_number, v.violation_date, v.location, v.fine_amount, v.status,
                                                COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') AS violation_display
                                         FROM violations v
                                         INNER JOIN motorists m ON v.motorist_id = m.id
                                         LEFT JOIN penalties p ON v.penalty_id = p.id
                                         WHERE m.user_id = ?
                                         ORDER BY v.violation_date DESC");
        $fallback_stmt->execute([$user_id]);
        $violations = $fallback_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $violations = [];
}

$total_violations = count($violations);
foreach ($violations as $violation) {
    $status = strtolower((string)($violation['status'] ?? 'pending'));
    $offense_by_status[$status] = ($offense_by_status[$status] ?? 0) + 1;

    $type = trim((string)($violation['violation_display'] ?? 'Multiple/Custom'));
    $offense_by_type[$type] = ($offense_by_type[$type] ?? 0) + 1;
}
arsort($offense_by_type);
$paid_count = (int)($offense_by_status['paid'] ?? 0);
$pending_count = (int)($offense_by_status['pending'] ?? 0);
$validated_count = (int)($offense_by_status['validated'] ?? 0);
$top_offense_count = 0;
if (!empty($offense_by_type)) {
    $top_offense_count = (int)reset($offense_by_type);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motorist Account</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="../theme.js" defer></script>
    <style>
        body {
            margin: 0;
            background:
                radial-gradient(circle at top right, rgba(88, 131, 255, 0.14), transparent 42%),
                linear-gradient(180deg, #f5f8ff 0%, #eef2fb 100%);
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #12284d;
        }
        .account-topnav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.8rem;
            padding: 1rem 1.25rem;
            background: rgba(255, 255, 255, 0.84);
            border-bottom: 1px solid #dfe8fb;
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 40;
        }
        .account-topnav h1 {
            margin: 0;
            color: #2a4b84;
            font-size: 1.05rem;
            letter-spacing: 0.02em;
            font-weight: 800;
        }
        .account-topnav-links { display: flex; gap: 0.9rem; flex-wrap: wrap; }
        .account-topnav-links a {
            text-decoration: none;
            color: #1e3f75;
            font-weight: 700;
            padding: 0.46rem 0.85rem;
            border-radius: 999px;
            border: 1px solid #d6e1f7;
            background: #ffffff;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .account-topnav-links a:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 14px rgba(17, 46, 103, 0.12);
        }
        .account-shell { width: min(1160px, 100%); margin: 0 auto; padding: 1.35rem 1.2rem 1.6rem; }
        .account-layout {
            display: block;
        }
        .account-right {
            background: #fff;
            border: 1px solid #dae5fb;
            border-radius: 16px;
            box-shadow: 0 20px 45px rgba(16, 42, 95, 0.10);
        }
        .profile-photo {
            width: 165px;
            height: 165px;
            border-radius: 50%;
            margin: 0 auto 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.2rem;
            font-weight: 800;
            color: #fff;
            background: linear-gradient(135deg, #6b2fc9 0%, #8f53e8 100%);
            box-shadow: 0 10px 22px rgba(82, 42, 153, 0.28);
            object-fit: cover;
            overflow: hidden;
        }
        .profile-hero {
            margin: -0.35rem -0.35rem 1.15rem;
            border: 1px solid #dae6ff;
            border-radius: 14px;
            background: #fff;
            overflow: hidden;
            animation: heroCardIn 520ms ease-out both;
        }
        .profile-cover {
            position: relative;
            height: 300px;
            background:
                linear-gradient(120deg, rgba(30, 99, 210, 0.56) 0%, rgba(15, 68, 156, 0.66) 100%),
                url('../assets/images/pototan-hall-wide.png') center 40% / cover no-repeat;
            animation: coverZoomIn 950ms ease-out both;
        }
        .hero-back-link {
            position: absolute;
            top: 0.95rem;
            left: 1rem;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.44rem 0.8rem;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            background: rgba(9, 25, 58, 0.50);
            color: #ffffff;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 700;
            backdrop-filter: blur(2px);
            transition: background 0.18s ease, transform 0.18s ease;
        }
        .hero-back-link:hover {
            background: rgba(12, 20, 48, 0.62);
            transform: translateY(-1px);
        }
        .profile-cover::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to bottom, rgba(255, 255, 255, 0) 48%, rgba(255, 255, 255, 0.22) 100%);
            pointer-events: none;
        }
        .profile-meta {
            position: relative;
            margin-top: -74px;
            padding: 0 1rem 1.3rem;
            text-align: center;
        }
        .profile-hero-photo {
            width: 124px;
            height: 124px;
            border-radius: 50%;
            margin: 0 auto;
            border: 5px solid #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.35rem;
            font-weight: 800;
            color: #fff;
            background: linear-gradient(135deg, #6b2fc9 0%, #8f53e8 100%);
            box-shadow: 0 15px 30px rgba(49, 77, 140, 0.25);
            object-fit: cover;
            overflow: hidden;
            transition: transform 180ms ease, box-shadow 180ms ease;
            animation: avatarPopIn 620ms cubic-bezier(0.2, 0.8, 0.2, 1) 120ms both;
        }
        .profile-hero:hover .profile-hero-photo {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 14px 26px rgba(82, 42, 153, 0.26);
        }
        .profile-meta h2 {
            margin: 0.92rem 0 0.35rem;
            color: #1a315a;
            font-size: 1.95rem;
            letter-spacing: 0.2px;
        }
        .profile-role {
            margin: 0;
            color: #5e7194;
            font-size: 1.08rem;
        }
        .hero-edit-link {
            margin-top: 0.8rem;
        }
        .hero-edit-link a {
            color: #1b5ccc;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.92rem;
            border: 1px solid #d2def7;
            background: #f4f8ff;
            border-radius: 999px;
            padding: 0.42rem 0.9rem;
            display: inline-flex;
        }
        .hero-edit-link a:hover {
            background: #eaf2ff;
        }
        .hero-photo-form {
            margin-top: 0.45rem;
        }
        .hero-photo-input {
            display: none;
        }
        .hero-photo-link {
            border: 0;
            background: transparent;
            color: #4b5f85;
            font-size: 0.83rem;
            text-decoration: none;
            cursor: pointer;
            padding: 0;
            border-bottom: 1px dashed #9db0d8;
        }
        .hero-photo-link:hover {
            color: #2f4470;
        }
        @keyframes heroCardIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes coverZoomIn {
            from { transform: scale(1.04); }
            to { transform: scale(1); }
        }
        @keyframes avatarPopIn {
            from { opacity: 0; transform: translateY(10px) scale(0.88); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            .profile-hero,
            .profile-cover,
            .profile-hero-photo {
                animation: none !important;
                transition: none !important;
            }
        }
        .edit-profile-row {
            margin: 0.2rem 0 0.9rem;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 0.8rem;
            flex-wrap: wrap;
        }
        .account-right { padding: 1.05rem; }
        .account-info-box {
            border: 1px solid #dfe7f8;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }
        .account-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.8rem 1.2rem;
        }
        .account-info-grid h4 {
            margin: 0 0 0.4rem;
            color: #263d67;
            font-size: 0.98rem;
        }
        .account-info-grid ul {
            margin: 0;
            padding-left: 1.15rem;
            color: #41577f;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.7rem;
            margin: 0 0 1rem;
        }
        .stat-card {
            border: 1px solid #dde8fc;
            border-radius: 12px;
            background: #ffffff;
            padding: 0.75rem 0.85rem;
            box-shadow: 0 8px 20px rgba(17, 44, 98, 0.07);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 3px;
            background: #5f87db;
        }
        .stat-card.total::before { background: linear-gradient(90deg, #3a6fd1, #5b89f0); }
        .stat-card.paid::before { background: linear-gradient(90deg, #2c9b5e, #52be84); }
        .stat-card.pending::before { background: linear-gradient(90deg, #e08b1a, #f2ad52); }
        .stat-card.top::before { background: linear-gradient(90deg, #8b52cc, #aa74e2); }
        .stat-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .stat-label {
            margin: 0;
            color: #5d7093;
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-weight: 700;
        }
        .stat-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            color: #274d8a;
            background: #edf3ff;
        }
        .stat-card.paid .stat-icon {
            color: #1f7647;
            background: #e7f8ef;
        }
        .stat-card.pending .stat-icon {
            color: #955f0d;
            background: #fff4e4;
        }
        .stat-card.top .stat-icon {
            color: #6d3aa8;
            background: #f1eaff;
        }
        .stat-value {
            margin: 0.2rem 0 0;
            font-size: 1.35rem;
            font-weight: 800;
            color: #1c3869;
        }
        .account-desc {
            margin: 0 0 0.75rem;
            color: #6a7b9b;
            line-height: 1.5;
        }
        .edit-form {
            border: 1px solid #dce6fa;
            border-radius: 10px;
            padding: 0.9rem;
            margin-bottom: 0.9rem;
            background: #fcfdff;
        }
        .edit-trigger-wrap { display: none; }
        .edit-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.7rem;
        }
        .edit-form .form-group { display: flex; flex-direction: column; }
        .edit-form label {
            font-size: 0.86rem;
            font-weight: 700;
            color: #26416d;
            margin-bottom: 0.3rem;
        }
        .edit-form input {
            border: 1px solid #ccdaf4;
            border-radius: 8px;
            padding: 0.62rem 0.68rem;
            font-size: 0.9rem;
        }
        .upload-media-box {
            border: 1px dashed #c8d7f2;
            border-radius: 10px;
            background: #f8fbff;
            padding: 0.9rem 0.85rem;
            text-align: center;
            transition: border-color 0.16s ease, background 0.16s ease;
        }
        .upload-media-box.is-dragover {
            border-color: #6b2fc9;
            background: #f1f5ff;
        }
        .upload-media-icon {
            font-size: 1.35rem;
            line-height: 1;
            color: #7a8eb5;
            margin-bottom: 0.35rem;
        }
        .upload-media-hint {
            margin: 0;
            color: #5f739b;
            font-size: 0.84rem;
        }
        .upload-media-or {
            margin: 0.38rem 0;
            color: #7e8fae;
            font-size: 0.8rem;
            text-transform: lowercase;
        }
        .upload-media-btn {
            border: 1px solid #d67a2b;
            background: #ff9a3f;
            color: #ffffff;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.28rem 0.68rem;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.01em;
        }
        .upload-media-btn:hover {
            background: #f28d33;
        }
        .upload-media-name {
            margin: 0.45rem 0 0;
            color: #587099;
            font-size: 0.8rem;
            word-break: break-word;
        }
        .upload-media-input {
            display: none;
        }
        .edit-form .full { grid-column: 1 / -1; }
        .edit-actions {
            margin-top: 0.8rem;
            display: flex;
            justify-content: flex-end;
        }
        .save-btn {
            border: 0;
            background: #1f5fd3;
            color: #fff;
            font-weight: 700;
            border-radius: 8px;
            padding: 0.58rem 1rem;
            cursor: pointer;
        }
        .form-alert {
            border-radius: 8px;
            padding: 0.62rem 0.75rem;
            margin-bottom: 0.75rem;
            font-size: 0.88rem;
        }
        .form-alert.success { background: #d8f1df; color: #1f6b3b; }
        .form-alert.error { background: #f8d7da; color: #721c24; }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(10, 20, 40, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1300;
            padding: 1rem;
        }
        .modal-backdrop.open { display: flex; }
        .profile-modal {
            width: min(760px, 100%);
            max-height: 92vh;
            overflow-y: auto;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 18px 48px rgba(8, 20, 44, 0.35);
            border: 1px solid #dce6fa;
        }
        .profile-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.8rem;
            border-bottom: 1px solid #e5ecfb;
            padding: 0.85rem 1rem;
        }
        .profile-modal-head h3 {
            margin: 0;
            color: #19396e;
            font-size: 1.05rem;
        }
        .profile-modal-close {
            border: 0;
            background: transparent;
            color: #375381;
            font-size: 1.35rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
        }
        .profile-modal-body {
            padding: 0.95rem 1rem 1rem;
        }
        .photo-preview-wrap {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            margin-top: 0.55rem;
        }
        .photo-preview {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 2px solid #d7e4fb;
            object-fit: cover;
            display: none;
            background: #f5f8ff;
        }
        .photo-preview.show { display: block; }
        .photo-preview-note {
            margin: 0;
            font-size: 0.82rem;
            color: #5a6f95;
        }
        .section-title {
            margin: 0 0 0.6rem;
            color: #163a74;
            font-size: 1.08rem;
            letter-spacing: 0.01em;
        }
        .section-animate {
            animation: sectionFadeIn 520ms ease-out both;
        }
        .section-animate.delay-1 { animation-delay: 80ms; }
        .section-animate.delay-2 { animation-delay: 150ms; }
        .section-animate.delay-3 { animation-delay: 220ms; }
        @keyframes sectionFadeIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .chip {
            display: inline-block;
            margin: 0.2rem 0.2rem 0 0;
            padding: 0.28rem 0.62rem;
            border-radius: 999px;
            font-size: 0.84rem;
            background: #eef3ff;
            color: #1f4f97;
            border: 1px solid #d4e0f8;
        }
        .chip.status-chip::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.42rem;
            vertical-align: middle;
            background: #6f85ae;
        }
        .chip.status-chip.pending::before { background: #e39a2f; }
        .chip.status-chip.validated::before { background: #4a7eda; }
        .chip.status-chip.paid::before { background: #2ea35f; }
        .chip.status-chip.rejected::before { background: #cc4253; }
        .offense-line {
            margin: 0.35rem 0;
            color: #344d79;
            font-size: 0.96rem;
            font-weight: 700;
        }
        .offense-line strong {
            color: #143a78;
            font-weight: 800;
        }
        .violations-wrap {
            border: 1px solid #e1e8f7;
            border-radius: 12px;
            overflow-x: auto;
            background: #ffffff;
        }
        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            flex-wrap: wrap;
            margin: 0 0 0.65rem;
            padding: 0.65rem 0.75rem;
            border: 1px solid #dfE8FA;
            border-radius: 10px;
            background: #f9fbff;
        }
        .table-toolbar-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .table-toolbar label {
            font-size: 0.8rem;
            color: #37527f;
            font-weight: 700;
        }
        .table-toolbar input,
        .table-toolbar select {
            border: 1px solid #ccd9f2;
            border-radius: 8px;
            padding: 0.42rem 0.52rem;
            font-size: 0.84rem;
            color: #1e3663;
            background: #ffffff;
        }
        .table-toolbar input {
            min-width: 210px;
        }
        .table-empty-note {
            display: none;
            margin: 0.6rem 0 0;
            font-size: 0.87rem;
            color: #5a6f95;
        }
        .violations-table {
            width: 100%;
            min-width: 880px;
            border-collapse: collapse;
        }
        .violations-table th,
        .violations-table td {
            padding: 0.62rem 0.72rem;
            border-bottom: 1px solid #e7edf9;
            text-align: left;
            font-size: 0.88rem;
            color: #1d335e;
        }
        .violations-table th {
            background: #eef3ff;
            color: #123a7f;
            font-weight: 700;
            position: sticky;
            top: 0;
            z-index: 3;
        }
        .violations-table tbody tr:nth-child(even) {
            background: #fbfdff;
        }
        .violations-table tbody tr:hover {
            background: #f2f7ff;
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
        html[data-theme="dark"] body {
            background: linear-gradient(180deg, #0f1624 0%, #121b2d 100%);
            color: #d8e4ff;
        }
        html[data-theme="dark"] .account-topnav {
            background: rgba(18, 27, 45, 0.88);
            border-bottom-color: #2c3b58;
        }
        html[data-theme="dark"] .account-topnav h1 {
            color: #e4edff;
        }
        html[data-theme="dark"] .account-topnav-links a {
            color: #bcd0f2;
        }
        html[data-theme="dark"] .account-right,
        html[data-theme="dark"] .profile-hero,
        html[data-theme="dark"] .account-info-box,
        html[data-theme="dark"] .stat-card,
        html[data-theme="dark"] .edit-form,
        html[data-theme="dark"] .profile-modal {
            background: linear-gradient(180deg, #162235 0%, #1a2940 100%);
            border-color: #2d3f5d;
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.24);
        }
        html[data-theme="dark"] .profile-cover::after {
            background: linear-gradient(to bottom, rgba(8, 14, 26, 0) 40%, rgba(8, 14, 26, 0.48) 100%);
        }
        html[data-theme="dark"] .hero-photo-link,
        html[data-theme="dark"] .account-desc,
        html[data-theme="dark"] .account-info-grid ul {
            color: #b5c6e4;
        }
        html[data-theme="dark"] .hero-photo-link:hover {
            color: #dce8ff;
        }
        html[data-theme="dark"] .account-info-grid h4,
        html[data-theme="dark"] .stat-value,
        html[data-theme="dark"] .section-title,
        html[data-theme="dark"] .offense-line,
        html[data-theme="dark"] .offense-line strong,
        html[data-theme="dark"] .profile-meta h2,
        html[data-theme="dark"] .profile-role,
        html[data-theme="dark"] .profile-modal-head h3 {
            color: #e4edff;
        }
        html[data-theme="dark"] .stat-label {
            color: #a9bfde;
        }
        html[data-theme="dark"] .stat-icon {
            background: #20324f;
            color: #dce8ff;
        }
        html[data-theme="dark"] .chip {
            background: #1f2f49;
            color: #dce8ff;
            border-color: #334967;
        }
        html[data-theme="dark"] .table-toolbar {
            background: #16263d;
            border-color: #2f425f;
        }
        html[data-theme="dark"] .table-toolbar label {
            color: #c2d5f1;
        }
        html[data-theme="dark"] .table-toolbar input,
        html[data-theme="dark"] .table-toolbar select {
            background: #121d30;
            border-color: #334966;
            color: #deebff;
        }
        html[data-theme="dark"] .table-empty-note {
            color: #b8caea;
        }
        html[data-theme="dark"] .violations-wrap {
            background: #162235;
            border-color: #2d3f5d;
        }
        html[data-theme="dark"] .violations-table th {
            background: #1f2f49;
            color: #dce8ff;
            border-bottom-color: #334967;
        }
        html[data-theme="dark"] .violations-table td {
            color: #d5e2f8;
            border-bottom-color: #2f425f;
        }
        html[data-theme="dark"] .violations-table tbody tr:nth-child(even) {
            background: #192840;
        }
        html[data-theme="dark"] .violations-table tbody tr:hover {
            background: #23344f;
        }
        html[data-theme="dark"] .profile-modal-head {
            border-bottom-color: #2f425f;
        }
        html[data-theme="dark"] .profile-modal-close {
            color: #c5d7f6;
        }
        html[data-theme="dark"] .edit-form label,
        html[data-theme="dark"] .photo-preview-note {
            color: #b8caea;
        }
        html[data-theme="dark"] .edit-form input {
            background: #121d30;
            color: #deebff;
            border-color: #334966;
        }
        html[data-theme="dark"] .upload-media-box {
            background: #121d30;
            border-color: #334966;
        }
        html[data-theme="dark"] .upload-media-box.is-dragover {
            background: #15243b;
            border-color: #7d5fd5;
        }
        html[data-theme="dark"] .upload-media-icon,
        html[data-theme="dark"] .upload-media-hint,
        html[data-theme="dark"] .upload-media-or,
        html[data-theme="dark"] .upload-media-name {
            color: #b8caea;
        }
        html[data-theme="dark"] .photo-preview {
            background: #121d30;
            border-color: #334967;
        }
        @media (max-width: 980px) {
            .profile-cover { height: 250px; }
            .profile-meta { margin-top: -70px; }
            .profile-hero-photo {
                width: 112px;
                height: 112px;
                font-size: 2.05rem;
            }
            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .account-info-grid { grid-template-columns: 1fr; }
            .edit-form-grid { grid-template-columns: 1fr; }
            .profile-meta h2 { font-size: 1.55rem; }
            .chip {
                font-size: 0.89rem;
                padding: 0.3rem 0.68rem;
            }
            .table-toolbar {
                padding: 0.62rem;
            }
            .table-toolbar input {
                min-width: 160px;
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .section-animate {
                animation: none !important;
            }
        }
    </style>
</head>
<body>
    <nav class="account-topnav">
        <h1>MTMO Account</h1>
        <div class="account-topnav-links">
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <div class="account-shell">
        <section class="account-layout">
            <?php
            $initials = '';
            $name_parts = array_values(array_filter(explode(' ', $full_name), fn($p) => trim((string)$p) !== ''));
            if (!empty($name_parts)) {
                $initials .= strtoupper(substr($name_parts[0], 0, 1));
                if (count($name_parts) > 1) {
                    $initials .= strtoupper(substr($name_parts[count($name_parts) - 1], 0, 1));
                }
            }
            if ($initials === '') {
                $initials = 'M';
            }
            ?>
            <main class="account-right">
                <section class="profile-hero">
                    <div class="profile-cover"></div>
                    <a href="dashboard.php" class="hero-back-link">← Back to Dashboard</a>
                    <div class="profile-meta">
                        <?php if ($profile_photo !== ''): ?>
                            <img src="../uploads/<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo" class="profile-hero-photo">
                        <?php else: ?>
                            <div class="profile-hero-photo"><?php echo htmlspecialchars($initials); ?></div>
                        <?php endif; ?>
                        <h2><?php echo htmlspecialchars($motorist_profile['full_name'] ?? $full_name); ?></h2>
                        <p class="profile-role">Motorist Account</p>
                        <p class="hero-edit-link"><a href="#" id="edit-profile-link">Edit your profile</a></p>
                        <form method="POST" enctype="multipart/form-data" class="hero-photo-form" id="hero-photo-form">
                            <input type="hidden" name="save_profile_photo" value="1">
                            <input type="file" id="hero-photo-input" class="hero-photo-input" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <button type="button" class="hero-photo-link" id="hero-photo-trigger">Change avatar photo</button>
                        </form>
                    </div>
                </section>

                <div class="account-info-box section-animate delay-1">
                    <div class="account-info-grid">
                        <div>
                            <h4>Personal Information:</h4>
                            <ul>
                                <li>Full Name: <?php echo htmlspecialchars($motorist_profile['full_name'] ?? $full_name); ?></li>
                                <li>Username: <?php echo htmlspecialchars($username); ?></li>
                                <li>Role: Motorist</li>
                                <li>Contact: <?php echo htmlspecialchars($motorist_profile['contact_number'] ?? 'N/A'); ?></li>
                            </ul>
                        </div>
                        <div>
                            <h4>Vehicle and License:</h4>
                            <ul>
                                <li>License Number: <?php echo htmlspecialchars($motorist_profile['license_number'] ?? 'N/A'); ?></li>
                                <li>Plate Number: <?php echo htmlspecialchars($motorist_profile['plate'] ?? 'N/A'); ?></li>
                                <li>Address: <?php echo htmlspecialchars($motorist_profile['address'] ?? 'N/A'); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <p class="account-desc">
                    From your account page you can review all recorded violations, check offense status,
                    and monitor your account details for full transparency.
                </p>
                <div class="stats-grid" aria-label="Violation summary cards">
                    <article class="stat-card total">
                        <div class="stat-head">
                            <p class="stat-label">Total Violations</p>
                            <span class="stat-icon" aria-hidden="true">#</span>
                        </div>
                        <p class="stat-value"><?php echo number_format($total_violations); ?></p>
                    </article>
                    <article class="stat-card paid">
                        <div class="stat-head">
                            <p class="stat-label">Paid Cases</p>
                            <span class="stat-icon" aria-hidden="true">P</span>
                        </div>
                        <p class="stat-value"><?php echo number_format($paid_count); ?></p>
                    </article>
                    <article class="stat-card pending">
                        <div class="stat-head">
                            <p class="stat-label">Pending Cases</p>
                            <span class="stat-icon" aria-hidden="true">!</span>
                        </div>
                        <p class="stat-value"><?php echo number_format($pending_count); ?></p>
                    </article>
                    <article class="stat-card top">
                        <div class="stat-head">
                            <p class="stat-label">Top Offense Count</p>
                            <span class="stat-icon" aria-hidden="true">T</span>
                        </div>
                        <p class="stat-value"><?php echo number_format($top_offense_count); ?></p>
                    </article>
                </div>
                <?php if ($edit_success !== ''): ?>
                    <div class="form-alert success"><?php echo htmlspecialchars($edit_success); ?></div>
                <?php endif; ?>
                <?php if ($edit_error !== ''): ?>
                    <div class="form-alert error"><?php echo htmlspecialchars($edit_error); ?></div>
                <?php endif; ?>

                <h3 class="section-title section-animate delay-1" id="offense-summary">Offense Summary</h3>
                <p class="offense-line"><strong>Total Violations:</strong> <?php echo number_format($total_violations); ?></p>
                <p class="offense-line"><strong>By Status:</strong></p>
                <?php if (empty($offense_by_status)): ?>
                    <span class="chip">No records</span>
                <?php else: ?>
                    <?php foreach ($offense_by_status as $status => $count): ?>
                        <span class="chip status-chip <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?>: <?php echo number_format((int)$count); ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
                <p class="offense-line" style="margin-top:0.65rem;"><strong>Top Offenses:</strong></p>
                <?php if (empty($offense_by_type)): ?>
                    <span class="chip">No offense data</span>
                <?php else: ?>
                    <?php $shown = 0; foreach ($offense_by_type as $type => $count): ?>
                        <span class="chip"><?php echo htmlspecialchars($type); ?>: <?php echo number_format((int)$count); ?></span>
                        <?php $shown++; if ($shown >= 8) { break; } ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <h3 class="section-title section-animate delay-2" id="all-violations" style="margin-top:1rem;">All Violation Details</h3>
            <div class="table-toolbar section-animate delay-2">
                <div class="table-toolbar-group">
                    <label for="violation-search">Search</label>
                    <input type="search" id="violation-search" placeholder="TOP number, violation, location">
                </div>
                <div class="table-toolbar-group">
                    <label for="violation-status-filter">Status</label>
                    <select id="violation-status-filter">
                        <option value="all">All</option>
                        <option value="pending">Pending</option>
                        <option value="validated">Validated</option>
                        <option value="paid">Paid</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </div>
            <div class="violations-wrap section-animate delay-3">
                <table class="violations-table">
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
                        <?php if (empty($violations)): ?>
                            <tr><td colspan="6">No violation records found for this account.</td></tr>
                        <?php else: ?>
                            <?php foreach ($violations as $violation): ?>
                                <?php $status = strtolower((string)($violation['status'] ?? 'pending')); ?>
                                <tr data-status="<?php echo htmlspecialchars($status); ?>">
                                    <td data-col="top"><?php echo htmlspecialchars((string)($violation['top_number'] ?: 'N/A')); ?></td>
                                    <td><?php echo !empty($violation['violation_date']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($violation['violation_date']))) : 'N/A'; ?></td>
                                    <td data-col="violation"><?php echo htmlspecialchars((string)$violation['violation_display']); ?></td>
                                    <td data-col="location"><?php echo htmlspecialchars((string)($violation['location'] ?? 'N/A')); ?></td>
                                    <td>₱<?php echo number_format((float)($violation['fine_amount'] ?? 0), 2); ?></td>
                                    <td><span class="status-tag <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="table-empty-note" id="violation-empty-note">No violations match your current filter.</p>
            </main>
        </section>
    </div>
    <div class="modal-backdrop" id="profile-edit-modal" aria-hidden="true">
        <div class="profile-modal" role="dialog" aria-modal="true" aria-labelledby="profile-edit-title">
            <div class="profile-modal-head">
                <h3 id="profile-edit-title">Edit Profile</h3>
                <button type="button" class="profile-modal-close" id="close-edit-profile" aria-label="Close edit profile modal">&times;</button>
            </div>
            <div class="profile-modal-body">
                <form method="POST" enctype="multipart/form-data" class="edit-form">
                    <?php if ($edit_success !== ''): ?>
                        <div class="form-alert success"><?php echo htmlspecialchars($edit_success); ?></div>
                    <?php endif; ?>
                    <?php if ($edit_error !== ''): ?>
                        <div class="form-alert error"><?php echo htmlspecialchars($edit_error); ?></div>
                    <?php endif; ?>
                    <input type="hidden" name="save_profile" value="1">
                    <div class="edit-form-grid">
                        <div class="form-group">
                            <label for="edit_full_name">Full Name</label>
                            <input type="text" id="edit_full_name" name="full_name" value="<?php echo htmlspecialchars($motorist_profile['full_name'] ?? $full_name); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_contact">Contact Number</label>
                            <input type="text" id="edit_contact" name="contact_number" value="<?php echo htmlspecialchars($motorist_profile['contact_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="edit_license">License Number</label>
                            <input type="text" id="edit_license" name="license_number" value="<?php echo htmlspecialchars($motorist_profile['license_number'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_plate">Plate Number</label>
                            <input type="text" id="edit_plate" name="plate" value="<?php echo htmlspecialchars($motorist_profile['plate'] ?? ''); ?>" pattern="[A-Za-z0-9][A-Za-z0-9 -]{1,13}[A-Za-z0-9]" title="Use letters, numbers, spaces, or dash (3-15 chars)." required>
                        </div>
                        <div class="form-group full">
                            <label for="edit_address">Address</label>
                            <input type="text" id="edit_address" name="address" value="<?php echo htmlspecialchars($motorist_profile['address'] ?? ''); ?>">
                        </div>
                        <div class="form-group full">
                            <label for="edit_photo">Profile Photo (JPG, PNG, WEBP, max 2MB)</label>
                            <div class="upload-media-box" id="edit-photo-dropzone">
                                <div class="upload-media-icon">☁</div>
                                <p class="upload-media-hint">Drag and drop file here</p>
                                <p class="upload-media-or">or</p>
                                <button type="button" class="upload-media-btn" id="edit-photo-trigger">Choose File</button>
                                <p class="upload-media-name" id="edit-photo-name">No file chosen</p>
                                <input type="file" id="edit_photo" class="upload-media-input" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            </div>
                            <div class="photo-preview-wrap">
                                <?php if ($profile_photo !== ''): ?>
                                    <img src="../uploads/<?php echo htmlspecialchars($profile_photo); ?>" alt="Selected photo preview" class="photo-preview show" id="photo-preview">
                                <?php else: ?>
                                    <img src="" alt="Selected photo preview" class="photo-preview" id="photo-preview">
                                <?php endif; ?>
                                <p class="photo-preview-note">Preview updates when you choose a new photo.</p>
                            </div>
                        </div>
                    </div>
                    <div class="edit-actions">
                        <button type="submit" class="save-btn">Save Profile</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const modal = document.getElementById('profile-edit-modal');
            const openBtn = document.getElementById('edit-profile-link');
            const closeBtn = document.getElementById('close-edit-profile');
            const photoInput = document.getElementById('edit_photo');
            const photoPreview = document.getElementById('photo-preview');
            const editPhotoTrigger = document.getElementById('edit-photo-trigger');
            const editPhotoName = document.getElementById('edit-photo-name');
            const editPhotoDropzone = document.getElementById('edit-photo-dropzone');
            const heroPhotoForm = document.getElementById('hero-photo-form');
            const heroPhotoInput = document.getElementById('hero-photo-input');
            const heroPhotoTrigger = document.getElementById('hero-photo-trigger');
            const violationSearchInput = document.getElementById('violation-search');
            const violationStatusFilter = document.getElementById('violation-status-filter');
            const violationTableRows = Array.from(document.querySelectorAll('.violations-table tbody tr[data-status]'));
            const violationEmptyNote = document.getElementById('violation-empty-note');
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
            const updatePhotoMeta = function () {
                if (!photoInput) {
                    return;
                }
                const file = photoInput.files && photoInput.files[0] ? photoInput.files[0] : null;
                if (editPhotoName) {
                    editPhotoName.textContent = file ? file.name : 'No file chosen';
                }
            };
            if (editPhotoTrigger && photoInput) {
                editPhotoTrigger.addEventListener('click', function () {
                    photoInput.click();
                });
            }
            if (photoInput && photoPreview) {
                photoInput.addEventListener('change', function () {
                    const file = photoInput.files && photoInput.files[0] ? photoInput.files[0] : null;
                    updatePhotoMeta();
                    if (!file) {
                        return;
                    }
                    if (!file.type.startsWith('image/')) {
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = function (loadEvent) {
                        photoPreview.src = String(loadEvent.target && loadEvent.target.result ? loadEvent.target.result : '');
                        if (photoPreview.src !== '') {
                            photoPreview.classList.add('show');
                        }
                    };
                    reader.readAsDataURL(file);
                });
            }
            if (editPhotoDropzone && photoInput) {
                editPhotoDropzone.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    editPhotoDropzone.classList.add('is-dragover');
                });
                editPhotoDropzone.addEventListener('dragleave', function () {
                    editPhotoDropzone.classList.remove('is-dragover');
                });
                editPhotoDropzone.addEventListener('drop', function (event) {
                    event.preventDefault();
                    editPhotoDropzone.classList.remove('is-dragover');
                    const files = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : null;
                    if (!files || !files.length) {
                        return;
                    }
                    try {
                        photoInput.files = files;
                    } catch (dropError) {
                        return;
                    }
                    photoInput.dispatchEvent(new Event('change'));
                });
            }
            updatePhotoMeta();
            if (heroPhotoTrigger && heroPhotoInput) {
                heroPhotoTrigger.addEventListener('click', function () {
                    heroPhotoInput.click();
                });
            }
            if (heroPhotoInput && heroPhotoForm) {
                heroPhotoInput.addEventListener('change', function () {
                    const file = heroPhotoInput.files && heroPhotoInput.files[0] ? heroPhotoInput.files[0] : null;
                    if (!file) {
                        return;
                    }
                    heroPhotoForm.submit();
                });
            }
            const applyViolationFilters = function () {
                if (!violationTableRows.length) {
                    return;
                }
                const searchTerm = (violationSearchInput && violationSearchInput.value ? violationSearchInput.value : '').trim().toLowerCase();
                const selectedStatus = violationStatusFilter && violationStatusFilter.value ? violationStatusFilter.value : 'all';
                let visibleRows = 0;
                violationTableRows.forEach(function (row) {
                    const rowStatus = String(row.getAttribute('data-status') || '').toLowerCase();
                    const topCell = row.querySelector('[data-col="top"]');
                    const violationCell = row.querySelector('[data-col="violation"]');
                    const locationCell = row.querySelector('[data-col="location"]');
                    const searchText = [
                        topCell ? topCell.textContent : '',
                        violationCell ? violationCell.textContent : '',
                        locationCell ? locationCell.textContent : ''
                    ].join(' ').toLowerCase();
                    const statusMatches = selectedStatus === 'all' || rowStatus === selectedStatus;
                    const searchMatches = searchTerm === '' || searchText.includes(searchTerm);
                    const shouldShow = statusMatches && searchMatches;
                    row.style.display = shouldShow ? '' : 'none';
                    if (shouldShow) {
                        visibleRows += 1;
                    }
                });
                if (violationEmptyNote) {
                    violationEmptyNote.style.display = visibleRows === 0 ? 'block' : 'none';
                }
            };
            if (violationSearchInput) {
                violationSearchInput.addEventListener('input', applyViolationFilters);
            }
            if (violationStatusFilter) {
                violationStatusFilter.addEventListener('change', applyViolationFilters);
            }
            applyViolationFilters();
            <?php if ($should_open_edit_modal): ?>
            openModal();
            <?php endif; ?>
        }());
    </script>
</body>
</html>
