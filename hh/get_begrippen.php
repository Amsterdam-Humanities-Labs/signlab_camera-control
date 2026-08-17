<?php
require_once __DIR__ . '/../db.php';
$conn = getConnection();

error_reporting(E_ERROR | E_PARSE);

$page = getInt('page', 1);
$search = getString('search', '');
$perPage = getInt('perPage', 25);

if ($page < 1) { $page = 1; }
$offset = ($page - 1) * $perPage;

$sql = "SELECT hig.id as glos_id, hig.glos, hig.text, hi.id as content_id, hi.url
        FROM hh_index_glos hig
        LEFT JOIN hh_index hi ON hig.hh_index_id = hi.id";
$types = '';
$params = [];

if (!empty($search)) {
    $sql .= " WHERE hig.glos LIKE ? OR hig.text LIKE ?";
    $types .= "ss";
    $likeSearch = '%' . $search . '%';
    $params[] = $likeSearch;
    $params[] = $likeSearch;
}

$sql .= " ORDER BY hig.glos ASC, hig.text ASC";

$allRows = queryAll($conn, $sql, $types, $params);

// Group results by glos and text
$groupedData = [];
foreach ($allRows as $row) {
    $key = $row['glos'] . '||' . $row['text'];

    if (!isset($groupedData[$key])) {
        $groupedData[$key] = [
            'glos' => $row['glos'],
            'text' => $row['text'],
            'original_glos' => $row['glos'],
            'original_text' => $row['text'],
            'sources' => [],
            'videoTop' => []
        ];
    }

    $groupedData[$key]['sources'][] = [
        'glos_id' => $row['glos_id'],
        'content_id' => $row['content_id'],
        'url' => $row['url']
    ];
}

// Fetch videoTop for each group
foreach ($groupedData as $key => &$group) {
    $videoTopArray = [];
    $processedGlosIds = [];

    foreach ($group['sources'] as $source) {
        $glosId = $source['glos_id'];
        if ($glosId !== null && !in_array($glosId, $processedGlosIds)) {
            $videoRows = queryAll($conn,
                "SELECT videoTop FROM CameraRecords WHERE id = ? AND zOg = 'begrip'",
                "i", [$glosId]
            );
            foreach ($videoRows as $videoRow) {
                if (!empty($videoRow['videoTop'])) {
                    $decoded = json_decode($videoRow['videoTop'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $videoTopArray = array_merge($videoTopArray, $decoded);
                    } else {
                        $videoTopArray[] = $videoRow['videoTop'];
                    }
                }
            }
            $processedGlosIds[] = $glosId;
        }
    }
    $group['videoTop'] = array_values(array_unique($videoTopArray));
}
unset($group);

$finalGroupedData = array_values($groupedData);
$total = count($finalGroupedData);
$paginatedData = array_slice($finalGroupedData, $offset, $perPage);

jsonResponse([
    'data' => $paginatedData,
    'total' => $total,
    'page' => $page,
    'perPage' => $perPage
]);
