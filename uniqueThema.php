<?php
require_once __DIR__ . '/db.php';
$conn = getConnection();

$extern = getInt('extern');

if ($extern !== null) {
    $rows = queryAll($conn,
        "SELECT DISTINCT thema FROM form_data WHERE glosZichtbaar = '0' AND extern = ? ORDER BY thema ASC",
        "i", [$extern]
    );
} else {
    $rows = queryAll($conn,
        "SELECT DISTINCT thema FROM form_data WHERE glosZichtbaar = '0' AND extern IS NULL ORDER BY thema ASC"
    );
}

$themas = [];
foreach ($rows as $row) {
    if ($row['thema'] !== '') {
        $themas[] = strtoupper($row['thema']);
    }
}

jsonResponse($themas);
