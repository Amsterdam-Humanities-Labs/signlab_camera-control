<?php
require_once __DIR__ . '/../db.php';
$conn = getConnection();

error_reporting(E_ERROR | E_PARSE);

function removeUserById(&$array, $userid) {
    $array = array_values(array_filter($array, function($item) use ($userid) {
        return !isset($item['userid']) || $item['userid'] !== $userid;
    }));
}

$glosId = getString('glosId');
$userId = getString('userId');
$method = getString('method');
$thema = getString('thema');
$captureDevice = getString('captureDevice');

$json_output = [];

if ($captureDevice === "unreal") {
    execute($conn, "UPDATE form_data SET unreal_take = NULL WHERE thema = ?", "s", [$thema]);
    jsonResponse(["status" => "Leeggemaakt!"]);
}

if ($method === "multiple") {
    $rows = queryAll($conn,
        "SELECT * FROM form_data WHERE videoTop IS NOT NULL AND thema = ?",
        "s", [$thema]
    );
} elseif ($method === "single") {
    $rows = queryAll($conn,
        "SELECT * FROM form_data WHERE id = ? AND videoTop IS NOT NULL",
        "s", [$glosId]
    );
} else {
    $rows = [];
}

foreach ($rows as $row) {
    $id = $row['id'];
    $videoTop = $row['videoTop'];
    $videoLeft = $row['videoLeft'];
    $videoCenter = $row['videoCenter'];
    $videoRight = $row['videoRight'];

    if ($videoTop !== null) {
        $videoTopArray = json_decode($videoTop, true) ?? [];
        removeUserById($videoTopArray, $userId);
        $videoTop = json_encode($videoTopArray);
    }

    if ($videoLeft !== null) {
        $videoLeftArray = json_decode($videoLeft, true) ?? [];
        removeUserById($videoLeftArray, $userId);
        $videoLeft = json_encode($videoLeftArray);
    }

    if ($videoCenter !== null) {
        $videoCenterArray = json_decode($videoCenter, true) ?? [];
        removeUserById($videoCenterArray, $userId);
        $videoCenter = json_encode($videoCenterArray);
    }

    if ($videoRight !== null) {
        $videoRightArray = json_decode($videoRight, true) ?? [];
        removeUserById($videoRightArray, $userId);
        $videoRight = json_encode($videoRightArray);
    }

    execute($conn,
        "UPDATE form_data SET videoTop = ?, videoLeft = ?, videoCenter = ?, videoRight = ? WHERE id = ?",
        "sssss", [$videoTop, $videoLeft, $videoCenter, $videoRight, $id]
    );

    $json_output = ["status" => "Leeggemaakt!"];
}

jsonResponse($json_output);
