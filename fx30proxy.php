<?php
// fx30proxy.php — same-origin proxy naar de fx30MultiRecord camera controller.
// De controller stuurt geen CORS-headers, dus de
// browser kan hem niet rechtstreeks op een ander apparaat aanroepen; alle
// /api/* requests lopen via dit script.
//
// Gebruik: fx30proxy.php?path=/api/status[&host=192.168.1.50:8080][&timeout=30]
// POST requests worden met body doorgestuurd.

// Login required: this reaches the studio cameras / controller Mac.
require_once __DIR__ . '/require_login.php';

$DEFAULT_HOST = 'signlabs-mini.taila8bdbd.ts.net:8080'; // controller-Mac (signlab, Tailscale)

$host = isset($_GET['host']) && $_GET['host'] !== '' ? $_GET['host'] : $DEFAULT_HOST;
$path = isset($_GET['path']) ? $_GET['path'] : '/api/status';
$timeout = isset($_GET['timeout']) ? min(600, max(1, (int)$_GET['timeout'])) : 30;

header('Content-Type: application/json');

if (strpos($path, '/api/') !== 0) {
    echo json_encode(array('error' => 'Invalid path, must start with /api/'));
    exit;
}
if (!preg_match('/^[A-Za-z0-9.\-]+(:[0-9]+)?$/', $host)) {
    echo json_encode(array('error' => 'Invalid host'));
    exit;
}

$ch = curl_init('http://' . $host . $path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    // de controller eist een JSON-body bij elke POST (kale POST geeft HTTP 400)
    if ($body === false || $body === '') {
        $body = '{}';
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
}

$result = curl_exec($ch);
if ($result === false) {
    // zelfde error-conventie als de controller: altijd HTTP 200, fout in JSON-body
    echo json_encode(array('error' => 'Controller unreachable: ' . curl_error($ch)));
} else {
    echo $result;
}
curl_close($ch);
