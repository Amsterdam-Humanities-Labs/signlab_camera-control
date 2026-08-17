<?php
require_once __DIR__ . '/db.php';
$conn = getConnection();

$rows = queryAll($conn, "SELECT userId, user FROM users");

$userData = array_map(fn($row) => [
    'userid' => $row['userId'],
    'user' => $row['user']
], $rows);

jsonResponse($userData);
