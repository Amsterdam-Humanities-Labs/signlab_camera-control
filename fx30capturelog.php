<?php
// fx30capturelog.php — schrijft een capture-log entry naar de controller-Mac.
// AGENT_API_GUIDE.md (coexistence regel 5): clients die opnames starten moeten
// de verwachte clipnamen (snapshot van clipName VOOR /api/start) toevoegen aan
// pyqtController/capture_logs/{YYYY-MM-DD}.json — zelfde formaat als de
// PyQt-app, anders zijn die opnames onzichtbaar voor de sync-verificatie.
//
// Body: {"time":"<iso8601>","clips":{"<serial>":"<clipName>.MP4", ...}}

$SSH_TARGET = 'signlab@100.66.221.75';
$SSH_KEY = '/home/gomer/.ssh/id_ed25519'; // moet leesbaar zijn voor de webserver-user
$LOG_DIR = '/Users/signlab/Sony-SDK-MACOS-API/pyqtController/capture_logs';

header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$entry = json_decode($payload, true);
if (!is_array($entry) || !isset($entry['time']) || !isset($entry['clips']) || !is_array($entry['clips']) || count($entry['clips']) === 0) {
    echo json_encode(array('error' => 'Body must be {"time":"...","clips":{"serial":"name.MP4"}}'));
    exit;
}
// alleen de verwachte velden doorsturen
$entry = array('time' => (string)$entry['time'], 'clips' => $entry['clips']);

// Lokaal ook bijhouden (gebruikt door reviewToday.php), onafhankelijk van de
// ssh-stap naar de Mac — zo werkt het dagoverzicht ook als ssh faalt.
$localOk = false;
$localDir = __DIR__ . '/logs/capture_clips';
if (!is_dir($localDir)) {
    @mkdir($localDir, 0755, true);
}
$localFile = $localDir . '/' . date('Y-m-d') . '.json';
$fp = @fopen($localFile, 'c+');
if ($fp) {
    flock($fp, LOCK_EX);
    $contents = stream_get_contents($fp);
    $arr = json_decode($contents, true);
    if (!is_array($arr)) {
        $arr = array();
    }
    $arr[] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
    $localOk = true;
}

// atomaire read-modify-write op de Mac: flock op een lockfile, schrijf naar
// tempfile, dan rename — zodat de PyQt-app nooit een half geschreven JSON leest
$py = <<<'PY'
import json, sys, os, fcntl, datetime
entry = json.loads(sys.argv[1])
log_dir = sys.argv[2]
os.makedirs(log_dir, exist_ok=True)
fn = os.path.join(log_dir, datetime.date.today().isoformat() + '.json')
lock = open(fn + '.lock', 'w')
fcntl.flock(lock, fcntl.LOCK_EX)
try:
    try:
        with open(fn) as f:
            arr = json.load(f)
        if not isinstance(arr, list):
            arr = []
    except Exception:
        arr = []
    arr.append(entry)
    tmp = fn + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(arr, f, indent=2)
    os.replace(tmp, fn)
finally:
    fcntl.flock(lock, fcntl.LOCK_UN)
print(json.dumps({'status': 'logged', 'file': fn, 'entries': len(arr)}))
PY;

$remote = 'python3 -c ' . escapeshellarg($py)
        . ' ' . escapeshellarg(json_encode($entry))
        . ' ' . escapeshellarg($LOG_DIR);

$cmd = 'ssh -i ' . escapeshellarg($SSH_KEY)
     . ' -o BatchMode=yes -o ConnectTimeout=8'
     . ' -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR'
     . ' ' . escapeshellarg($SSH_TARGET)
     . ' ' . escapeshellarg($remote) . ' 2>&1';

$out = shell_exec($cmd);
$res = json_decode(trim((string)$out), true);
if (is_array($res)) {
    $res['local'] = $localOk;
    echo json_encode($res);
} elseif ($localOk) {
    // lokaal gelukt, alleen de Mac-sync van het log faalde
    echo json_encode(array('status' => 'logged-local-only', 'sshError' => trim((string)$out)));
} else {
    echo json_encode(array('error' => 'capture log failed (local en ssh): ' . trim((string)$out)));
}
