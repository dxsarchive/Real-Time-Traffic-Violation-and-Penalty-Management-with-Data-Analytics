<?php
require_once '../auth.php';
check_role('supervisor');

global $pdo;
$db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$upload_directory = realpath(__DIR__ . '/../uploads');
if ($upload_directory === false) {
    $upload_directory = __DIR__ . '/../uploads';
    if (!is_dir($upload_directory)) {
        mkdir($upload_directory, 0777, true);
    }
}
$video_directory = $upload_directory . DIRECTORY_SEPARATOR . 'tutorials';
if (!is_dir($video_directory)) {
    mkdir($video_directory, 0777, true);
}
$announcement_directory = $upload_directory . DIRECTORY_SEPARATOR . 'announcements';
if (!is_dir($announcement_directory)) {
    mkdir($announcement_directory, 0777, true);
}
$article_directory = $upload_directory . DIRECTORY_SEPARATOR . 'articles';
if (!is_dir($article_directory)) {
    mkdir($article_directory, 0777, true);
}

function save_uploaded_tutorial_video(array $file, string $target_dir): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Video upload failed. Please try again.');
    }

    $max_size = 60 * 1024 * 1024; // 60 MB
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $max_size) {
        throw new RuntimeException('Video must be between 1 byte and 60 MB.');
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['mp4', 'webm', 'ogg'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Only MP4, WEBM, and OGG files are allowed.');
    }

    $filename = 'tutorial_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
    $target_path = $target_dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $target_path)) {
        throw new RuntimeException('Unable to save uploaded video file.');
    }

    return 'tutorials/' . $filename;
}

function save_uploaded_announcement_image(array $file, string $target_dir): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Announcement image upload failed. Please try again.');
    }

    $max_size = 8 * 1024 * 1024; // 8 MB
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $max_size) {
        throw new RuntimeException('Image must be between 1 byte and 8 MB.');
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Only JPG, PNG, WEBP, and GIF images are allowed.');
    }

    $filename = 'announcement_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
    $target_path = $target_dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $target_path)) {
        throw new RuntimeException('Unable to save uploaded announcement image.');
    }

    return 'announcements/' . $filename;
}

function save_uploaded_article_pdf(array $file, string $target_dir): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Document upload failed. Please try again.');
    }

    $max_size = 15 * 1024 * 1024;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $max_size) {
        throw new RuntimeException('PDF must be between 1 byte and 15 MB.');
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        throw new RuntimeException('Only PDF files are allowed for article attachments.');
    }

    $filename = 'article_' . time() . '_' . bin2hex(random_bytes(5)) . '.pdf';
    $target_path = $target_dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $target_path)) {
        throw new RuntimeException('Unable to save uploaded PDF.');
    }

    return 'articles/' . $filename;
}

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
}

require_once __DIR__ . '/../includes/ensure_articles_schema.php';
ensure_articles_schema($pdo, $db_driver);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_type = $_POST['content_type'] ?? '';
    $action = $_POST['action'] ?? '';

    try {
        if ($content_type === 'announcement') {
            if ($action === 'create') {
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                if ($title === '' || $content === '') {
                    throw new RuntimeException('Announcement title and content are required.');
                }
                $image_path = '';
                if (isset($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $image_path = save_uploaded_announcement_image($_FILES['image_file'], $announcement_directory);
                }
                $stmt = $pdo->prepare("INSERT INTO announcements (title, content, image_path, is_active, posted_at) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$title, $content, $image_path, $is_active, date('Y-m-d H:i:s')]);
                $message = 'Announcement added.';
            } elseif ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                if ($id <= 0 || $title === '' || $content === '') {
                    throw new RuntimeException('Invalid announcement update request.');
                }
                $old_stmt = $pdo->prepare("SELECT image_path FROM announcements WHERE id = ?");
                $old_stmt->execute([$id]);
                $old_data = $old_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$old_data) {
                    throw new RuntimeException('Announcement not found.');
                }
                $image_path = $old_data['image_path'] ?? '';
                if (isset($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $new_image_path = save_uploaded_announcement_image($_FILES['image_file'], $announcement_directory);
                    if ($image_path !== '') {
                        $old_file = $upload_directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $image_path);
                        if (is_file($old_file)) {
                            @unlink($old_file);
                        }
                    }
                    $image_path = $new_image_path;
                }
                $stmt = $pdo->prepare("UPDATE announcements SET title = ?, content = ?, image_path = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$title, $content, $image_path, $is_active, $id]);
                $message = 'Announcement updated.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Invalid announcement delete request.');
                }
                $old_stmt = $pdo->prepare("SELECT image_path FROM announcements WHERE id = ?");
                $old_stmt->execute([$id]);
                $old_data = $old_stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
                $stmt->execute([$id]);
                if (!empty($old_data['image_path'])) {
                    $old_file = $upload_directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $old_data['image_path']);
                    if (is_file($old_file)) {
                        @unlink($old_file);
                    }
                }
                $message = 'Announcement deleted.';
            }
        } elseif ($content_type === 'video') {
            if ($action === 'create') {
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $sort_order = (int)($_POST['sort_order'] ?? 0);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                if ($title === '') {
                    throw new RuntimeException('Video title is required.');
                }
                if (!isset($_FILES['video_file']) || ($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Please upload a tutorial video file.');
                }
                $file_path = save_uploaded_tutorial_video($_FILES['video_file'], $video_directory);
                $stmt = $pdo->prepare("INSERT INTO tutorial_videos (title, url, file_path, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, '', $file_path, $description, $sort_order, $is_active]);
                $message = 'Tutorial video added.';
            } elseif ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $sort_order = (int)($_POST['sort_order'] ?? 0);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                if ($id <= 0 || $title === '') {
                    throw new RuntimeException('Invalid video update request.');
                }
                $old_stmt = $pdo->prepare("SELECT file_path FROM tutorial_videos WHERE id = ?");
                $old_stmt->execute([$id]);
                $old_video = $old_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$old_video) {
                    throw new RuntimeException('Video not found.');
                }
                $file_path = $old_video['file_path'] ?? '';
                if (isset($_FILES['video_file']) && ($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $new_file_path = save_uploaded_tutorial_video($_FILES['video_file'], $video_directory);
                    if ($file_path !== '') {
                        $old_file = $upload_directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
                        if (is_file($old_file)) {
                            @unlink($old_file);
                        }
                    }
                    $file_path = $new_file_path;
                }
                if ($file_path === '') {
                    throw new RuntimeException('Upload a video file before saving.');
                }
                $stmt = $pdo->prepare("UPDATE tutorial_videos SET title = ?, url = ?, file_path = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$title, '', $file_path, $description, $sort_order, $is_active, $id]);
                $message = 'Tutorial video updated.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Invalid video delete request.');
                }
                $old_stmt = $pdo->prepare("SELECT file_path FROM tutorial_videos WHERE id = ?");
                $old_stmt->execute([$id]);
                $old_video = $old_stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("DELETE FROM tutorial_videos WHERE id = ?");
                $stmt->execute([$id]);
                if (!empty($old_video['file_path'])) {
                    $old_file = $upload_directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $old_video['file_path']);
                    if (is_file($old_file)) {
                        @unlink($old_file);
                    }
                }
                $message = 'Tutorial video deleted.';
            }
        } elseif ($content_type === 'article') {
            if ($action === 'create') {
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $link_url = trim($_POST['link_url'] ?? '');
                $published_raw = trim($_POST['published_at'] ?? '');
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                if ($title === '' || $content === '') {
                    throw new RuntimeException('Article title and body are required.');
                }
                if ($link_url !== '' && !filter_var($link_url, FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('External link must be a valid URL (include https://).');
                }
                $attachment_path = '';
                if (isset($_FILES['article_file']) && ($_FILES['article_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $attachment_path = save_uploaded_article_pdf($_FILES['article_file'], $article_directory);
                }
                $published_at = $published_raw === '' ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $published_raw)));
                if ($published_at === false || $published_at === '1970-01-01 00:00:00') {
                    $published_at = date('Y-m-d H:i:s');
                }
                $slug = 'article-' . time() . '-' . bin2hex(random_bytes(4));
                $stmt = $pdo->prepare("INSERT INTO articles (title, slug, content, published_at, link_url, attachment_path, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $content, $published_at, $link_url, $attachment_path, $is_active]);
                $message = 'Help article added. It appears on the public homepage when Active is checked.';
            } elseif ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $link_url = trim($_POST['link_url'] ?? '');
                $published_raw = trim($_POST['published_at'] ?? '');
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                if ($id <= 0 || $title === '' || $content === '') {
                    throw new RuntimeException('Invalid article update request.');
                }
                if ($link_url !== '' && !filter_var($link_url, FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('External link must be a valid URL (include https://).');
                }
                $old_stmt = $pdo->prepare("SELECT attachment_path FROM articles WHERE id = ?");
                $old_stmt->execute([$id]);
                $old_row = $old_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$old_row) {
                    throw new RuntimeException('Article not found.');
                }
                $attachment_path = $old_row['attachment_path'] ?? '';
                if (!empty($_POST['remove_attachment'])) {
                    if ($attachment_path !== '') {
                        $old_file = $upload_directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $attachment_path);
                        if (is_file($old_file)) {
                            @unlink($old_file);
                        }
                    }
                    $attachment_path = '';
                } elseif (isset($_FILES['article_file']) && ($_FILES['article_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $new_path = save_uploaded_article_pdf($_FILES['article_file'], $article_directory);
                    if ($attachment_path !== '') {
                        $old_file = $upload_directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $attachment_path);
                        if (is_file($old_file)) {
                            @unlink($old_file);
                        }
                    }
                    $attachment_path = $new_path;
                }
                $published_at = $published_raw === '' ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $published_raw)));
                if ($published_at === false || $published_at === '1970-01-01 00:00:00') {
                    $old_pub = $pdo->prepare("SELECT published_at FROM articles WHERE id = ?");
                    $old_pub->execute([$id]);
                    $published_at = $old_pub->fetchColumn() ?: date('Y-m-d H:i:s');
                }
                $stmt = $pdo->prepare("UPDATE articles SET title = ?, content = ?, published_at = ?, link_url = ?, attachment_path = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$title, $content, $published_at, $link_url, $attachment_path, $is_active, $id]);
                $message = 'Article updated.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Invalid article delete request.');
                }
                $old_stmt = $pdo->prepare("SELECT attachment_path FROM articles WHERE id = ?");
                $old_stmt->execute([$id]);
                $old_row = $old_stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("DELETE FROM articles WHERE id = ?");
                $stmt->execute([$id]);
                if (!empty($old_row['attachment_path'])) {
                    $old_file = $upload_directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $old_row['attachment_path']);
                    if (is_file($old_file)) {
                        @unlink($old_file);
                    }
                }
                $message = 'Article deleted.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$announcements = $pdo->query("SELECT * FROM announcements ORDER BY posted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$videos = $pdo->query("SELECT * FROM tutorial_videos ORDER BY sort_order ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$articles = $pdo->query("SELECT * FROM articles ORDER BY published_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Manager - Supervisor</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="../theme.js" defer></script>
    <style>
        .content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
                .compact-input { width: 100%; }
                .row-actions { display: flex; gap: 0.5rem; margin-top: 0.6rem; }
                .inline-checkbox { display: inline-flex; align-items: center; gap: 0.4rem; margin-top: 0.5rem; }
                .upload-dropzone {
                    border: 2px dashed #bfd0eb;
                    border-radius: 12px;
                    background: #f8fbff;
                    padding: 0.95rem 0.9rem;
                    text-align: center;
                    cursor: pointer;
                    transition: border-color 0.2s ease, background 0.2s ease;
                }
                .upload-dropzone:hover,
                .upload-dropzone.is-active {
                    border-color: #6ea0ef;
                    background: #edf4ff;
                }
                .upload-dropzone-title {
                    font-size: 0.92rem;
                    color: #1f3a68;
                    font-weight: 600;
                }
                .upload-dropzone-note {
                    margin-top: 0.22rem;
                    font-size: 0.76rem;
                    color: #6c7ea1;
                }
                .upload-file-chip {
                    margin-top: 0.46rem;
                    display: inline-block;
                    font-size: 0.78rem;
                    color: #194b9a;
                    background: #e7f0ff;
                    border: 1px solid #bdd1f4;
                    border-radius: 999px;
                    padding: 0.18rem 0.52rem;
                    max-width: 100%;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
                .upload-preview {
                    margin-top: 0.5rem;
                    width: 100%;
                    max-height: 140px;
                    object-fit: cover;
                    border-radius: 8px;
                    border: 1px solid #d6e1f3;
                    display: none;
                    background: #f2f6ff;
                }
                .upload-preview.show { display: block; }
                .sr-only-input {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    padding: 0;
                    margin: -1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    border: 0;
                }
                .content-launchers {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(210px, 1fr));
                    gap: 0.8rem;
                    margin-bottom: 1rem;
                }
                .content-launch-btn {
                    border: 1px solid #cfe0fb;
                    background: #f5f9ff;
                    color: #1d417f;
                    border-radius: 12px;
                    padding: 0.85rem 0.9rem;
                    font-weight: 700;
                    cursor: pointer;
                    text-align: left;
                }
                .content-launch-btn small {
                    display: block;
                    margin-top: 0.24rem;
                    color: #5f769f;
                    font-weight: 500;
                    font-size: 0.8rem;
                }
                .content-modal-backdrop {
                    position: fixed;
                    inset: 0;
                    background: rgba(12, 22, 42, 0.6);
                    display: none;
                    align-items: center;
                    justify-content: center;
                    padding: 1rem;
                    z-index: 2200;
                }
                .content-modal-backdrop.open { display: flex; }
                .content-modal-card {
                    width: min(860px, 100%);
                    max-height: 92vh;
                    overflow-y: auto;
                    background: #fff;
                    border: 1px solid #d7e3f8;
                    border-radius: 14px;
                    box-shadow: 0 24px 56px rgba(9, 22, 48, 0.32);
                }
                .content-modal-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 0.7rem;
                    border-bottom: 1px solid #e4ecfb;
                    padding: 0.9rem 1rem;
                    position: sticky;
                    top: 0;
                    background: #fff;
                }
                .content-modal-head h2 {
                    margin: 0;
                    color: #163a74;
                    font-size: 1.15rem;
                }
                .content-modal-close {
                    border: 0;
                    background: transparent;
                    color: #3c5a88;
                    font-size: 1.4rem;
                    line-height: 1;
                    font-weight: 700;
                    cursor: pointer;
                }
                .content-modal-body {
                    padding: 1rem;
                }
                .content-modal-form .row-actions {
                    margin-top: 0.9rem;
                }
                .content-modal-helper {
                    color:#556487;
                    margin:0 0 0.85rem;
                    font-size:0.92rem;
                    line-height:1.45;
                }
                html[data-theme="dark"] .content-modal-card {
                    background: linear-gradient(180deg, #162235 0%, #1a2940 100%);
                    border-color: #2d3f5d;
                    box-shadow: 0 24px 56px rgba(0, 0, 0, 0.45);
                }
                html[data-theme="dark"] .content-modal-head {
                    background: #162235;
                    border-bottom-color: #2f425f;
                }
                html[data-theme="dark"] .content-modal-head h2,
                html[data-theme="dark"] .content-modal-form label,
                html[data-theme="dark"] .content-modal-helper {
                    color: #dce8ff;
                }
                html[data-theme="dark"] .content-modal-close {
                    color: #c5d7f6;
                }
                html[data-theme="dark"] .content-modal-form .compact-input,
                html[data-theme="dark"] .content-modal-form textarea {
                    background: #121d30;
                    color: #deebff;
                    border: 1px solid #334966;
                }
                html[data-theme="dark"] .content-modal-form .compact-input::placeholder,
                html[data-theme="dark"] .content-modal-form textarea::placeholder {
                    color: #94a9cc;
                }
                html[data-theme="dark"] .upload-dropzone {
                    border-color: #3c5274;
                    background: #121d30;
                }
                html[data-theme="dark"] .upload-dropzone:hover,
                html[data-theme="dark"] .upload-dropzone.is-active {
                    border-color: #7d5fd5;
                    background: #15243b;
                }
                html[data-theme="dark"] .upload-dropzone-title {
                    color: #d7e5ff;
                }
                html[data-theme="dark"] .upload-dropzone-note {
                    color: #9cb0d2;
                }
                html[data-theme="dark"] .upload-file-chip {
                    color: #d7e6ff;
                    background: #1f2f49;
                    border-color: #334967;
                }
                html[data-theme="dark"] .upload-preview {
                    background: #121d30;
                    border-color: #334967;
                }
                @media (max-width: 980px) {
                    .content-launchers { grid-template-columns: 1fr; }
                }
                @media (max-width: 980px) { .content-grid { grid-template-columns: 1fr; } }
    </style>

</head>
<body>
    <div class="dashboard-container">
<?php $supervisor_sidebar_active = 'content'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
        <main class="main-content">
            <header>
                <h1>Public Content Manager</h1>
                <div class="user-info"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
            </header>

            <?php if ($message): ?><div class="alert" style="background:#d8f4e5;color:#166243;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <section class="card">
                <h2>Add Content</h2>
                <div class="content-launchers">
                    <button type="button" class="content-launch-btn" data-open-modal="add-announcement-modal">Add Announcement<small>Create a public announcement with optional photo.</small></button>
                    <button type="button" class="content-launch-btn" data-open-modal="add-video-modal">Add Tutorial Video<small>Upload a tutorial video with title and details.</small></button>
                    <button type="button" class="content-launch-btn" data-open-modal="add-article-modal">Add Help Article<small>Publish help article with optional external link/PDF.</small></button>
                </div>
            </section>

            <div class="content-modal-backdrop" id="add-announcement-modal" aria-hidden="true">
                <div class="content-modal-card" role="dialog" aria-modal="true" aria-labelledby="add-announcement-title">
                    <div class="content-modal-head">
                        <h2 id="add-announcement-title">Add Announcement</h2>
                        <button type="button" class="content-modal-close" data-close-modal>&times;</button>
                    </div>
                    <div class="content-modal-body">
                        <form method="POST" enctype="multipart/form-data" class="content-modal-form">
                            <input type="hidden" name="content_type" value="announcement">
                            <input type="hidden" name="action" value="create">
                            <div class="form-group"><label>Title</label><input class="compact-input" type="text" name="title" required></div>
                            <div class="form-group"><label>Content</label><textarea class="compact-input" name="content" rows="4" required></textarea></div>
                            <div class="form-group">
                                <label>Photo (optional)</label>
                                <label class="upload-dropzone" for="announcement-create-image">
                                    <div class="upload-dropzone-title">Upload or capture photo</div>
                                    <div class="upload-dropzone-note">JPG, PNG, WEBP, GIF</div>
                                    <span class="upload-file-chip" id="announcement-create-image-name">No file selected</span>
                                    <img src="" alt="Announcement photo preview" class="upload-preview" id="announcement-create-image-preview">
                                </label>
                                <input id="announcement-create-image" class="sr-only-input upload-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif,image/*" capture="environment" data-file-label="announcement-create-image-name" data-file-preview="announcement-create-image-preview">
                            </div>
                            <label class="inline-checkbox"><input type="checkbox" name="is_active" checked> Active</label>
                            <div class="row-actions"><button class="btn btn-primary" type="submit">Add Announcement</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="content-modal-backdrop" id="add-video-modal" aria-hidden="true">
                <div class="content-modal-card" role="dialog" aria-modal="true" aria-labelledby="add-video-title">
                    <div class="content-modal-head">
                        <h2 id="add-video-title">Add Tutorial Video</h2>
                        <button type="button" class="content-modal-close" data-close-modal>&times;</button>
                    </div>
                    <div class="content-modal-body">
                        <form method="POST" enctype="multipart/form-data" class="content-modal-form">
                            <input type="hidden" name="content_type" value="video">
                            <input type="hidden" name="action" value="create">
                            <div class="form-group"><label>Title</label><input class="compact-input" type="text" name="title" required></div>
                            <div class="form-group">
                                <label>Video File (MP4/WEBM/OGG, max 60MB)</label>
                                <label class="upload-dropzone" for="video-create-file">
                                    <div class="upload-dropzone-title">Upload tutorial video file</div>
                                    <div class="upload-dropzone-note">MP4, WEBM, OGG</div>
                                    <span class="upload-file-chip" id="video-create-file-name">No file selected</span>
                                </label>
                                <input id="video-create-file" class="sr-only-input upload-input" type="file" name="video_file" accept="video/mp4,video/webm,video/ogg" required data-file-label="video-create-file-name">
                            </div>
                            <div class="form-group"><label>Description</label><textarea class="compact-input" name="description" rows="3"></textarea></div>
                            <div class="form-group"><label>Sort Order</label><input class="compact-input" type="number" name="sort_order" value="0"></div>
                            <label class="inline-checkbox"><input type="checkbox" name="is_active" checked> Active</label>
                            <div class="row-actions"><button class="btn btn-primary" type="submit">Add Video</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="content-modal-backdrop" id="add-article-modal" aria-hidden="true">
                <div class="content-modal-card" role="dialog" aria-modal="true" aria-labelledby="add-article-title">
                    <div class="content-modal-head">
                        <h2 id="add-article-title">Add Help Article</h2>
                        <button type="button" class="content-modal-close" data-close-modal>&times;</button>
                    </div>
                    <div class="content-modal-body">
                        <p class="content-modal-helper">
                            These entries appear in <strong>Help &amp; Tutorials</strong> on the public homepage (newest first).
                            Use the body for a short article or summary; optionally add an external link and/or a PDF attachment.
                        </p>
                        <form method="POST" enctype="multipart/form-data" class="content-modal-form">
                            <input type="hidden" name="content_type" value="article">
                            <input type="hidden" name="action" value="create">
                            <div class="form-group"><label>Title</label><input class="compact-input" type="text" name="title" required placeholder="e.g. How to pay a violation"></div>
                            <div class="form-group"><label>Body text</label><textarea class="compact-input" name="content" rows="5" required placeholder="Article text shown on the homepage (can be a summary if you attach a full PDF)."></textarea></div>
                            <div class="form-group"><label>External link (optional)</label><input class="compact-input" type="url" name="link_url" placeholder="https://example.gov.ph/page"></div>
                            <div class="form-group">
                                <label>PDF attachment (optional, max 15MB)</label>
                                <label class="upload-dropzone" for="article-create-file">
                                    <div class="upload-dropzone-title">Upload PDF attachment</div>
                                    <div class="upload-dropzone-note">PDF only</div>
                                    <span class="upload-file-chip" id="article-create-file-name">No file selected</span>
                                </label>
                                <input id="article-create-file" class="sr-only-input upload-input" type="file" name="article_file" accept="application/pdf" data-file-label="article-create-file-name">
                            </div>
                            <div class="form-group"><label>Publish date</label><input class="compact-input" type="datetime-local" name="published_at" value="<?php echo htmlspecialchars(date('Y-m-d\TH:i')); ?>"></div>
                            <label class="inline-checkbox"><input type="checkbox" name="is_active" checked> Show on homepage</label>
                            <div class="row-actions"><button class="btn btn-primary" type="submit">Publish article</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <section class="card">
                <h2>Manage Announcements</h2>
                <?php if (empty($announcements)): ?>
                    <p>No announcements yet.</p>
                <?php else: ?>
                    <?php foreach ($announcements as $item): ?>
                        <form method="POST" enctype="multipart/form-data" style="border:1px solid #e2e8f6;border-radius:10px;padding:0.9rem;margin-bottom:0.8rem;">
                            <input type="hidden" name="content_type" value="announcement">
                            <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                            <div class="form-group"><label>Title</label><input class="compact-input" type="text" name="title" value="<?php echo htmlspecialchars($item['title']); ?>" required></div>
                            <div class="form-group"><label>Content</label><textarea class="compact-input" name="content" rows="3" required><?php echo htmlspecialchars($item['content']); ?></textarea></div>
                            <div class="form-group">
                                <label>Current Photo</label>
                                <div><?php echo !empty($item['image_path']) ? htmlspecialchars($item['image_path']) : 'No photo uploaded'; ?></div>
                            </div>
                            <div class="form-group">
                                <label>Replace Photo (optional)</label>
                                <label class="upload-dropzone" for="announcement-replace-image-<?php echo (int)$item['id']; ?>">
                                    <div class="upload-dropzone-title">Upload or capture replacement photo</div>
                                    <div class="upload-dropzone-note">JPG, PNG, WEBP, GIF</div>
                                    <span class="upload-file-chip" id="announcement-replace-image-name-<?php echo (int)$item['id']; ?>">No file selected</span>
                                    <img src="" alt="Replacement photo preview" class="upload-preview" id="announcement-replace-image-preview-<?php echo (int)$item['id']; ?>">
                                </label>
                                <input id="announcement-replace-image-<?php echo (int)$item['id']; ?>" class="sr-only-input upload-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif,image/*" capture="environment" data-file-label="announcement-replace-image-name-<?php echo (int)$item['id']; ?>" data-file-preview="announcement-replace-image-preview-<?php echo (int)$item['id']; ?>">
                            </div>
                            <label class="inline-checkbox"><input type="checkbox" name="is_active" <?php echo (int)$item['is_active'] === 1 ? 'checked' : ''; ?>> Active</label>
                            <div class="row-actions">
                                <button type="submit" name="action" value="update" class="btn" style="background:#2a7de1;color:white;">Save</button>
                                <button type="submit" name="action" value="delete" class="btn" style="background:#d64545;color:white;" onclick="return confirm('Delete this announcement?');">Delete</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Manage Tutorial Videos</h2>
                <?php if (empty($videos)): ?>
                    <p>No tutorial videos yet.</p>
                <?php else: ?>
                    <?php foreach ($videos as $item): ?>
                        <form method="POST" enctype="multipart/form-data" style="border:1px solid #e2e8f6;border-radius:10px;padding:0.9rem;margin-bottom:0.8rem;">
                            <input type="hidden" name="content_type" value="video">
                            <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                            <div class="form-group"><label>Title</label><input class="compact-input" type="text" name="title" value="<?php echo htmlspecialchars($item['title']); ?>" required></div>
                            <div class="form-group">
                                <label>Current File</label>
                                <div><?php echo !empty($item['file_path']) ? htmlspecialchars($item['file_path']) : 'No file uploaded'; ?></div>
                            </div>
                            <div class="form-group">
                                <label>Replace File (optional)</label>
                                <label class="upload-dropzone" for="video-replace-file-<?php echo (int)$item['id']; ?>">
                                    <div class="upload-dropzone-title">Upload replacement video file</div>
                                    <div class="upload-dropzone-note">MP4, WEBM, OGG</div>
                                    <span class="upload-file-chip" id="video-replace-file-name-<?php echo (int)$item['id']; ?>">No file selected</span>
                                </label>
                                <input id="video-replace-file-<?php echo (int)$item['id']; ?>" class="sr-only-input upload-input" type="file" name="video_file" accept="video/mp4,video/webm,video/ogg" data-file-label="video-replace-file-name-<?php echo (int)$item['id']; ?>">
                            </div>
                            <div class="form-group"><label>Description</label><textarea class="compact-input" name="description" rows="3"><?php echo htmlspecialchars($item['description']); ?></textarea></div>
                            <div class="form-group"><label>Sort Order</label><input class="compact-input" type="number" name="sort_order" value="<?php echo (int)$item['sort_order']; ?>"></div>
                            <label class="inline-checkbox"><input type="checkbox" name="is_active" <?php echo (int)$item['is_active'] === 1 ? 'checked' : ''; ?>> Active</label>
                            <div class="row-actions">
                                <button type="submit" name="action" value="update" class="btn" style="background:#2a7de1;color:white;">Save</button>
                                <button type="submit" name="action" value="delete" class="btn" style="background:#d64545;color:white;" onclick="return confirm('Delete this video?');">Delete</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Manage Help Articles</h2>
                <?php if (empty($articles)): ?>
                    <p>No help articles yet. Use the form above to add one.</p>
                <?php else: ?>
                    <?php foreach ($articles as $item): ?>
                        <?php
                        $pub_ts = strtotime($item['published_at'] ?? '');
                        $pub_local = $pub_ts ? date('Y-m-d\TH:i', $pub_ts) : '';
                        ?>
                        <form method="POST" enctype="multipart/form-data" style="border:1px solid #e2e8f6;border-radius:10px;padding:0.9rem;margin-bottom:0.8rem;">
                            <input type="hidden" name="content_type" value="article">
                            <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                            <div class="form-group"><label>Title</label><input class="compact-input" type="text" name="title" value="<?php echo htmlspecialchars($item['title']); ?>" required></div>
                            <div class="form-group"><label>Body text</label><textarea class="compact-input" name="content" rows="4" required><?php echo htmlspecialchars($item['content']); ?></textarea></div>
                            <div class="form-group"><label>External link</label><input class="compact-input" type="url" name="link_url" value="<?php echo htmlspecialchars($item['link_url'] ?? ''); ?>" placeholder="https://"></div>
                            <div class="form-group">
                                <label>Current PDF</label>
                                <div><?php echo !empty($item['attachment_path']) ? htmlspecialchars($item['attachment_path']) : 'None'; ?></div>
                            </div>
                            <div class="form-group">
                                <label>Replace PDF (optional)</label>
                                <label class="upload-dropzone" for="article-replace-file-<?php echo (int)$item['id']; ?>">
                                    <div class="upload-dropzone-title">Upload replacement PDF</div>
                                    <div class="upload-dropzone-note">PDF only</div>
                                    <span class="upload-file-chip" id="article-replace-file-name-<?php echo (int)$item['id']; ?>">No file selected</span>
                                </label>
                                <input id="article-replace-file-<?php echo (int)$item['id']; ?>" class="sr-only-input upload-input" type="file" name="article_file" accept="application/pdf" data-file-label="article-replace-file-name-<?php echo (int)$item['id']; ?>">
                            </div>
                            <?php if (!empty($item['attachment_path'])): ?>
                                <label class="inline-checkbox"><input type="checkbox" name="remove_attachment" value="1"> Remove PDF attachment</label>
                            <?php endif; ?>
                            <div class="form-group"><label>Publish date</label><input class="compact-input" type="datetime-local" name="published_at" value="<?php echo htmlspecialchars($pub_local); ?>"></div>
                            <label class="inline-checkbox"><input type="checkbox" name="is_active" <?php echo (int)($item['is_active'] ?? 1) === 1 ? 'checked' : ''; ?>> Show on homepage</label>
                            <div class="row-actions">
                                <button type="submit" name="action" value="update" class="btn" style="background:#2a7de1;color:white;">Save</button>
                                <button type="submit" name="action" value="delete" class="btn" style="background:#d64545;color:white;" onclick="return confirm('Delete this article?');">Delete</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script>
        (function () {
            const modals = document.querySelectorAll('.content-modal-backdrop');
            const openButtons = document.querySelectorAll('[data-open-modal]');
            const closeButtons = document.querySelectorAll('[data-close-modal]');
            const closeAllModals = function () {
                modals.forEach(function (modal) {
                    modal.classList.remove('open');
                    modal.setAttribute('aria-hidden', 'true');
                });
            };
            openButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const target = button.getAttribute('data-open-modal');
                    const modal = target ? document.getElementById(target) : null;
                    if (!modal) {
                        return;
                    }
                    closeAllModals();
                    modal.classList.add('open');
                    modal.setAttribute('aria-hidden', 'false');
                });
            });
            closeButtons.forEach(function (button) {
                button.addEventListener('click', closeAllModals);
            });
            modals.forEach(function (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        closeAllModals();
                    }
                });
            });

            const fileInputs = document.querySelectorAll('.upload-input');
            fileInputs.forEach(function (input) {
                const labelId = input.getAttribute('data-file-label');
                const previewId = input.getAttribute('data-file-preview');
                const fileChip = labelId ? document.getElementById(labelId) : null;
                const preview = previewId ? document.getElementById(previewId) : null;
                const dropzone = input.previousElementSibling && input.previousElementSibling.classList.contains('upload-dropzone')
                    ? input.previousElementSibling
                    : null;

                const updateView = function (file) {
                    if (fileChip) {
                        fileChip.textContent = file ? file.name : 'No file selected';
                    }
                    if (preview) {
                        if (!file || !file.type.startsWith('image/')) {
                            preview.src = '';
                            preview.classList.remove('show');
                            return;
                        }
                        const reader = new FileReader();
                        reader.onload = function (event) {
                            preview.src = String(event.target && event.target.result ? event.target.result : '');
                            preview.classList.add('show');
                        };
                        reader.readAsDataURL(file);
                    }
                };

                input.addEventListener('change', function () {
                    const file = input.files && input.files[0] ? input.files[0] : null;
                    updateView(file);
                });

                if (!dropzone) {
                    return;
                }
                ['dragenter', 'dragover'].forEach(function (eventName) {
                    dropzone.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        dropzone.classList.add('is-active');
                    });
                });
                ['dragleave', 'drop'].forEach(function (eventName) {
                    dropzone.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        dropzone.classList.remove('is-active');
                    });
                });
                dropzone.addEventListener('drop', function (event) {
                    const files = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : null;
                    if (!files || files.length === 0) {
                        return;
                    }
                    input.files = files;
                    updateView(files[0]);
                });
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeAllModals();
                }
            });
        }());
    </script>
</body>
</html>
