<?php
require_once 'db.php';

echo "=== Adding Sample Data to Database ===\n\n";

global $pdo;

try {
    $pdo->beginTransaction();

    // 1. Create sample motorists
    echo "1. Creating sample motorists...\n";
    $motorists = [
        ['JOHNDOE-2024-001', 'John Doe', 'quezon_city', '09123456789', 'ABC-1234'],
        ['JANEDOE-2024-002', 'Jane Doe', 'manila_city', '09876543210', 'DEF-5678'],
        ['BOB2024-003', 'Bob Smith', 'caloocan_city', '09112223333', 'GHI-9012'],
    ];

    $moto_stmt = $pdo->prepare("INSERT OR IGNORE INTO motorists (license_number, full_name, address, contact_number, plate) VALUES (?, ?, ?, ?, ?)");
    foreach ($motorists as $m) {
        $moto_stmt->execute($m);
    }
    echo "   ✓ Added " . count($motorists) . " motorists\n\n";

    // 2. Create sample violations
    echo "2. Creating sample violations...\n";
    
    // Get enforcer IDs
    $enforcers = $pdo->query("SELECT id FROM users WHERE role = 'enforcer'")->fetchAll(PDO::FETCH_ASSOC);
    $enforcer1_id = $enforcers[0]['id'] ?? 1;
    
    // Get motorist IDs
    $motorist_ids = $pdo->query("SELECT id FROM motorists")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get penalty IDs
    $penalties = $pdo->query("SELECT id, fine_amount FROM penalties")->fetchAll(PDO::FETCH_ASSOC);

    if (count($motorist_ids) > 0 && count($penalties) > 0) {
        $violations = [
            // Pending violations (for supervisor to validate)
            [
                $motorist_ids[0]['id'], $enforcer1_id, $penalties[0]['id'],
                'EDSA Cubao', $penalties[0]['fine_amount'], 'pending'
            ],
            // Validated violations (ready for payment)
            [
                $motorist_ids[0]['id'], $enforcer1_id, $penalties[1]['id'],
                'Ayala Avenue', $penalties[1]['fine_amount'], 'validated'
            ],
            [
                $motorist_ids[1]['id'], $enforcer1_id, $penalties[2]['id'],
                'Makati Central', $penalties[2]['fine_amount'], 'validated'
            ],
            // Paid violations
            [
                $motorist_ids[1]['id'], $enforcer1_id, $penalties[3]['id'],
                'Bonifacio Global City', $penalties[3]['fine_amount'], 'paid'
            ],
        ];

        $viol_stmt = $pdo->prepare("INSERT INTO violations (motorist_id, enforcer_id, penalty_id, location, fine_amount, status) 
                                    VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($violations as $v) {
            $viol_stmt->execute($v);
        }
        
        // Update motorist offense counts
        $offense_stmt = $pdo->prepare("INSERT OR IGNORE INTO motorist_offense_counts (motorist_id, offense_count) VALUES (?, 1)");
        foreach ($motorist_ids as $m) {
            $offense_stmt->execute([$m['id']]);
        }
        
        echo "   ✓ Added " . count($violations) . " violations\n\n";
    }

    // 3. Create sample payments for paid violations
    echo "3. Creating sample payments...\n";
    $paid_violations = $pdo->query("SELECT v.id, v.fine_amount FROM violations v WHERE v.status = 'paid'")->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($paid_violations) > 0) {
        $treasurer = $pdo->query("SELECT id FROM users WHERE role = 'treasurer' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $treasurer_id = $treasurer['id'] ?? 1;
        
        $pay_stmt = $pdo->prepare("INSERT INTO payments (violation_id, treasurer_id, receipt_number, payment_amount) VALUES (?, ?, ?, ?)");
        foreach ($paid_violations as $v) {
            $receipt = 'REC-' . time() . '-' . rand(1000, 9999);
            $pay_stmt->execute([$v['id'], $treasurer_id, $receipt, $v['fine_amount']]);
        }
        echo "   ✓ Added " . count($paid_violations) . " payments\n\n";
    }

    // 4. Create sample articles/tutorials
    echo "4. Creating sample articles...\n";
    $articles = [
        ['How to Settle Traffic Violations', 'how-to-settle', 
         'To settle your traffic violations, you can pay online through your dashboard. Alternatively, visit the Municipal Treasurer office for cash payments.'],
        ['Online Payment Guide', 'online-payment-guide',
         'For validated violations, select the violation to process payment. Visit the Treasurer office for cash options.'],
        ['Appealing a Violation', 'appealing-violation',
         'If you believe a violation was issued in error, contact the supervisor through the system or visit the office. Provide evidence and details for review.'],
    ];

    $article_stmt = $pdo->prepare("INSERT OR IGNORE INTO articles (title, slug, content) VALUES (?, ?, ?)");
    foreach ($articles as $a) {
        $article_stmt->execute($a);
    }
    echo "   ✓ Added " . count($articles) . " articles\n\n";

    $pdo->commit();

    echo "=== Sample Data Added Successfully! ===\n\n";

    // Show summary
    echo "Database Summary:\n";
    $tables = ['users', 'motorists', 'violations', 'penalties', 'payments', 'articles'];
    foreach ($tables as $table) {
        $cnt = $pdo->query("SELECT COUNT(*) as cnt FROM $table")->fetch(PDO::FETCH_ASSOC);
        echo "  - $table: {$cnt['cnt']} records\n";
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
?>
