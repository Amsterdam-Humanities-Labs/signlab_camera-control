<?php
// fx30debuglog.php — debug-logging voor de webapp <-> fx30MultiRecord interactie.
// De browser POST {time, tag, data}; dit script schrijft één regel per event naar
// logs/fx30_debug.log zodat de interactie server-side te volgen is.
// Uitzetten in de browser met ?fxdebug=0 op de pagina-URL.

$LOG_FILE = __DIR__ . '/logs/fx30_debug.log';
$MAX_SIZE = 5 * 1024 * 1024; // simpele rotatie boven 5 MB

header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$entry = json_decode($payload, true);
if (!is_array($entry)) {
    echo json_encode(array('error' => 'invalid json'));
    exit;
}

$tag = isset($entry['tag']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', $entry['tag']) : '-';
$time = isset($entry['time']) ? $entry['time'] : date('c');
$data = isset($entry['data']) ? json_encode($entry['data'], JSON_UNESCAPED_SLASHES) : 'null';

if (file_exists($LOG_FILE) && filesize($LOG_FILE) > $MAX_SIZE) {
    @rename($LOG_FILE, $LOG_FILE . '.1');
}
file_put_contents($LOG_FILE, $time . ' [' . $tag . '] ' . $data . "\n", FILE_APPEND | LOCK_EX);
echo json_encode(array('status' => 'ok'));
