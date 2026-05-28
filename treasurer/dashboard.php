<?php
require_once '../auth.php';
check_role('treasurer');

global $pdo;
$conn = $pdo;

// Handle Add Violation Form Submission
if (isset($_POST['add_violation'])) {
    $license_number = trim($_POST['license_number'] ?? '');
    $motorist_name = trim($_POST['motorist_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $plate = trim($_POST['plate'] ?? '');
    $penalty_id = $_POST['penalty_id'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $violation_date = $_POST['violation_date'] ?? date('Y-m-d');
    $confiscated = $_POST['confiscated'] ?? 'None';
    
    if (empty($license_number) || empty($motorist_name) || empty($penalty_id) || empty($location)) {
        $error = "Please fill in all required fields.";
    } else {
        // Get penalty amount
        $p_stmt = $conn->prepare("SELECT fine_amount, violation_name FROM penalties WHERE id = ?");
        $p_stmt->execute([$penalty_id]);
        $penalty_data = $p_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($penalty_data) {
            $fine_amount = $penalty_data['fine_amount'];
            $violation_name = $penalty_data['violation_name'];
            
            // Check or create motorist
            $m_stmt = $conn->prepare("SELECT id FROM motorists WHERE license_number = ?");
            $m_stmt->execute([$license_number]);
            
            if ($m_row = $m_stmt->fetch(PDO::FETCH_ASSOC)) {
                // Update existing motorist info
                $upd_m = $conn->prepare("UPDATE motorists SET full_name = ?, address = ?, contact_number = ?, plate = ? WHERE id = ?");
                $upd_m->execute([$motorist_name, $address, $contact_number, $plate, $m_row['id']]);
                $motorist_id = $m_row['id'];
            } else {
                // Create new motorist
                $ins_m = $conn->prepare("INSERT INTO motorists (license_number, full_name, address, contact_number, plate) VALUES (?, ?, ?, ?, ?)");
                $ins_m->execute([$license_number, $motorist_name, $address, $contact_number, $plate]);
                $motorist_id = $conn->lastInsertId();
                
                // Create offense count record
                $offense_stmt = $conn->prepare("INSERT INTO motorist_offense_counts (motorist_id, offense_count, last_violation_at) VALUES (?, 1, NOW())");
                $offense_stmt->execute([$motorist_id]);
            }
            
            // Generate TOP number for treasurer-added violation
            $top_number = 'TREASURER-' . time() . '-' . rand(1000, 9999);
            
            // Insert violation (enforcer_id is NULL for treasurer-added)
            $treasurer_id = $_SESSION['user_id'];
            
            $v_stmt = $conn->prepare("INSERT INTO violations (motorist_id, enforcer_id, penalty_id, location, fine_amount, top_number, status, confiscated_items, violation_date, source) VALUES (?, NULL, ?, ?, ?, ?, 'validated', ?, ?, 'treasurer')");
            
            if ($v_stmt->execute([$motorist_id, $penalty_id, $location, $fine_amount, $top_number, $confiscated, $violation_date])) {
                $violation_id = $conn->lastInsertId();
                
                // Log in audit trail
                $audit_stmt = $conn->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
                $audit_stmt->execute([$treasurer_id, 'add_violation', 'violations', $violation_id, "Violation added by treasurer: $violation_name - $top_number"]);
                
                $success = "Violation recorded successfully! TOP Number: " . $top_number . " (Added by Treasurer)";
            } else {
                $error = "Error recording violation. Please try again.";
            }
        } else {
            $error = "Invalid violation type selected.";
        }
    }
}

// Handle Payment
$last_payment_id = 0;
if (isset($_POST['mark_paid']) && isset($_POST['violation_id'])) {
    $v_id = $_POST['violation_id'];
    $amount = $_POST['amount'];
    $treasurer_id = $_SESSION['user_id'];
    $receipt_no = 'REC-' . time() . '-' . rand(1000, 9999);
    
    $conn->beginTransaction();
    try {
        // Insert payment
        $p_stmt = $conn->prepare("INSERT INTO payments (violation_id, treasurer_id, receipt_number, payment_amount) VALUES (?, ?, ?, ?)");
        $p_stmt->execute([$v_id, $treasurer_id, $receipt_no, $amount]);
        $last_payment_id = $conn->lastInsertId();
        
        // Update violation status
        $v_stmt = $conn->prepare("UPDATE violations SET status = 'paid' WHERE id = ?");
        $v_stmt->execute([$v_id]);
        
        $conn->commit();
        $success = "Payment recorded successfully! Receipt: " . $receipt_no;
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error recording payment." . $e->getMessage();
    }
}

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Stats
$revenue_res = $conn->query("SELECT SUM(payment_amount) as total FROM payments");
$revenue = $revenue_res ? ($revenue_res->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) : 0;

// Today's Collection
$today_res = $conn->query("SELECT SUM(payment_amount) as total FROM payments WHERE DATE(payment_date) = DATE('now')");
$today_collection = $today_res ? ($today_res->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) : 0;

$validated_count_res = $conn->query("SELECT COUNT(*) as count FROM violations WHERE status = 'validated'");
$validated_count = $validated_count_res ? ($validated_count_res->fetch(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

// Fetch validated violations for payment (with optional search)
if ($search) {
    $validated_res = $conn->prepare("SELECT v.*, m.full_name as motorist_name, COALESCE(p.violation_name, v.violation_details) as violation_display 
                               FROM violations v 
                               LEFT JOIN motorists m ON v.motorist_id = m.id 
                               LEFT JOIN penalties p ON v.penalty_id = p.id 
                               WHERE v.status = 'validated' 
                               AND (m.full_name LIKE ? OR COALESCE(p.violation_name, v.violation_details) LIKE ? OR v.top_number LIKE ?)
                               ORDER BY v.violation_date DESC");
    $search_param = "%$search%";
    $validated_res->execute([$search_param, $search_param, $search_param]);
    $validated_violations = $validated_res->fetchAll(PDO::FETCH_ASSOC);
} else {
    $validated_res = $conn->query("SELECT v.*, m.full_name as motorist_name, COALESCE(p.violation_name, v.violation_details) as violation_display 
                               FROM violations v 
                               LEFT JOIN motorists m ON v.motorist_id = m.id 
                               LEFT JOIN penalties p ON v.penalty_id = p.id 
                               WHERE v.status = 'validated' 
                               ORDER BY v.violation_date DESC");
    $validated_violations = $validated_res ? $validated_res->fetchAll(PDO::FETCH_ASSOC) : [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/theme_early.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treasurer Dashboard - Traffic Management System</title>
    <link rel="stylesheet" href="../style.css?v=20260425">
    <script src="../theme.js" defer></script>
    <style>
        .stat-card {
                    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
                    border-radius: 16px;
                    padding: 1.5rem;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
                    transition: transform 0.3s ease, box-shadow 0.3s ease;
                }
                .stat-card:hover {
                    transform: translateY(-5px);
                    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
                }
                .stat-icon {
                    width: 60px;
                    height: 60px;
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 1.5rem;
                }
                .stat-icon.revenue {
                    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
                }
                .stat-icon.pending {
                    background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
                }
                .stat-icon.today {
                    background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
                }
                .stat-content h3 {
                    font-size: 0.85rem;
                    color: #6c757d;
                    margin-bottom: 0.25rem;
                    font-weight: 500;
                }
                .stat-content .value {
                    font-size: 1.75rem;
                    font-weight: 700;
                    color: #212529;
                }
                .card {
                    background: #fff;
                    border-radius: 16px;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
                    margin-bottom: 1.5rem;
                    overflow: hidden;
                }
                .card-header {
                    background: linear-gradient(135deg, #004a99 0%, #007bff 100%);
                    color: white;
                    padding: 1rem 1.5rem;
                    border-radius: 16px 16px 0 0;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 1rem;
                }
                .card-header h2 {
                    margin: 0;
                    font-size: 1.25rem;
                }
                .card-body {
                    padding: 1.5rem;
                }
                table {
                    width: 100%;
                    border-collapse: separate;
                    border-spacing: 0;
                }
                table th {
                    background: #f8f9fa;
                    padding: 1rem;
                    font-weight: 600;
                    color: #495057;
                    border-bottom: 2px solid #dee2e6;
                }
                table td {
                    padding: 1rem;
                    border-bottom: 1px solid #f1f3f4;
                }
                table tr:last-child td {
                    border-bottom: none;
                }
                table tr:hover td {
                    background: #f8f9fa;
                }
                .btn {
                    padding: 0.5rem 1rem;
                    border-radius: 8px;
                    font-weight: 500;
                    transition: all 0.3s ease;
                    text-decoration: none;
                    display: inline-block;
                }
                .btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
                }
                .btn-success {
                    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                    border: none;
                    color: white;
                }
                .btn-info {
                    background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);
                    border: none;
                    color: white;
                }
                .btn-print {
                    background: linear-gradient(135deg, #004a99 0%, #007bff 100%);
                    border: none;
                    color: white;
                }
                .btn-print:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 15px rgba(0, 74, 153, 0.4);
                }
                /* Search Styles */
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
                    max-width: 400px;
                    transition: all 0.3s ease;
                }
                .search-input::placeholder {
                    color: #6c757d;
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
                    color: white;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    font-weight: 500;
                    font-size: 0.95rem;
                }
                .search-btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(0, 74, 153, 0.35);
                }
                .clear-search {
                    padding: 0.7rem 1rem;
                    background: #fff;
                    border: 2px solid #dee2e6;
                    border-radius: 8px;
                    color: #6c757d;
                    text-decoration: none;
                    transition: all 0.3s ease;
                    font-weight: 500;
                    font-size: 0.95rem;
                }
                .clear-search:hover {
                    background: #e9ecef;
                    color: #212529;
                    border-color: #adb5bd;
                }
                .no-results {
                    text-align: center;
                    padding: 2rem;
                    color: #6c757d;
                }
                .alert-actions {
                    display: flex;
                    gap: 10px;
                    margin-top: 15px;
                    flex-wrap: wrap;
                }
                .role-hero-banner {
                    margin-bottom: 1rem;
                    padding: 4.4rem 1.8rem;
                    min-height: 360px;
                    border-radius: 14px;
                    border: 1px solid #7ab3f0;
                    position: relative;
                    overflow: hidden;
                    text-align: center;
                    background:
                        linear-gradient(120deg, rgba(32, 116, 220, 0.62) 0%, rgba(79, 163, 245, 0.56) 45%, rgba(23, 98, 200, 0.66) 100%),
                        radial-gradient(circle at 16% 78%, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0) 40%),
                        radial-gradient(circle at 88% 18%, rgba(255, 255, 255, 0.18) 0%, rgba(255, 255, 255, 0) 36%),
                        url("../assets/images/pototan-hall-wide.png") center 44% / cover no-repeat;
                    box-shadow: 0 14px 32px rgba(25, 88, 163, 0.26);
                    animation: roleHeroBackgroundShift 14s ease-in-out infinite alternate;
                }
                .role-hero-banner h2 {
                    margin: 0;
                    color: #ffffff;
                    font-size: clamp(2.7rem, 5.8vw, 3.9rem);
                    text-shadow: 0 8px 18px rgba(13, 6, 34, 0.32);
                    position: relative;
                    z-index: 1;
                    opacity: 0;
                    transform: translateY(18px);
                    animation: roleHeroFadeUp 760ms ease-out 140ms forwards;
                }
                .role-hero-banner p {
                    margin: 0.95rem auto 0;
                    color: #eaf4ff;
                    line-height: 1.6;
                    font-size: 1.24rem;
                    max-width: 820px;
                    position: relative;
                    z-index: 1;
                    opacity: 0;
                    transform: translateY(16px);
                    animation: roleHeroFadeUp 760ms ease-out 320ms forwards;
                }
                @keyframes roleHeroFadeUp {
                    from { opacity: 0; transform: translateY(20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                @keyframes roleHeroBackgroundShift {
                    from { background-position: center 44%, center center, center center, center 44%; }
                    to { background-position: center 44%, center center, center center, center 50%; }
                }
                @media (prefers-reduced-motion: reduce) {
                    .role-hero-banner { animation: none; }
                    .role-hero-banner h2, .role-hero-banner p { animation: none; opacity: 1; transform: none; }
                }
    </style>

</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h2>💼 Municipal Treasurer</h2>
            <ul>
                <li><a href="dashboard.php" class="active">📊 Dashboard</a></li>
                <li><a href="payment_history.php">🧾 Payment History</a></li>
                <li><a href="revenue.php">📈 Revenue Analytics</a></li>
                <li><a href="../logout.php">🚪 Logout</a></li>

            </ul>
        </nav>
        <main class="main-content">
            <header>
                <h1>💰 Payment Management</h1>
                <div class="user-info">👤 <?php echo $_SESSION['full_name']; ?></div>
            </header>
            <section class="role-hero-banner">
                <h2>Welcome, <?php echo htmlspecialchars((string)($_SESSION['full_name'] ?? 'Treasurer')); ?>!</h2>
                <p>Manage collections, process payments quickly, and maintain accurate financial records for traffic violations.</p>
            </section>

            <?php if (isset($success) && $success != ''): ?>
                <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #28a745;">
                    ✅ <?php echo $success; ?>
                    <div class="alert-actions">
                        <a href="../receipt.php?id=<?php echo $last_payment_id; ?>" class="btn btn-print">🖨️ Print Receipt</a>
                        <a href="dashboard.php" class="clear-search" style="padding: 0.5rem 1rem;">New Payment</a>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (isset($error) && $error != ''): ?>
                <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #dc3545;">
                    ❌ <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem;">
                <div class="stat-card">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div class="stat-icon revenue">💰</div>
                        <div class="stat-content">
                            <h3>Total Revenue</h3>
                            <div class="value">₱<?php echo number_format($revenue, 2); ?></div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div class="stat-icon pending">⏳</div>
                        <div class="stat-content">
                            <h3>Pending Payments</h3>
                            <div class="value"><?php echo $validated_count; ?></div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div class="stat-icon today">📅</div>
                        <div class="stat-content">
                            <h3>Today's Collection</h3>
                            <div class="value">₱<?php echo number_format($today_collection, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search Bar - After stats cards -->
            <div class="card" style="padding: 1.25rem;">
                <form method="GET" action="" class="search-wrapper">
                    <input type="text" name="search" class="search-input" placeholder="Search by name, violation, or receipt #..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-btn">Search</button>
                    <?php if ($search != ''): ?>
                        <a href="dashboard.php" class="clear-search">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Validated Violations (Pending Payment)</h2>
                    <span style="font-size: 0.9rem; opacity: 0.9;"><?php echo count($validated_violations); ?> record(s)</span>
                </div>
                <div class="card-body">
                    <?php if (count($validated_violations) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Motorist</th>
                                    <th>Violation</th>
                                    <th>Amount</th>
                                    <th>Validated On</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($validated_violations as $v): ?>
                                    <tr>
                                        <td><?php echo $v['motorist_name']; ?></td>
                                        <td><?php echo htmlspecialchars($v['violation_display'] ?? 'Multiple/Custom'); ?></td>
                                        <td><strong>₱<?php echo number_format($v['fine_amount'], 2); ?></strong></td>
                                        <td><?php echo date('M d, Y', strtotime($v['violation_date'])); ?></td>
                                        <td>
                                            <form method="POST">
                                                <input type="hidden" name="violation_id" value="<?php echo $v['id']; ?>">
                                                <input type="hidden" name="amount" value="<?php echo $v['fine_amount']; ?>">
                                                <button type="submit" name="mark_paid" class="btn btn-success">Mark as Paid</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-results">
                            <?php if ($search != ''): ?>
                                No results found for "<?php echo htmlspecialchars($search); ?>"
                            <?php else: ?>
                                No pending payments.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
