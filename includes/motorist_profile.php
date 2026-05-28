<?php

function fetch_motorist_profile_data(PDO $conn, int $motorist_id): array
{
    $profile = null;
    $violations = [];
    $evidence_by_violation = [];

    if ($motorist_id <= 0) {
        return [
            'profile' => $profile,
            'violations' => $violations,
            'evidence_by_violation' => $evidence_by_violation,
        ];
    }

    $is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $has_date_of_birth = false;
    $has_registered_owner = false;
    try {
        if ($is_mysql) {
            $dob_stmt = $conn->prepare("SHOW COLUMNS FROM motorists LIKE 'date_of_birth'");
            $dob_stmt->execute();
            $has_date_of_birth = (bool)$dob_stmt->fetch(PDO::FETCH_ASSOC);

            $owner_stmt = $conn->prepare("SHOW COLUMNS FROM motorists LIKE 'registered_owner'");
            $owner_stmt->execute();
            $has_registered_owner = (bool)$owner_stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $columns_stmt = $conn->query("PRAGMA table_info(motorists)");
            $columns = $columns_stmt ? $columns_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'date_of_birth') {
                    $has_date_of_birth = true;
                }
                if (($column['name'] ?? '') === 'registered_owner') {
                    $has_registered_owner = true;
                }
            }
        }
    } catch (Throwable $e) {
        $has_date_of_birth = false;
        $has_registered_owner = false;
    }

    $motorist_select_parts = [
        'id',
        'full_name',
        'license_number',
        'plate',
        'address'
    ];
    $motorist_select_parts[] = $has_date_of_birth ? 'date_of_birth' : "'' AS date_of_birth";
    $motorist_select_parts[] = $has_registered_owner ? 'registered_owner' : "'' AS registered_owner";
    $motorist_select = implode(', ', $motorist_select_parts);

    $motorist_stmt = $conn->prepare("SELECT $motorist_select
                                     FROM motorists
                                     WHERE id = ?
                                     LIMIT 1");
    $motorist_stmt->execute([$motorist_id]);
    $profile = $motorist_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        return [
            'profile' => null,
            'violations' => [],
            'evidence_by_violation' => [],
        ];
    }

    $violations_stmt = $conn->prepare("SELECT v.id,
                                              v.top_number,
                                              v.violation_date,
                                              v.location,
                                              v.fine_amount,
                                              v.status,
                                              v.incident_description,
                                              COALESCE(v.violation_details, p.violation_name, 'Multiple/Custom') as violation_display
                                       FROM violations v
                                       LEFT JOIN penalties p ON v.penalty_id = p.id
                                       WHERE v.motorist_id = ?
                                       ORDER BY v.violation_date DESC");
    $violations_stmt->execute([$motorist_id]);
    $violations = $violations_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($violations)) {
        $violation_ids = array_map(fn($row) => (int)$row['id'], $violations);
        $placeholders = implode(',', array_fill(0, count($violation_ids), '?'));
        $evidence_stmt = $conn->prepare("SELECT violation_id,
                                                file_path,
                                                COALESCE(evidence_type, 'general') as evidence_type,
                                                COALESCE(evidence_label, '') as evidence_label
                                         FROM evidence
                                         WHERE violation_id IN ($placeholders)
                                         ORDER BY uploaded_at DESC");
        $evidence_stmt->execute($violation_ids);
        $evidence_rows = $evidence_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($evidence_rows as $ev) {
            $violation_id = (int)$ev['violation_id'];
            if (!isset($evidence_by_violation[$violation_id])) {
                $evidence_by_violation[$violation_id] = [];
            }
            $evidence_by_violation[$violation_id][] = $ev;
        }
    }

    return [
        'profile' => $profile,
        'violations' => $violations,
        'evidence_by_violation' => $evidence_by_violation,
    ];
}

function build_motorist_profile_payload(?array $profile, array $violations, array $evidence_by_violation): ?array
{
    if (!$profile) {
        return null;
    }

    $payload = [
        'full_name' => $profile['full_name'] ?? '',
        'license_number' => $profile['license_number'] ?? '',
        'plate' => $profile['plate'] ?? '',
        'address' => $profile['address'] ?? '',
        'date_of_birth' => $profile['date_of_birth'] ?? '',
        'registered_owner' => $profile['registered_owner'] ?? '',
        'total_offenses' => count($violations),
        'violations' => [],
    ];

    foreach ($violations as $violation) {
        $payload['violations'][] = [
            'top_number' => $violation['top_number'] ?? '',
            'violation_date' => $violation['violation_date'] ?? '',
            'location' => $violation['location'] ?? '',
            'violation_display' => $violation['violation_display'] ?? '',
            'incident_description' => $violation['incident_description'] ?? '',
            'fine_amount' => $violation['fine_amount'] ?? 0,
            'status' => $violation['status'] ?? '',
            'evidence' => $evidence_by_violation[(int)($violation['id'] ?? 0)] ?? [],
        ];
    }

    return $payload;
}
