<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('memory_limit', '-1');

require_once __DIR__ . '/db.php';
$conn = getConnection();

// Sanitize GET parameters (replaced deprecated FILTER_SANITIZE_STRING with FILTER_DEFAULT)
$params = filter_input_array(INPUT_GET, [
    'offset'       => FILTER_VALIDATE_INT,
    'id'           => FILTER_DEFAULT,
    'thema'        => FILTER_DEFAULT,
    'limit'        => FILTER_VALIDATE_INT,
    'handle'       => FILTER_DEFAULT,
    'gloss'        => FILTER_DEFAULT,
    'glosStatus'   => FILTER_DEFAULT,
    'selectedUser' => FILTER_DEFAULT,
    'status'       => FILTER_DEFAULT,
    'menu'         => FILTER_DEFAULT,
    'glosses'      => FILTER_DEFAULT,
    'extern'       => FILTER_VALIDATE_INT,
    'label'        => FILTER_DEFAULT,
    'labels'       => FILTER_DEFAULT
]) ?? [];
$params['offset'] = $params['offset'] ?? 0;
$params['limit']  = $params['limit'] ?? 0;
$params['thema']  = urldecode($params['thema'] ?? '');
if (empty($params['selectedUser']) || $params['selectedUser'] === "undefined") {
    $params['selectedUser'] = "[";
}

/**
 * Build SQL conditions and collect binding values and types.
 */
function buildLimitSQL($params, &$bindings, &$bind_types) {
    $thema = $params['thema'];
    $gloss = $params['gloss'];
    $selectedUser = $params['selectedUser'];
    $status = $params['status'];
    $handle = $params['handle'];
    $sql = "";
    $bindings = [];
    $bind_types = "";
    
    switch($handle) {
        case "allGlosses":
            $sql = "WHERE glosZichtbaar = ?";
            $bindings[] = '0';
            $bind_types .= "s";
            break;
        case "searchGloss":
            if($thema !== "undefined" && $thema !== "") {
                $sql = "WHERE (glos LIKE ? OR senses LIKE ?) AND glosZichtbaar = ? AND thema = ?";
                $bindings[] = $gloss.'%';
                $bindings[] = $gloss.'%';
                $bindings[] = '0';
                $bindings[] = $thema;
                $bind_types .= "ssss";
            } else {
                $sql = "WHERE (glos LIKE ? OR senses LIKE ?) AND glosZichtbaar = ?";
                $bindings[] = $gloss.'%';
                $bindings[] = $gloss.'%';
                $bindings[] = '0';
                $bind_types .= "sss";
            }
            break;
        case "searchGlosses":
            $glossesArray = is_array($params['glosses']) ? $params['glosses'] : json_decode($params['glosses'], true);
            if (is_array($glossesArray)) {
                $conditions = [];
                foreach ($glossesArray as $g) {
                    $conditions[] = "glos LIKE ?";
                    $bindings[] = '%'.$g.'%';
                    $bind_types .= "s";
                }
                $sql = "WHERE (" . implode(" OR ", $conditions) . ") AND glosZichtbaar = ?";
                $bindings[] = '0';
                $bind_types .= "s";
            }
            break;
        case "allGlossesStudio":
            $sql = "WHERE glosZichtbaar = ? AND thema NOT LIKE ?";
            $bindings[] = '0';
            $bindings[] = '%GEBARENSTRAND%';
            $bind_types .= "ss";
            break;
        case "themaFilter":
        case "wieFilter":
            if($thema !== "undefined" && $thema !== "") {
                $sql = "WHERE thema = ? AND glosZichtbaar = ? AND wie LIKE ?";
                $bindings[] = $thema;
                $bindings[] = '0';
                $bindings[] = '%'.$selectedUser.'%';
                $bind_types .= "sss";
            } else {
                $sql = "WHERE glosZichtbaar = ? AND wie LIKE ?";
                $bindings[] = '0';
                $bindings[] = '%'.$selectedUser.'%';
                $bind_types .= "ss";
            }
            break;
        case "myGlosses":
            $sql = "WHERE wie LIKE ? AND glosZichtbaar = ?";
            $bindings[] = '%'.$params['id'].'%';
            $bindings[] = '0';
            $bind_types .= "ss";
            break;
        case "sortWoordvorm":
            $sql = "ORDER BY woord DESC";
            break;
        case "statusFilter":
            if($status == "Verborgen") {
                $sql = "WHERE glosZichtbaar = ? AND wie LIKE ? AND thema LIKE ?";
                $bindings[] = '1';
                $bindings[] = '%'.$selectedUser.'%';
                $bindings[] = '%'.$thema.'%';
                $bind_types .= "sss";
            } else if ($status == "StudioOpname"){
                       // Use a subquery approach to avoid GROUP BY issues
            $sql = "WHERE form_data.id IN (
                SELECT DISTINCT mt.definitive_outcome 
                FROM matched_transcriptions mt 
                WHERE (mt.zOg = 'Glos' OR mt.zOg = '' OR mt.zOg='extern' OR mt.zOg='labels' OR mt.zOg='nmm') 
                AND mt.added != 'DELETE'
            ) AND form_data.glosZichtbaar = ?";
        $bindings[] = '0';
        $bind_types .= "s";
        
        // Add thema filter if provided
        if($thema !== "undefined" && $thema !== "") {
            $sql .= " AND thema = ?";
            $bindings[] = $thema;
            $bind_types .= "s";
        }
        
        // Add user filter if provided
        if($selectedUser !== "[") {
            $sql .= " AND wie LIKE ?";
            $bindings[] = '%'.$selectedUser.'%';
            $bind_types .= "s";
        }
                
            
        } else if ($status == "StudioOpnameNiet"){
            // Use a subquery approach to avoid GROUP BY issues
             $sql = "WHERE form_data.id NOT IN (
                SELECT DISTINCT mt.definitive_outcome 
                FROM matched_transcriptions mt 
                WHERE (mt.zOg = 'Glos' OR mt.zOg = '' OR mt.zOg='extern' OR mt.zOg='labels' OR mt.zOg='nmm') 
                AND mt.added != 'DELETE'
            ) AND form_data.glosZichtbaar = ?";
        $bindings[] = '0';
        $bind_types .= "s";
        
        // Add thema filter if provided
        if($thema !== "undefined" && $thema !== "") {
            $sql .= " AND thema = ?";
            $bindings[] = $thema;
            $bind_types .= "s";
        }
        
        // Add user filter if provided
        if($selectedUser !== "[") {
            $sql .= " AND wie LIKE ?";
            $bindings[] = '%'.$selectedUser.'%';
            $bind_types .= "s";
        }

            }
            
            else {
                $sql = "WHERE glosZichtbaar = ? AND wie LIKE ? AND thema = ?";
                $bindings[] = '0';
                $bindings[] = '%'.$selectedUser.'%';
                $bindings[] = $thema;
                $bind_types .= "sss";
            }
            break;
        case "madeByWie":
            $sql = "WHERE madeByWie = ?";
            $bindings[] = $params['id'];
            $bind_types .= "s";
            break;
        default:
            if($thema !== "undefined" && $thema !== "") {
                $sql = "WHERE thema = ? AND glosZichtbaar = ? AND wie LIKE ?";
                $bindings[] = $thema;
                $bindings[] = '0';
                $bindings[] = '%'.$selectedUser.'%';
                $bind_types .= "sss";
            } else {
                $sql = "WHERE glosZichtbaar = ? AND wie LIKE ?";
                $bindings[] = '0';
                $bindings[] = '%'.$selectedUser.'%';
                $bind_types .= "ss";
            }
    }
    // Extend with label filter - handle both single label and multiple labels
    if (isset($params['labels']) && $params['labels'] !== "" && $params['labels'] !== "undefined") {
        $labelsArray = json_decode($params['labels'], true);
        if (is_array($labelsArray) && !empty($labelsArray)) {
            $labelConditions = [];
            foreach ($labelsArray as $label) {
                if (!empty($label)) {
                    $labelConditions[] = "REPLACE(labels, '\\\\', '') LIKE ?";
                    $bindings[] = '%' . $label . '%';
                    $bind_types .= "s";
                }
            }
            if (!empty($labelConditions)) {
                if (stripos($sql, "WHERE") !== false) {
                    $sql .= " AND (" . implode(" AND ", $labelConditions) . ")";
                } else {
                    $sql = "WHERE (" . implode(" AND ", $labelConditions) . ")";
                }
            }
        }
    } elseif (isset($params['label']) && $params['label'] !== "" && $params['label'] !== "undefined") {
        // Fallback for single label (backward compatibility)
        if (stripos($sql, "WHERE") !== false) {
            $sql .= " AND REPLACE(labels, '\\\\', '') LIKE ?";
        } else {
            $sql = "WHERE REPLACE(labels, '\\\\', '') LIKE ?";
        }
        $bindings[] = '%' . $params['label'] . '%';
        $bind_types .= "s";
    }
    
    // Append extern condition if provided
    if (isset($params['extern']) && $params['extern'] == 1) {
        if (stripos($sql, "WHERE") !== false) {
            $sql .= " AND extern LIKE ?";
        } else {
            $sql = "WHERE extern LIKE ?";
        }
        $bindings[] = '1';
        $bind_types .= "s";
    }
    return $sql;
}

// Build main query
$bindings = [];
$bind_types = "";
$limitSQL = buildLimitSQL($params, $bindings, $bind_types);
$limitSQL .= " ORDER BY glos ASC LIMIT ? OFFSET ?";
$bindings[] = $params['limit'];
$bindings[] = $params['offset'];
$bind_types .= "ii";

$query = "SELECT * FROM form_data " . $limitSQL;
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("An error occurred.");
}

// Bind parameters dynamically
$params_ref = [];
$params_ref[] = & $bind_types;
for ($i = 0; $i < count($bindings); $i++) {
    $params_ref[] = & $bindings[$i];
}
call_user_func_array([$stmt, 'bind_param'], $params_ref);

$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);

// Total count query
$totalCountSql = "SELECT COUNT(*) as count FROM form_data";
if (isset($params['extern']) && $params['extern'] == 1) {
    $totalCountSql .= " WHERE extern = 1";
}
$totalCount = $conn->query($totalCountSql)->fetch_assoc()['count'];

// Signbank count query
$signbankSql = "SELECT COUNT(*) as signbankCount FROM form_data WHERE processed = '2'";
if (isset($params['extern']) && $params['extern'] == 1) {
    $signbankSql .= " AND extern = 1";
}
$signbankCount = $conn->query($signbankSql)->fetch_assoc()['signbankCount'];

// Query count with same condition
$dummyBindings = [];
$dummyBindTypes = "";
$condition = buildLimitSQL($params, $dummyBindings, $dummyBindTypes);
$queryCountStmt = $conn->prepare("SELECT COUNT(*) as count FROM form_data " . $condition);
if ($dummyBindings) {
    $params_ref_dummy = [];
    $params_ref_dummy[] = & $dummyBindTypes;
    for ($i = 0; $i < count($dummyBindings); $i++) {
        $params_ref_dummy[] = & $dummyBindings[$i];
    }
    call_user_func_array([$queryCountStmt, 'bind_param'], $params_ref_dummy);
}
$queryCountStmt->execute();
$queryCount = $queryCountStmt->get_result()->fetch_assoc()['count'];

/** Preload transcriptions */
$transcriptions = [];
$tRes = $conn->query("SELECT * FROM matched_transcriptions WHERE (zOg = 'Glos' OR zOg = '' OR zOg='extern' OR zOg='labels' OR zOg='nmm') AND added != 'DELETE' ORDER BY date DESC, time DESC");
while ($tRow = $tRes->fetch_assoc()) {
    $transcriptions[$tRow['definitive_outcome']][] = $tRow;
}

/** Preload camera records */
$cameraRecords = [];
$cRes = $conn->query("SELECT * FROM CameraRecords WHERE (zOg = 'Glos' OR zOg IS NULL OR zOg = 'Extern' OR zOg='labels' OR zOg='nmm') AND stateVideo != 'DELETE'");
while ($cRow = $cRes->fetch_assoc()) {
    $cameraRecords[$cRow['glosId']][] = $cRow;
}

/** Utility: Normalize array output */
function normalizeArrayOutput($input) {
    if (empty($input)) return $input;
    $data = is_string($input) ? json_decode($input, true) : $input;
    if (!is_array($data)) $data = [$data];
    $flattened = [];
    foreach ($data as $item) {
        $flattened = array_merge($flattened, is_array($item) ? $item : [$item]);
    }
    return json_encode($flattened);
}

$data = [];
foreach ($rows as $row) {
    $videoLeft = $videoCenter = $videoRight = $videoA = $videoB = [];
    $processed = "";
    if (isset($transcriptions[$row['id']])) {
        foreach ($transcriptions[$row['id']] as $t) {
            $videoLeft[]   = ["file" => str_replace(".wav", ".mp4", $t["l_file"])];
            $videoCenter[] = ["file" => str_replace(".wav", ".mp4", $t["m_file"]), "id" => $t["id"], "added" => $t["added"]];
            $videoRight[]  = ["file" => str_replace(".wav", ".mp4", $t["r_file"])];
            $videoA[]      = ["file" => str_replace(".wav", ".mp4", $t["a_file"])];
            $videoB[]      = ["file" => str_replace(".wav", ".mp4", $t["b_file"])];
            $processed = ($t["post_processed"] == "1") ? "2" : "1";
        }
    }
    $videoTop = [];
    if (isset($cameraRecords[$row['id']])) {
        foreach ($cameraRecords[$row['id']] as $rec) {
            if ($params['menu'] == "true" && in_array($rec['user'], ["14", "15", "16", "7", "17"])) continue;
            if (!empty($rec['videoTop'])) {
                $videoTop[] = ["videoTop" => $rec['videoTop'], "userId" => $rec['user']];
            }
        }
    }
    $usd_file = "";
    $mocapStmt = $conn->prepare("SELECT * FROM mocap_files WHERE glos = ? ORDER BY id DESC LIMIT 1");
    $mocapStmt->bind_param("s", $row['glos']);
    $mocapStmt->execute();
    $mocapRes = $mocapStmt->get_result();
    if ($mocapRow = $mocapRes->fetch_assoc()) {
        $usd_file = str_replace([".fbx", ".glb"], ".usdz", $mocapRow["filename"]);
    }
    $data[] = [
        "processed"         => $processed,
        "woord"             => $row["woord"] ?? "",
        "signbank"          => $row["signbank"] ?? "",
        "actie"             => $row["actie"] ?? "",
        "entry"             => $row["entry"] ?? "",
        "glos"              => htmlspecialchars($row["glos"], ENT_QUOTES, 'UTF-8'),
        "glos_engels"       => htmlspecialchars($row["glos_engels"] ?? "", ENT_QUOTES, 'UTF-8'),
        "wanneer"           => $row["wanneer"] ?? "",
        "control_nodig"     => $row["control_nodig"] ?? "",
        "fonologie_fase1"   => $row["fonologie_fase1"] ?? "",
        "fonologie_fase2"   => $row["fonologie_fase2"] ?? "",
        "senses"            => $row["senses"] ?? "",
        "linkSignbank"      => $row["linkSignbank"] ?? "",
        "gbc"               => $row["gbc"] ?? "",
        "wie"               => normalizeArrayOutput($row["wie"]),
        "wie_snel_opname"   => json_decode($row["wie_snel_opname"]),
        "studioOpnameStatus"=> $row["studioOpnameStatus"] ?? "",
        "studioOpnameWie"   => $row["studioOpnameWie"] ?? "",
        "glosZichtbaar"     => $row["glosZichtbaar"] ?? "",
        "zelfopname"        => $row["zelfopname"] ?? "",
        "id"                => $row["id"] ?? "",
        "signbank_opname"   => $row["signbank_opname"] ?? "",
        "thema"             => $row["thema"] ?? "",
        "count"             => $totalCount,
        "signbankCount"     => $signbankCount,
        "sensesEngels"      => $row["sensesEngels"] ?? "",
        "unreal_take"       => $row["unreal_take"] ?? "",
        "queryCount"        => $queryCount,
        "Handeness"         => $row["Handeness"] ?? "",
        "madeByWie"         => $row["madeByWie"] ?? "",
        // "logboek"           => ($params['handle'] != "allGlosses") ? ($row["logboek"] ?? "") : "",
        "videoLeft"         => json_encode($videoLeft),
        "videoCenter"       => json_encode($videoCenter),
        "videoRight"        => json_encode($videoRight),
        "videoA"            => json_encode($videoA),
        "videoB"            => json_encode($videoB),
        "videoTop"          => $videoTop,
        "usd_file"          => $usd_file,
        "labels"            => $row["labels"] ?? "",
    ];
}

// For debugging: capture query and binding info
$debug = [
    "query"      => $query,
    "bindings"   => $bindings,
    "bind_types" => $bind_types
];

$output = [
    "data"  => $data,
    "debug" => $debug
];

header("Content-Type: application/json");
echo json_encode($output, JSON_UNESCAPED_SLASHES);
$conn->close();
?>
