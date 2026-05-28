<?php
/**
 * One-time safe backfill:
 * - Normalizes license numbers (trim + uppercase).
 * - Merges duplicate motorists by normalized license.
 * - Relinks violations to a single canonical motorist per license.
 * - Preserves/fills key fields like DOB and registered_owner.
 *
 * This script does NOT delete motorists; it only updates links/data.
 */
require_once 'db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit('Database connection not available.');
}

$is_mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

function first_non_empty(?string ...$values): ?string
{
    foreach ($values as $value) {
        $trimmed = trim((string)$value);
        if ($trimmed !== '') {
            return $trimmed;
        }
    }
    return null;
}

echo '<h2>Motorist Link + DOB Backfill</h2>';
echo '<p>Starting safe reconciliation...</p>';

try {
    $pdo->beginTransaction();

    // 1) Normalize all motorist license numbers to reduce mismatch.
    $normalize_sql = $is_mysql
        ? "UPDATE motorists SET license_number = UPPER(TRIM(license_number)) WHERE license_number IS NOT NULL"
        : "UPDATE motorists SET license_number = UPPER(TRIM(license_number)) WHERE license_number IS NOT NULL";
    $normalized_rows = $pdo->exec($normalize_sql);
    echo '<p>Normalized license numbers: ' . (int)$normalized_rows . '</p>';

    // 2) Build groups by normalized license.
    $motorists = $pdo->query("SELECT id, license_number, full_name, address, plate, date_of_birth, registered_owner
                              FROM motorists
                              WHERE TRIM(COALESCE(license_number, '')) <> ''")
        ->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($motorists as $row) {
        $key = strtoupper(trim((string)$row['license_number']));
        if ($key === '') {
            continue;
        }
        if (!isset($groups[$key])) {
            $groups[$key] = [];
        }
        $groups[$key][] = $row;
    }

    $relinked_violations = 0;
    $updated_motorists = 0;
    $updated_offense_rows = 0;
    $duplicate_groups = 0;

    $update_motorist_stmt = $pdo->prepare(
        "UPDATE motorists
         SET full_name = ?, address = ?, date_of_birth = ?, registered_owner = ?, plate = ?
         WHERE id = ?"
    );
    $relink_violations_stmt = $pdo->prepare("UPDATE violations SET motorist_id = ? WHERE motorist_id = ?");
    $sum_offense_stmt = $pdo->prepare("SELECT COALESCE(SUM(offense_count), 0) AS total FROM motorist_offense_counts WHERE motorist_id IN (%s)");
    $delete_offense_stmt = $pdo->prepare("DELETE FROM motorist_offense_counts WHERE motorist_id = ?");
    $upsert_offense_mysql = $pdo->prepare(
        "INSERT INTO motorist_offense_counts (motorist_id, offense_count, last_violation_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE offense_count = VALUES(offense_count), last_violation_at = VALUES(last_violation_at)"
    );
    $upsert_offense_sqlite = $pdo->prepare(
        "INSERT INTO motorist_offense_counts (motorist_id, offense_count, last_violation_at)
         VALUES (?, ?, datetime('now'))
         ON CONFLICT(motorist_id) DO UPDATE SET offense_count = excluded.offense_count, last_violation_at = excluded.last_violation_at"
    );

    foreach ($groups as $license => $rows) {
        if (count($rows) <= 1) {
            continue;
        }

        $duplicate_groups++;

        // Choose canonical row: prefer one with DOB, otherwise lowest id.
        usort($rows, static function (array $a, array $b): int {
            $a_has_dob = trim((string)($a['date_of_birth'] ?? '')) !== '' ? 1 : 0;
            $b_has_dob = trim((string)($b['date_of_birth'] ?? '')) !== '' ? 1 : 0;
            if ($a_has_dob !== $b_has_dob) {
                return $b_has_dob <=> $a_has_dob;
            }
            return ((int)$a['id']) <=> ((int)$b['id']);
        });

        $canonical = $rows[0];
        $canonical_id = (int)$canonical['id'];

        $merged_full_name = trim((string)($canonical['full_name'] ?? ''));
        $merged_address = trim((string)($canonical['address'] ?? ''));
        $merged_dob = trim((string)($canonical['date_of_birth'] ?? ''));
        $merged_owner = trim((string)($canonical['registered_owner'] ?? ''));
        $merged_plate = trim((string)($canonical['plate'] ?? ''));

        $duplicate_ids = [];
        foreach ($rows as $idx => $row) {
            if ($idx === 0) {
                continue;
            }
            $duplicate_id = (int)$row['id'];
            $duplicate_ids[] = $duplicate_id;

            $merged_full_name = first_non_empty($merged_full_name, $row['full_name'] ?? null) ?? '';
            $merged_address = first_non_empty($merged_address, $row['address'] ?? null) ?? '';
            $merged_dob = first_non_empty($merged_dob, $row['date_of_birth'] ?? null) ?? '';
            $merged_owner = first_non_empty($merged_owner, $row['registered_owner'] ?? null) ?? '';
            $merged_plate = first_non_empty($merged_plate, $row['plate'] ?? null) ?? '';

            $relink_violations_stmt->execute([$canonical_id, $duplicate_id]);
            $relinked_violations += $relink_violations_stmt->rowCount();
        }

        $update_motorist_stmt->execute([
            $merged_full_name,
            $merged_address,
            $merged_dob !== '' ? $merged_dob : null,
            $merged_owner,
            $merged_plate,
            $canonical_id
        ]);
        $updated_motorists += $update_motorist_stmt->rowCount();

        if (!empty($duplicate_ids)) {
            $all_ids = array_merge([$canonical_id], $duplicate_ids);
            $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
            $sum_stmt = $pdo->prepare(sprintf(
                "SELECT COALESCE(SUM(offense_count), 0) AS total FROM motorist_offense_counts WHERE motorist_id IN (%s)",
                $placeholders
            ));
            $sum_stmt->execute($all_ids);
            $merged_offense_count = (int)($sum_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            if ($merged_offense_count > 0) {
                if ($is_mysql) {
                    $upsert_offense_mysql->execute([$canonical_id, $merged_offense_count]);
                } else {
                    $upsert_offense_sqlite->execute([$canonical_id, $merged_offense_count]);
                }
                $updated_offense_rows++;
            }

            foreach ($duplicate_ids as $dup_id) {
                $delete_offense_stmt->execute([$dup_id]);
            }
        }
    }

    $pdo->commit();

    echo '<h3>Backfill completed successfully.</h3>';
    echo '<ul>';
    echo '<li>Duplicate license groups processed: ' . (int)$duplicate_groups . '</li>';
    echo '<li>Violations relinked: ' . (int)$relinked_violations . '</li>';
    echo '<li>Motorist rows updated: ' . (int)$updated_motorists . '</li>';
    echo '<li>Offense-count rows merged: ' . (int)$updated_offense_rows . '</li>';
    echo '</ul>';
    echo '<p>Next: reload PNP Reports/Dashboard and re-check "Violators by Age Group".</p>';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo '<h3 style="color:#b00020;">Backfill failed</h3>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}

