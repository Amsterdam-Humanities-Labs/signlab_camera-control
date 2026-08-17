<?php
require_once __DIR__ . '/db.php';
$conn = getConnection();

$extern = getInt('extern');

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
    $rowLabels = explode(',', $row['label']);
    foreach ($rowLabels as $label) {
        $trimmed = trim($label);
        if ($trimmed !== '' && !isset($seen[$trimmed])) {
            $seen[$trimmed] = true;
            $labels[] = [
                'name' => $trimmed,
                'color' => $row['color'] ?? '#e9ecef'
            ];
        }
    }
}

usort($labels, fn($a, $b) => strcmp($a['name'], $b['name']));

jsonResponse($labels);
