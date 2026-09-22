<?php
// Dropdown lookups for opnameViewTest.html, one endpoint instead of four.
//   ?what=thema[&extern=N]   distinct form_data themes, upper-cased (was uniqueThema.php)
//   ?what=labels[&extern=N]  distinct labels with colour              (was uniqueLabels.php)
//   ?what=users              userId + user                            (was fetch_data.php)
//   ?what=nmm_themas         distinct nmm_data themes                 (was nmm/fetch_themas.php)
require_once __DIR__ . '/db.php';
$conn = getConnection();

$extern = getInt('extern');

switch (getString('what', '')) {
case 'thema':
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

case 'labels':
    $sql = "SELECT DISTINCT label, color FROM labels WHERE label IS NOT NULL AND label != ''";
    $types = '';
    $params = [];
    if ($extern !== null) {
        $sql .= " AND extern = ?";
        $types .= "i";
        $params[] = $extern;
    }
    $sql .= " ORDER BY label ASC";
    $rows = queryAll($conn, $sql, $types, $params);

    // Explode comma-separated labels and deduplicate
    $labels = [];
    $seen = [];
    foreach ($rows as $row) {
        foreach (explode(',', $row['label']) as $label) {
            $trimmed = trim($label);
            if ($trimmed !== '' && !isset($seen[$trimmed])) {
                $seen[$trimmed] = true;
                $labels[] = ['name' => $trimmed, 'color' => $row['color'] ?? '#e9ecef'];
            }
        }
    }
    usort($labels, fn($a, $b) => strcmp($a['name'], $b['name']));
    jsonResponse($labels);

case 'users':
    $rows = queryAll($conn, "SELECT userId, user FROM users");
    jsonResponse(array_map(fn($row) => ['userid' => $row['userId'], 'user' => $row['user']], $rows));

case 'nmm_themas':
    $rows = queryAll($conn, "SELECT DISTINCT thema FROM nmm_data WHERE thema IS NOT NULL AND thema != ''");
    jsonResponse(['rows' => array_map(fn($row) => ['thema' => $row['thema']], $rows)]);

default:
    jsonResponse(['error' => 'what must be thema, labels, users or nmm_themas'], 400);
}
