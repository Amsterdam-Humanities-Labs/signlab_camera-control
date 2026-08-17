<?php
require_once __DIR__ . '/../db.php';
$conn = getConnection();

$rows = queryAll($conn, "SELECT DISTINCT thema FROM nmm_data WHERE thema IS NOT NULL AND thema != ''");

$themas = array_map(fn($row) => ['thema' => $row['thema']], $rows);

jsonResponse(['rows' => $themas]);
