<?php
require_once __DIR__ . '/../db.php';
$conn = getConnection();

// Get all stopped nmm camera records
$crRows = queryAll($conn, "SELECT * FROM CameraRecords WHERE stateVideo = 'stopped' AND zOg = 'nmm'");
$glosses_cr = array_column($crRows, 'glos');

// Get all ready nmm_data records
$nmm_data = queryAll($conn, "SELECT * FROM nmm_data WHERE type = 'ready'");

// Read liteGlos.json
$filename = __DIR__ . "/liteGlos.json";
$contents = file_get_contents($filename);
$glosses = json_decode($contents, true);

$summary = $glosses['summary']['glosses_with_empty_matched_transcriptions_and_nme_videos'] ?? [];

$output = [];
$output_mg = [];

foreach ($summary as $value) {
    if (!in_array($value, $glosses_cr)) {
        $nmmfound = false;
        foreach ($nmm_data as $nmm) {
            if ($nmm['glos'] === $value) {
                $entry = [
                    'id' => $nmm['id'],
                    'signbank' => $nmm['signbank_id'],
                    'type' => $nmm['type'],
                    'nmmId' => $nmm['id'],
                    'glos' => $nmm['glos'],
                    'thema' => "ALLES",
                    'zelfopname' => $nmm['zelfopname']
                ];
                $output[] = $entry;
                $nmmfound = true;
            }
        }
        if (!$nmmfound) {
            // Insert new row with prepared statement
            $stmt = execute($conn,
                "INSERT INTO nmm_data (glos, type, thema) VALUES (?, 'ready', 'ALLES')",
                "s", [$value]
            );
            $nmmId = $stmt->insert_id;
            $stmt->close();
            $output[] = [
                'id' => $nmmId,
                'signbank' => "",
                'type' => "ready",
                'nmmId' => $nmmId,
                'glos' => $value,
                'thema' => "ALLES"
            ];
        }
    } else {
        foreach ($nmm_data as $nmm) {
            if ($nmm['glos'] === $value) {
                $output_mg[] = [
                    'ID' => $nmm['id'],
                    'signbank' => $nmm['signbank_id'],
                    'type' => $nmm['type'],
                    'nmmId' => $nmm['id'],
                    'glos' => $nmm['glos'],
                    'thema' => "ALLES"
                ];
            }
        }
    }
}

jsonResponse([
    'unmatchedGloss' => $output,
    'matchedGlosses' => $output_mg,
    'matchedCount' => count($output),
    'unmatchedCount' => count($output_mg)
]);
