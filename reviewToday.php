<?php
// reviewToday.php — de database-kant van het dagoverzicht: wat is er vandaag
// (of op ?date=YYYY-MM-DD) opgenomen volgens CameraRecords, incl. de
// webcam-blob (videoTop, terug te kijken uit /web/uploads).
// De camera-clips en hun downloaded-status komen client-side uit
// GET /api/captures van de controller en worden op tijd gematcht.
require_once __DIR__ . '/db.php';

$conn = getConnection();

$date = getString('date', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    jsonResponse(array('error' => 'Invalid date'), 400);
}
$dayStartMs = strtotime($date . ' 00:00:00') * 1000;
$dayEndMs = $dayStartMs + 24 * 3600 * 1000;

$rows = queryAll($conn,
    "SELECT glosId, glos, stateVideo, startTime, stopTime, videoTop, datetime_ms, zOg, user, clips
     FROM CameraRecords
     WHERE datetime_ms >= ? AND datetime_ms < ?
     ORDER BY datetime_ms DESC",
    "ii", array($dayStartMs, $dayEndMs));

$out = array();
foreach ($rows as $row) {
    $rowMs = (float)$row['datetime_ms'];
    // startTime is ISO bij een echte opname ("0" bij een skip); dat tijdstip
    // matcht het beste met de capture-entries van de controller
    $t = strtotime((string)$row['startTime']);
    $refMs = $t ? $t * 1000 : $rowMs;

    $out[] = array(
        'timeMs' => $rowMs,
        'refMs' => $refMs,
        'glos' => $row['glos'],
        'glosId' => $row['glosId'],
        'zOg' => $row['zOg'],
        'user' => $row['user'],
        'stateVideo' => $row['stateVideo'],
        'videoTop' => $row['videoTop'],
        // verwachte clipnamen per camera-serial (JSON), null bij oude rijen/skips
        'clips' => $row['clips'] ? json_decode($row['clips'], true) : null,
    );
}

jsonResponse(array('date' => $date, 'records' => $out));
