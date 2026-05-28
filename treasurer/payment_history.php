<?php
require_once '../auth.php';
check_role('treasurer');

global $pdo;
$conn = $pdo;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search) {
    $history_res = $conn->prepare("SELECT pay.*, COALESCE(m.full_name, 'Unknown') as motorist_name, COALESCE(p.violation_name, v.violation_details, 'Multiple/Custom') as violation_display
                             FROM payments pay
                             JOIN violations v ON pay.violation_id = v.id
                             LEFT JOIN motorists m ON v.motorist_id = m.id
                             LEFT JOIN penalties p ON v.penalty_id = p.id
                             WHERE m.full_name LIKE ? OR COALESCE(p.violation_name, v.violation_details) LIKE ? OR pay.receipt_number LIKE ?
                             ORDER BY pay.payment_date DESC LIMIT 100");
    $search_param = "%$search%";
    $history_res->execute([$search_param, $search_param, $search_param]);
    $payment_history = $history_res->fetchAll(PDO::FETCH_ASSOC);
} else {
    $history_res = $conn->query("SELECT pay.*, COALESCE(m.full_name, 'Unknown') as motorist_name, COALESCE(p.violation_name, v.violation_details, 'Multiple/Custom') as violation_display
                             FROM payments pay
                             JOIN violations v ON pay.violation_id = v.id
                             LEFT JOIN motorists m ON v.motorist_id = m.id
                             LEFT JOIN penalties p ON v.penalty_id = p.id
                             ORDER BY pay.payment_date DESC LIMIT 100");
    $payment_history = $history_res ? $history_res->fetchAll(PDO::FETCH_ASSOC) : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - Traffic Management System</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="../theme.js" defer></script>
    <style>
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #004a99 0%, #007bff 100%);
            color: #fff;
            padding: 1rem 1.5rem;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .card-header h2 {
            margin: 0;
            font-size: 1.2rem;
        }
        .card-body {
            padding: 1.5rem;
        }
        .search-card {
            padding: 1.25rem;
        }
        .search-wrapper {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .search-input {
            padding: 0.7rem 1rem;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            background: #fff;
            color: #212529;
            font-size: 0.95rem;
            width: 100%;
            max-width: 420px;
        }
        .search-input:focus {
            outline: none;
            border-color: #004a99;
            box-shadow: 0 0 0 3px rgba(0, 74, 153, 0.15);
        }
        .search-btn {
            padding: 0.7rem 1.25rem;
            background: linear-gradient(135deg, #004a99 0%, #007bff 100%);
            border: none;
            border-radius: 8px;
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }
        .clear-search {
            padding: 0.7rem 1rem;
            background: #fff;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            color: #6c757d;
            text-decoration: none;
            font-weight: 500;
        }
        .clear-search:hover {
            background: #e9ecef;
            color: #212529;
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        table th {
            background: #f8f9fa;
            padding: 0.9rem;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        table td {
            padding: 0.9rem;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: middle;
        }
        table tr:hover td {
            background: #f8f9fa;
        }
        .receipt-code {
            background: #e9ecef;
            color: #1f2937;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);
            color: #fff;
            border: none;
        }
        .no-results {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }
        html[data-theme="dark"] .receipt-code {
            background: #f3f4f6;
            color: #0f172a;
        }
        html[data-theme="dark"] table td,
        html[data-theme="dark"] table th {
            color: #e8eefc;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>💼 Municipal Treasurer</h2>
            <ul>
                <li><a href="dashboard.php">📊 Dashboard</a></li>
                <li><a href="payment_history.php" class="active">🧾 Payment History</a></li>
                <li><a href="revenue.php">📈 Revenue Analytics</a></li>
                <li><a href="../logout.php">🚪 Logout</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <header>
                <h1>🧾 Payment History</h1>
                <div class="user-info">👤 <?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
            </header>

            <div class="card search-card">
                <form method="GET" action="" class="search-wrapper">
                    <input type="text" name="search" class="search-input" placeholder="Search by receipt #, motorist, or violation..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-btn">Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="payment_history.php" class="clear-search">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Payment Records</h2>
                    <span><?php echo count($payment_history); ?> record(s)</span>
                </div>
                <div class="card-body">
                    <?php if (count($payment_history) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Motorist</th>
                                    <th>Violation</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_history as $h): ?>
                                    <tr>
                                        <td><code class="receipt-code"><?php echo htmlspecialchars($h['receipt_number']); ?></code></td>
                                        <td><?php echo htmlspecialchars($h['motorist_name']); ?></td>
                                        <td><?php echo htmlspecialchars($h['violation_display'] ?? 'Multiple/Custom'); ?></td>
                                        <td><strong>₱<?php echo number_format((float)$h['payment_amount'], 2); ?></strong></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($h['payment_date'])); ?></td>
                                        <td><a href="../receipt.php?id=<?php echo (int)$h['id']; ?>" class="btn btn-info">View Receipt</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-results">
                            <?php if ($search !== ''): ?>
                                No results found for "<?php echo htmlspecialchars($search); ?>"
                            <?php else: ?>
                                No payment history yet.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
