<?php
require_once 'auth.php';
check_role('motorist');

$conn = get_db_connection();

require_once __DIR__ . '/includes/ensure_articles_schema.php';
ensure_articles_schema($conn, $conn->getAttribute(PDO::ATTR_DRIVER_NAME));

// Insert sample articles if none exist
$check = $conn->query("SELECT COUNT(*) as count FROM articles")->fetch(PDO::FETCH_ASSOC);
if ((int)$check['count'] === 0) {
    $ins = $conn->prepare("INSERT INTO articles (title, slug, content) VALUES (?, ?, ?)");
    $samples = [
        ['Traffic Rules Overview', 'sample-rules-1', 'Understanding the basic traffic rules in our municipality...'],
        ['How to Appeal a Violation', 'sample-appeal-1', 'If you believe a violation was issued in error, you can file an appeal...'],
        ['Payment Methods', 'sample-pay-1', 'Currently, we accept cash payments at the Municipal Treasurer office...'],
    ];
    foreach ($samples as $row) {
        $ins->execute($row);
    }
}

$articles = $conn->query("SELECT * FROM articles ORDER BY published_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutorials - Traffic Management System</title>
    <link rel="stylesheet" href="style.css?v=20260425">
    <script src="theme.js" defer></script>
</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>Motorist Portal</h2>
            <ul>
                <li><a href="dashboard.php">My Violations</a></li>
                <li><a href="tutorials.php" class="active">Help & Tutorials</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <main class="main-content">
            <header>
                <h1>Help & Instructional Articles</h1>
            </header>

            <?php while($a = $articles->fetch(PDO::FETCH_ASSOC)): ?>
                <div class="card">
                    <h2><?php echo $a['title']; ?></h2>
                    <p style="color: #666; font-size: 0.8rem; margin-bottom: 1rem;">Published on <?php echo date('M d, Y', strtotime($a['published_at'] ?? 'now')); ?></p>
                    <div class="article-content">
                        <?php echo nl2br(htmlspecialchars((string)$a['content'])); ?>
                    </div>
                    <?php
                    $tl = trim((string)($a['link_url'] ?? ''));
                    $tp = trim((string)($a['attachment_path'] ?? ''));
                    ?>
                    <?php if ($tl !== '' || $tp !== ''): ?>
                        <p style="margin-top:0.75rem;">
                            <?php if ($tl !== ''): ?>
                                <a href="<?php echo htmlspecialchars($tl); ?>" target="_blank" rel="noopener noreferrer">Open link</a>
                            <?php endif; ?>
                            <?php if ($tp !== ''): ?>
                                <?php if ($tl !== ''): ?> &nbsp;|&nbsp; <?php endif; ?>
                                <a href="uploads/<?php echo htmlspecialchars($tp); ?>" target="_blank" rel="noopener noreferrer">View PDF</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </main>
    </div>
</body>
</html>
