<?php
require_once __DIR__ . '/db.php';

$violation_id = isset($_GET['violation_id']) ? (int)$_GET['violation_id'] : 0;
$violation = null;
$evidence_items = [];

if ($violation_id > 0) {
    $violation_stmt = $pdo->prepare("SELECT v.id,
                                            v.top_number,
                                            v.violation_date,
                                            v.location,
                                            v.fine_amount,
                                            v.status,
                                            COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') as violation_display,
                                            COALESCE(m.full_name, 'Unknown') as motorist_name
                                     FROM violations v
                                     LEFT JOIN penalties p ON v.penalty_id = p.id
                                     LEFT JOIN motorists m ON v.motorist_id = m.id
                                     WHERE v.id = ?
                                     LIMIT 1");
    $violation_stmt->execute([$violation_id]);
    $violation = $violation_stmt->fetch(PDO::FETCH_ASSOC);

    if ($violation) {
        $evidence_stmt = $pdo->prepare("SELECT file_path,
                                               COALESCE(evidence_label, '') as evidence_label,
                                               COALESCE(evidence_type, 'Evidence') as evidence_type
                                        FROM evidence
                                        WHERE violation_id = ?
                                          AND COALESCE(file_path, '') <> ''
                                        ORDER BY uploaded_at DESC, id DESC");
        $evidence_stmt->execute([$violation_id]);
        $evidence_items = $evidence_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Violation Evidence Document</title>
    <link rel="stylesheet" href="style.css?v=20260425">
    <style>
        body { background: #f2f6ff; }
        .doc-wrap {
            max-width: 980px;
            margin: 18px auto;
            background: #fff;
            border: 1px solid #d7e3fa;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 8px 20px rgba(18, 48, 106, 0.08);
        }
        .doc-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }
        .doc-title {
            margin: 0;
            color: #0d2f6f;
            font-size: 1.5rem;
        }
        .doc-sub {
            margin: 4px 0 0;
            color: #536490;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 8px;
            margin: 12px 0;
        }
        .meta-item {
            border: 1px solid #dbe6fa;
            background: #f8fbff;
            border-radius: 8px;
            padding: 8px 10px;
        }
        .meta-item strong {
            display: block;
            font-size: 0.78rem;
            color: #24457f;
            margin-bottom: 4px;
        }
        .evidence-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        .evidence-card {
            border: 1px solid #d7e3fa;
            border-radius: 10px;
            overflow: hidden;
            background: #f9fbff;
        }
        .evidence-card img {
            width: 100%;
            height: 170px;
            object-fit: cover;
            display: block;
            background: #edf3ff;
        }
        .evidence-meta {
            padding: 8px 10px;
            font-size: 0.84rem;
            color: #38507e;
        }
        .evidence-meta strong {
            display: block;
            color: #15376f;
            margin-bottom: 2px;
        }
    </style>
</head>
<body>
    <div class="doc-wrap">
        <div class="doc-head">
            <div>
                <h1 class="doc-title">Violation Evidence Document</h1>
                <p class="doc-sub">All uploaded evidence in one view.</p>
            </div>
            <a class="btn" href="index.php">Back</a>
        </div>

        <?php if (!$violation): ?>
            <div class="alert alert-danger">Violation record not found.</div>
        <?php else: ?>
            <div class="meta-grid">
                <div class="meta-item"><strong>TOP Number</strong><?php echo htmlspecialchars($violation['top_number'] ?: 'N/A'); ?></div>
                <div class="meta-item"><strong>Motorist</strong><?php echo htmlspecialchars($violation['motorist_name']); ?></div>
                <div class="meta-item"><strong>Violation</strong><?php echo htmlspecialchars($violation['violation_display']); ?></div>
                <div class="meta-item"><strong>Date</strong><?php echo !empty($violation['violation_date']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($violation['violation_date']))) : 'N/A'; ?></div>
                <div class="meta-item"><strong>Location</strong><?php echo htmlspecialchars($violation['location'] ?: 'N/A'); ?></div>
                <div class="meta-item"><strong>Amount</strong>₱<?php echo number_format((float)$violation['fine_amount'], 2); ?></div>
                <div class="meta-item"><strong>Status</strong><?php echo htmlspecialchars(ucfirst($violation['status'])); ?></div>
                <div class="meta-item"><strong>Total Evidence</strong><?php echo number_format(count($evidence_items)); ?></div>
            </div>

            <?php if (empty($evidence_items)): ?>
                <div class="alert alert-info">No uploaded evidence found for this violation.</div>
            <?php else: ?>
                <div class="evidence-grid">
                    <?php foreach ($evidence_items as $item): ?>
                        <?php
                        $label = trim((string)($item['evidence_label'] ?? ''));
                        $type = trim((string)($item['evidence_type'] ?? 'Evidence'));
                        $title = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $type));
                        $path = trim((string)($item['file_path'] ?? ''));
                        ?>
                        <a class="evidence-card" href="uploads/<?php echo htmlspecialchars($path); ?>" target="_blank" rel="noopener noreferrer">
                            <img src="uploads/<?php echo htmlspecialchars($path); ?>" alt="Evidence">
                            <div class="evidence-meta">
                                <strong><?php echo htmlspecialchars($title); ?></strong>
                                Click to open full image
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
