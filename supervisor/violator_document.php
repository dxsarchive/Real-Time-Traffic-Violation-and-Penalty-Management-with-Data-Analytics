<?php
require_once '../auth.php';
check_role('supervisor');
require_once '../includes/motorist_profile.php';

global $pdo;
$conn = $pdo;

$motorist_id = isset($_GET['motorist_id']) ? (int)$_GET['motorist_id'] : 0;
$auto_download = isset($_GET['download']) && $_GET['download'] === '1';
$profile_data = fetch_motorist_profile_data($conn, $motorist_id);
$profile = $profile_data['profile'];
$violations = $profile_data['violations'];
$evidence_by_violation = $profile_data['evidence_by_violation'];
$profile_payload = build_motorist_profile_payload($profile, $violations, $evidence_by_violation);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Violator Document - Supervisor</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="../public/js/violator-pdf.js" defer></script>
    <script src="../theme.js" defer></script>
</head>
<body>
    <div class="dashboard-wrapper">
        <?php include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
        <main class="main-content">
            <div class="card">
                <h2>Violator Document</h2>
                <?php if ($profile): ?>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($profile['full_name'] ?? 'N/A'); ?></p>
                    <p><strong>License Number:</strong> <?php echo htmlspecialchars($profile['license_number'] ?? 'N/A'); ?></p>
                    <p><strong>Plate Number:</strong> <?php echo htmlspecialchars($profile['plate'] ?? 'N/A'); ?></p>
                    <p><strong>Address:</strong> <?php echo htmlspecialchars($profile['address'] ?? 'N/A'); ?></p>
                    <p><strong>Total Violations:</strong> <?php echo count($violations); ?></p>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px;">
                        <button type="button" class="btn" id="download-supervisor-profile-pdf" style="background: var(--secondary-color); color: #fff;">Download PDF</button>
                        <a class="btn" href="dashboard.php" style="text-decoration:none;">Back to Dashboard</a>
                    </div>
                <?php else: ?>
                    <p>Motorist profile not found.</p>
                    <a class="btn" href="dashboard.php" style="text-decoration:none;">Back to Dashboard</a>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <?php if ($profile_payload): ?>
        <script>
            window.motoristProfilePayload = <?php echo json_encode($profile_payload, JSON_UNESCAPED_UNICODE); ?>;
            document.addEventListener('DOMContentLoaded', function() {
                window.setupViolatorPdfDownload({
                    buttonId: 'download-supervisor-profile-pdf',
                    payload: window.motoristProfilePayload || null,
                    successMessage: 'Violator PDF generated successfully.'
                });
                <?php if ($auto_download): ?>
                const autoDownloadButton = document.getElementById('download-supervisor-profile-pdf');
                if (autoDownloadButton) {
                    autoDownloadButton.click();
                }
                <?php endif; ?>
            });
        </script>
    <?php endif; ?>
</body>
</html>
