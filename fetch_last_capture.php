<?php
require_once __DIR__ . '/db.php';
$conn = getConnection();

error_reporting(E_ALL);
ini_set('display_errors', 0);

$processed = getString('processed');
$status = getString('status');
$cId = getString('cameraId');
$format = getString('format');

$cameraMap = [
    "D4DA001EACEA" => "camera1",
    "D4DA001EAC65" => "camera2",
    "D4DA001EAD5C" => "camera3",
    "D4DA001ECB4B" => "camera4",
    "D4DA001EC952" => "camera5",
];

// Whitelist valid camera columns
$validColumns = ['camera1', 'camera2', 'camera3', 'camera4', 'camera5'];

$resultsArray = [];

$row = queryOne($conn, "SELECT * FROM studio_data WHERE date = CURDATE()");

if ($row) {
    $counterArray = [
        "camera1" => $row['camera1'],
        "camera2" => $row['camera2'],
        "camera3" => $row['camera3'],
        "camera4" => $row['camera4'],
        "camera5" => $row['camera5']
    ];

    if ($processed === "0") {
        $datetime_ms = round(microtime(true) * 1000);
        execute($conn,
            "UPDATE studio_data SET processed = 0, ready = 1, datetime_ms = ? WHERE date = CURDATE()",
            "s", [$datetime_ms]
        );
    }

    if ($status === "opnameCounterAdd") {
        $cameraCol = $cameraMap[$cId] ?? null;
        if ($cameraCol && in_array($cameraCol, $validColumns)) {
            $count = ($counterArray[$cameraCol] ?? 0) + 1;
            // Column name is whitelisted, safe to interpolate
            execute($conn,
                "UPDATE studio_data SET {$cameraCol} = ? WHERE date = CURDATE()",
                "i", [$count]
            );
            $resultsArray = [
                "result" => '1',
                "opnameCounter" => $count,
                "cameraNumber" => $cId,
                "cameraID" => $cameraCol,
            ];
        } else {
            $resultsArray = [
                "result" => '0',
                "opnameCounter" => '0',
                "cameraNumber" => $cId,
                "cameraID" => $cameraCol
            ];
        }
    }

    if ($status === "opnameCounter") {
        $resultsArray = [
            "result" => '0',
            "opnameCounter" => $row['camera3']
        ];
    }
}

if ($format) {
    $existingRow = queryOne($conn, "SELECT * FROM studio_data WHERE date = CURDATE()");
    if ($existingRow) {
        $resultsArray = [
            "result" => '0',
            "opnameCounter" => '0'
        ];
    } else {
        $resultsArray = [
            "result" => '1',
            "opnameCounter" => '0'
        ];
        execute($conn,
            "INSERT INTO studio_data (date) VALUES (?)",
            "s", [date('Y-m-d')]
        );
    }
}

jsonResponse($resultsArray);
