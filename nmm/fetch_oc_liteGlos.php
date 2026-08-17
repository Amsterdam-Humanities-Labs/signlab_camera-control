<?php
require_once __DIR__ . '/../db.php';
$conn = getConnection();

// Get distinct stopped glos records for nmm_oc
$crRows = queryAll($conn, "SELECT DISTINCT glos FROM CameraRecords WHERE stateVideo = 'stopped' AND zOg = 'nmm_oc'");
$glosses_cr = [];
foreach ($crRows as $row) {
    $glos = explode(" ", $row['glos'])[0];
    $glos = preg_replace('/\s+/', '', $glos);
    $glosses_cr[] = $glos;
}

// Get matched transcriptions
$mtRows = queryAll($conn, "SELECT * FROM matched_transcriptions WHERE zOg = 'nmm_oc' AND added = '1'");
$glosses_mt = [];
foreach ($mtRows as $row) {
    $glosses_mt[$row['m_transcription']] = $row['m_transcription'];
}

// Get nmm_data records for oc type
$nmm_data = queryAll($conn, "SELECT * FROM nmm_data WHERE type = 'oc' AND zelfopname != ''");

$output = [];
$output_mg = [];

foreach ($nmm_data as $nmm) {
    $glos = $nmm['glos'];
    $glosId = $nmm['id'];

    if (!in_array($glos, $glosses_cr)) {
        $output[] = [
            'id' => $nmm['id'],
            'signbank' => $nmm['signbank_id'],
            'type' => $nmm['type'],
            'nmmId' => $nmm['id'],
            'glos' => $nmm['glos'],
            'thema' => "ALLES",
            'zelfopname' => [$nmm['zelfopname']]
        ];
    } else {
        if (array_key_exists($glosId, $glosses_mt) && $glosId == $glosses_mt[$glosId]) {
            $output_mg[] = [
                'id' => $nmm['id'],
                'signbank' => $nmm['signbank_id'],
                'type' => $nmm['type'],
                'nmmId' => $nmm['id'],
                'glos' => $nmm['glos'],
                'thema' => "ALLES"
            ];
        } else {
            $output[] = [
                'id' => $nmm['id'],
                'signbank' => $nmm['signbank_id'],
                'type' => $nmm['type'],
                'nmmId' => $nmm['id'],
                'glos' => $nmm['glos'],
                'thema' => "ALLES",
                'zelfopname' => [$nmm['zelfopname']]
            ];
        }
    }
}

jsonResponse([
    'unmatchedGloss' => $output,
    'matchedGlosses' => $output_mg,
    'matchedCount' => count($output_mg),
    'unmatchedCount' => count($output)
]);
