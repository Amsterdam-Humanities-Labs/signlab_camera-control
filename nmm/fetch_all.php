<?php
require_once __DIR__ . '/../db.php';
$conn = getConnection();

$thema = getString('thema');
$userId = getString('userId', '%');

// Build base query with optional thema filter
$sql = "SELECT id, signbank_id, glos, zelfopname, type, thema FROM nmm_data";
$types = '';
$params = [];

if ($thema !== null && $thema !== '') {
    $sql .= " WHERE thema = ?";
    $types .= "s";
    $params[] = $thema;
}

$rows = queryAll($conn, $sql, $types, $params);

$data = [];
foreach ($rows as $row) {
    if ($row['type'] === 'not_ready') {
        continue;
    }

    $zelfopname = $row['zelfopname'];

    // Get video records
    $videos = queryAll($conn,
        "SELECT camera1, camera2, camera3, camera4, camera5, videoTop, datetime_ms, user
         FROM CameraRecords WHERE zOg='nmm' AND glosId = ? AND (user LIKE ? OR ? = '%') AND stateVideo = 'stopped'",
        "iss", [$row['id'], $userId, $userId]
    );

    // Convert 'ready' type to 'gc' if no gc record exists
    if ($row['type'] === 'ready') {
        $existing = queryOne($conn,
            "SELECT COUNT(*) as cnt FROM nmm_data WHERE signbank_id = ? AND type = ?",
            "ss", [$row['signbank_id'], 'gc']
        );
        if ($existing && $existing['cnt'] > 0) {
            continue;
        }
        $row['type'] = 'gc';
    }

    // If zelfopname is empty, get from form_data
    if ($zelfopname === '' || $zelfopname === null) {
        $fdRow = queryOne($conn, "SELECT zelfopname FROM form_data WHERE id = ?", "s", [$row['signbank_id']]);
        if ($fdRow) {
            $parsed = is_string($fdRow['zelfopname']) ? json_decode($fdRow['zelfopname'], true) : $fdRow['zelfopname'];
            if (is_array($parsed) && count($parsed) === 1) {
                $zelfopname = $parsed[0];
            }
        }
        if ($zelfopname === '' || $zelfopname === null) {
            $zelfopname = $row['glos'] . ".mp4";
        }
    }

    $data[] = [
        'id' => $row['id'],
        'signbank_id' => $row['signbank_id'],
        'glos' => $row['glos'],
        'zelfopname' => $zelfopname,
        'type' => $row['type'],
        'videos' => $videos
    ];
}

jsonResponse($data);
