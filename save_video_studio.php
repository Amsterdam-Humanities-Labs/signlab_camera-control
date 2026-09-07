<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/sc_paths.php';

require_once __DIR__ . '/db.php';
$conn = getConnection();

header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);

$glos = postString('glos');
$glosId = postString('id');
$startTime = postString('startTime');
$stopTime = postString('stopTime');
$userId = postString('userid');
$skipped = postString('skipped');
$camera1 = postString('camera1');
$camera2 = postString('camera2');
$camera3 = postString('camera3');
$camera4 = postString('camera4');
$camera5 = postString('camera5');
$stateVideo = postString('stateVideo');
$zOg = postString('zOg');

// verwachte clipnamen per camera-serial (JSON), gesnapshot voor de opnamestart
$clips = postString('clips');
$clipsDecoded = $clips ? json_decode($clips, true) : null;
$clips = (is_array($clipsDecoded) && count($clipsDecoded) > 0) ? json_encode($clipsDecoded) : null;

$randomString = $skipped === "true" ? "skipped" : generateRandomString(64) . '.webm';

// vrij_-opnames hebben geen form_data-rij en een niet-numeriek id, terwijl
// form_data.id een INTEGER is: alleen opzoeken/updaten voor numerieke ids,
// anders gooit strict-mode MySQL "Truncated incorrect INTEGER value".
$row = ctype_digit($glosId) ? queryOne($conn, "SELECT videoTop FROM form_data WHERE id = ?", "s", [$glosId]) : null;

$updatedVideoTop = null;
if ($row) {
    $videoTopArray = json_decode($row['videoTop'] ?? '{}', true);
    if (!is_array($videoTopArray)) {
        $videoTopArray = [];
    }

    $item = [
        "userid" => $userId,
        "videoTop" => $randomString,
        "startTime" => $startTime,
        "stopTime" => $stopTime
    ];

    $itemFound = false;
    foreach ($videoTopArray as $key => $existingItem) {
        if ($existingItem['userid'] === $userId) {
            $videoTopArray[$key] = $item;
            $itemFound = true;
            break;
        }
    }

    if (!$itemFound) {
        $videoTopArray[] = $item;
    }

    $updatedVideoTop = json_encode($videoTopArray);
}

if ($skipped !== "true" && isset($_FILES['video'])) {
    $upload_directory = sc_dir('uploads');
    $video_filename = $randomString;
    $video_path = $upload_directory . $video_filename;

    if (move_uploaded_file($_FILES['video']['tmp_name'], $video_path)) {
        if ($row) {
            execute($conn, "UPDATE form_data SET videoTop = ? WHERE id = ?", "ss", [$updatedVideoTop, $glosId]);
        }
        executeAddRowToCameraRecords($conn, $glosId, $glos, $camera1, $camera2, $camera3, $camera4, $camera5, $stateVideo, $startTime, $stopTime, $randomString, $zOg, $userId, $clips);
        $response = [
            'success' => true,
            'message' => 'Video has been successfully saved',
            'videolink' => $video_filename
        ];
    } else {
        $response = ["error" => "Error: Unable to save the video."];
    }
} else {
    if ($row) {
        execute($conn, "UPDATE form_data SET videoTop = ? WHERE id = ?", "ss", [$updatedVideoTop, $glosId]);
    }
    executeAddRowToCameraRecords($conn, $glosId, $glos, $camera1, $camera2, $camera3, $camera4, $camera5, $stateVideo, $startTime, $stopTime, $randomString, $zOg, $userId, $clips);
    $response = ["success" => true, "message" => "Skipped"];
}

$conn->close();
echo json_encode($response);

function generateRandomString($length = 64) {
    $bytes = ceil($length / 2);
    return substr(bin2hex(random_bytes($bytes)), 0, $length);
}

function executeAddRowToCameraRecords($conn, $glosId, $glos, $camera1, $camera2, $camera3, $camera4, $camera5, $stateVideo, $startTime, $stopTime, $randomString, $zOg, $userId, $clips = null) {
    $datetime_ms = round(microtime(true) * 1000);
    execute($conn,
        "INSERT INTO CameraRecords (glosId, glos, camera1, camera2, camera3, camera4, camera5, stateVideo, startTime, stopTime, videoTop, datetime_ms, zOg, user, clips)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "sssssssssssssss",
        [$glosId, $glos, $camera1, $camera2, $camera3, $camera4, $camera5, $stateVideo, $startTime, $stopTime, $randomString, $datetime_ms, $zOg, $userId, $clips]
    );
}
