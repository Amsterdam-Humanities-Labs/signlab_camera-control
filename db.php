<?php
/**
 * Shared database helper for studio_beta.
 *
 * Usage:
 *   require_once __DIR__ . '/db.php';
 *   $conn = getConnection();
 *   $rows = queryAll($conn, "SELECT * FROM users WHERE id = ?", "i", [42]);
 *   $row  = queryOne($conn, "SELECT * FROM users WHERE id = ?", "i", [42]);
 *   execute($conn, "UPDATE users SET name = ? WHERE id = ?", "si", ["Alice", 42]);
 *   jsonResponse($rows);
 */

include(__DIR__ . '/mysql_config.php');

/**
 * Create and return a mysqli connection.
 */
function getConnection(): mysqli {
    global $servername, $username, $password, $database;
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Connection failed: ' . $conn->connect_error]);
        exit();
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

/**
 * Execute a query and return all rows as an associative array.
 */
function queryAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed: " . $conn->error);
    }
    if ($types !== '' && count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Execute a query and return the first row, or null.
 */
function queryOne(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = queryAll($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

/**
 * Execute a non-SELECT statement (INSERT, UPDATE, DELETE). Returns the statement for insert_id etc.
 */
function execute(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed: " . $conn->error);
    }
    if ($types !== '' && count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

// --- Input helpers ---

function getInt(string $key, ?int $default = null): ?int {
    return isset($_GET[$key]) ? intval($_GET[$key]) : $default;
}

function getString(string $key, ?string $default = null): ?string {
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

function postString(string $key, ?string $default = null): ?string {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function postInt(string $key, ?int $default = null): ?int {
    return isset($_POST[$key]) ? intval($_POST[$key]) : $default;
}

// --- Response helper ---

function jsonResponse($data, int $statusCode = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    if ($statusCode !== 200) {
        http_response_code($statusCode);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}
