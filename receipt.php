<?php
require_once 'auth.php';
if (!is_logged_in()) { header("Location: ../index.php"); exit(); }

global $pdo;
$conn = $pdo;
$p_id = $_GET['id'] ?? 0;

$stmt = $conn->prepare("SELECT pay.*, v.violation_date, v.location, v.top_number, m.full_name as motorist_name, m.license_number, p.violation_name, u.full_name as treasurer_name 
                        FROM payments pay 
                        JOIN violations v ON pay.violation_id = v.id 
                        LEFT JOIN motorists m ON v.motorist_id = m.id 
                        LEFT JOIN penalties p ON v.penalty_id = p.id 
                        JOIN users u ON pay.treasurer_id = u.id
                        WHERE pay.id = ?");
$stmt->execute([$p_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) { die("Receipt not found."); }

// Check access: treasurer or the motorist themselves
if ($_SESSION['role'] == 'motorist') {
    $m_stmt = $conn->prepare("SELECT id FROM motorists WHERE user_id = ?");
    $m_stmt->execute([$_SESSION['user_id']]);
    $m_id = $m_stmt->fetch(PDO::FETCH_ASSOC)['id'];
    if ($data['motorist_id'] != $m_id) { die("Unauthorized access."); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Receipt - <?php echo $data['receipt_number']; ?></title>
    <link rel="stylesheet" href="style.css?v=20260425">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                    background: #f5f5f5; 
                    padding: 20px; 
                }
                .receipt-container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                }
                .receipt { 
                    background: white; 
                    padding: 40px; 
                    border-radius: 12px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.1); 
                }
                .header { 
                    text-align: center; 
                    border-bottom: 3px solid #004a99; 
                    padding-bottom: 25px; 
                    margin-bottom: 25px; 
                }
                .header-logo {
                    width: 86px;
                    height: 86px;
                    object-fit: contain;
                    display: block;
                    margin: 0 auto 10px;
                }
                .header h1 {
                    color: #004a99;
                    font-size: 2rem;
                    margin-bottom: 5px;
                }
                .header p {
                    color: #666;
                    font-size: 0.9rem;
                }
                .receipt-number {
                    background: #004a99;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 25px;
                    display: inline-block;
                    margin-bottom: 20px;
                    font-weight: bold;
                }
                .details {
                    margin-bottom: 25px;
                }
                .detail-row { 
                    display: flex; 
                    justify-content: space-between; 
                    padding: 12px 0;
                    border-bottom: 1px solid #eee;
                }
                .detail-row:last-child {
                    border-bottom: none;
                }
                .detail-label {
                    color: #666;
                    font-weight: 500;
                }
                .detail-value {
                    color: #333;
                    font-weight: 600;
                }
                .total-section {
                    background: linear-gradient(135deg, #004a99 0%, #007bff 100%);
                    color: white;
                    padding: 20px;
                    border-radius: 10px;
                    margin: 25px 0;
                    text-align: center;
                }
                .total-section .label {
                    font-size: 1rem;
                    opacity: 0.9;
                }
                .total-section .amount {
                    font-size: 2rem;
                    font-weight: bold;
                    margin-top: 5px;
                }
                .footer { 
                    text-align: center; 
                    border-top: 2px solid #004a99; 
                    padding-top: 20px; 
                    margin-top: 25px; 
                }
                .footer p {
                    color: #666;
                    font-size: 0.85rem;
                    margin: 5px 0;
                }
                
                /* Action Buttons */
                .actions {
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                    margin-top: 20px;
                    flex-wrap: wrap;
                }
                .btn {
                    padding: 12px 24px;
                    border-radius: 8px;
                    text-decoration: none;
                    font-weight: 600;
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    cursor: pointer;
                    border: none;
                    font-size: 0.95rem;
                    transition: all 0.3s ease;
                }
                .btn-print {
                    background: linear-gradient(135deg, #004a99 0%, #007bff 100%);
                    color: white;
                }
                .btn-print:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 15px rgba(0, 74, 153, 0.4);
                }
                .btn-download {
                    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                    color: white;
                }
                .btn-download:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
                }
                .btn-back {
                    background: #6c757d;
                    color: white;
                }
                .btn-back:hover {
                    background: #5a6268;
                    transform: translateY(-2px);
                }

                @media (max-width: 480px) {
                    body { padding: 12px; }
                    .receipt-container { max-width: 100%; }
                    .receipt { padding: 22px 16px; }
                    .actions .btn { min-height: 44px; padding: 0.65rem 1rem; }
                }
                
                /* Print Styles */
                @media print {
                    body { 
                        background: white; 
                        padding: 0; 
                    }
                    .receipt { 
                        border: none; 
                        box-shadow: none; 
                        padding: 20px;
                        border-radius: 0;
                    }
                    .actions, .no-print {
                        display: none !important;
                    }
                    .header {
                        border-bottom-color: #333;
                    }
                    .total-section {
                        background: #f0f0f0 !important;
                        color: #333 !important;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }
                }
                
                /* Back link */
                .back-link {
                    text-align: center;
                    margin-top: 15px;
                }
                .back-link a {
                    color: #666;
                    text-decoration: none;
                }
                .back-link a:hover {
                    color: #004a99;
                }
    </style>

</head>
<body>
    <div class="receipt-container">
        <div class="receipt">
            <div class="header">
                <img src="assets/images/pototan-logo-no-bg.png" alt="Municipality of Pototan Logo" class="header-logo">
                <h1>OFFICIAL RECEIPT</h1>
                <p>Traffic Violation Management System</p>
                <p>Municipal Government Unit</p>
            </div>
            
            <div style="text-align: center;">
                <div class="receipt-number">
                    <?php echo $data['receipt_number']; ?>
                </div>
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <span class="detail-label">Date Paid:</span>
                    <span class="detail-value"><?php echo date('F d, Y h:i A', strtotime($data['payment_date'])); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Motorist Name:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($data['motorist_name']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">License Number:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($data['license_number']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Violation Type:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($data['violation_details'] ?? $data['violation_name'] ?? 'Multiple Violations'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Location:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($data['location']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">TOP Number:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($data['top_number']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Violation Date:</span>
                    <span class="detail-value"><?php echo date('F d, Y', strtotime($data['violation_date'])); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Issued By:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($data['treasurer_name']); ?></span>
                </div>
            </div>
            
            <div class="total-section">
                <div class="label">TOTAL AMOUNT PAID</div>
                <div class="amount">₱<?php echo number_format($data['payment_amount'], 2); ?></div>
            </div>
            
            <div class="footer">
                <p><strong>Thank you for settling your penalty!</strong></p>
                <p>This is a computer-generated official receipt.</p>
                <p>Please keep this receipt for your records.</p>
            </div>
        </div>
        
        <div class="actions">
            <a href="<?php echo $_SESSION['role'] == 'treasurer' ? 'treasurer/dashboard.php' : 'dashboard.php'; ?>" class="btn btn-back">
                ← Back to Dashboard
            </a>
        </div>

        <!-- Action Buttons -->
        <div class="actions">
            <button onclick="window.print()" class="btn btn-print">
                🖨️ Print Receipt
            </button>
            <button onclick="downloadPDF()" class="btn btn-download">
                📥 Download PDF
            </button>
        </div>
        
    </div>
    
    <script>
        function downloadPDF() {
            // Open print dialog which allows saving as PDF
            window.print();
        }
    </script>
</body>
</html>
