<?php
require_once __DIR__ . '/../db.php';
$conn = getConnection();

header('Content-Type: application/json');
error_reporting(0);

$errors = [];
$data = json_decode(file_get_contents('php://input'), true);



function hasDuplicateZinArray($conn, $zinArray) {
    $zinArray = strtolower($zinArray);
    $zinArrayArray = explode(' ', $zinArray);
    $zinArrayJson = json_encode($zinArrayArray);

    $sql = "SELECT COUNT(*) as count FROM sentences WHERE JSON_CONTAINS(zinArray, ?) AND JSON_LENGTH(zinArray) = JSON_LENGTH(?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('ss', $zinArrayJson, $zinArrayJson);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return $count >= 1;
}

// Handle POST request to update glosArray, sentence, sortArray, or batch add sentences
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : null;
    $thema = isset($_POST['thema']) ? $_POST['thema'] : null;
    $rowId = isset($_POST['rowId']) ? intval($_POST['rowId']) : null;
    $action = isset($_POST['action']) ? $_POST['action'] : null;

    // Handle updating glosArray
    if (isset($_POST['elementIndex']) && isset($_POST['newWord']) && $id !== null) {
        $elementIndex = intval($_POST['elementIndex']);
        $newWord = $_POST['newWord'];

        $sql = "SELECT glosArray FROM sentences WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errors[] = 'Prepare failed: ' . $conn->error;
            echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
            exit();
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $glosArray = json_decode($row['glosArray'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'JSON decode error: ' . json_last_error_msg();
            }

            if (!is_array($glosArray)) {
                $glosArray = [];
            }

            $glosArray[$elementIndex] = $newWord;
            $newGlosArray = json_encode($glosArray);

            $updateSql = "UPDATE sentences SET glosArray = ?, thema = ? WHERE ID = ?";
            $updateStmt = $conn->prepare($updateSql);
            if (!$updateStmt) {
                $errors[] = 'Prepare failed: ' . $conn->error;
            } else {
                $updateStmt->bind_param('ssi', $newGlosArray, $thema, $id);
                $success = $updateStmt->execute();
                if (!$success) {
                    $errors[] = 'Execute failed: ' . $updateStmt->error;
                }
            }

            echo json_encode(['success' => $success, 'errors' => $errors]);
        } else {
            $errors[] = 'Row not found';
            echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
        }

        $stmt->close();
    }
    // Handle updating sentence and glosArray
    elseif (isset($_POST['updatedSentence']) && isset($_POST['updatedGlosArray']) && $id !== null) {
        $updatedSentence = $_POST['updatedSentence'];
        $updatedGlosArray = json_decode($_POST['updatedGlosArray'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'JSON decode error: ' . json_last_error_msg();
        }

        $zinArray = json_encode(explode(' ', $updatedSentence));
        $glosArray = json_encode($updatedGlosArray);

        $updateSql = "UPDATE sentences SET zinArray = ?, glosArray = ?, thema = ? WHERE ID = ?";
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            $errors[] = 'Prepare failed: ' . $conn->error;
        } else {
            $updateStmt->bind_param('sssi', $zinArray, $glosArray, $thema, $id);
            $success = $updateStmt->execute();
            if (!$success) {
                $errors[] = 'Execute failed: ' . $updateStmt->error;
            }
        }

        echo json_encode(['success' => $success, 'errors' => $errors]);

        $updateStmt->close();
    }
    // Handle updating sortArray
    elseif (isset($_POST['newSortArray']) && $id !== null) {
        $newSortArray = json_decode($_POST['newSortArray'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'JSON decode error: ' . json_last_error_msg();
        }

        $sortArray = json_encode($newSortArray);

        $updateSql = "UPDATE sentences SET sortArray = ?, thema = ? WHERE ID = ?";
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            $errors[] = 'Prepare failed: ' . $conn->error;
        } else {
            $updateStmt->bind_param('ssi', $sortArray, $thema, $id);
            $success = $updateStmt->execute();
            if (!$success) {
                $errors[] = 'Execute failed: ' . $updateStmt->error;
            }
        }

        echo json_encode(['success' => $success, 'errors' => $errors]);

        $updateStmt->close();
    }
    // Handle batch adding sentences
    elseif (isset($data['sentences'])) {
        $sentences = $data['sentences'];
        $thema = $data['thema'] ?? null;

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'JSON decode error: ' . json_last_error_msg();
            echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
            exit();
        }

        $successCount = 0;
        $failedCount = 0;
        $skippedSentences = [];
        $addedSentences = [];

        $existingSentences = [];

        foreach ($sentences as $sentence) {
            if (hasDuplicateZinArray($conn, $sentence)) {
                $skippedSentences[] = $sentence;
            } else {
                $existingSentences[] = $sentence;
            }
        }

        if (count($existingSentences) > 0) {
            $insertSql = "INSERT INTO sentences (zinArray, glosArray, thema) VALUES (?, ?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) {
                $errors[] = 'Prepare failed (insert): ' . $conn->error;
                echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
                exit();
            }

            foreach ($existingSentences as $sentence) {
                $zinArray = json_encode(explode(' ', trim($sentence)));
                $glosArray = json_encode(array_fill(0, count(explode(' ', trim($sentence))), ""));

                $insertStmt->bind_param('sss', $zinArray, $glosArray, $thema);
                if ($insertStmt->execute()) {
                    $successCount++;
                    $addedSentences[] = $sentence;
                } else {
                    $failedCount++;
                    $errors[] = 'Insert failed: ' . $insertStmt->error;
                }
            }

            $insertStmt->close();
        }

        echo json_encode([
            'success' => $failedCount === 0,
            'inserted' => $successCount,
            'failed' => $failedCount,
            'message' => $failedCount === 0 ? 'All sentences added successfully!' : "Failed to add $failedCount sentences.",
            'added_sentences' => $addedSentences,
            'skipped_sentences' => $skippedSentences,
            'errors' => $errors
        ]);
    } 
    // Handle GET request to fetch unique thema values
 // Handle saving (creating or updating) a thema
 elseif ($action === 'saveThema' && $thema !== null && $rowId !== null) {
    // Update or create the thema in the database for the specific row
    $updateSql = "UPDATE sentences SET thema = ? WHERE ID = ?";
    $updateStmt = $conn->prepare($updateSql);
    if (!$updateStmt) {
        $errors[] = 'Prepare failed: ' . $conn->error;
        echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
        exit();
    }
    $updateStmt->bind_param('si', $thema, $rowId);
    $success = $updateStmt->execute();
    if (!$success) {
        $errors[] = 'Execute failed: ' . $updateStmt->error;
    }

    echo json_encode(['success' => $success, 'errors' => $errors]);
    $updateStmt->close();
}
    
    else {
        $errors[] = 'Invalid parameters';
        $errors[] = 'POST: ' . json_encode($_POST);
        echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
    }

}
// Handle GET request to fetch sentences
else {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $limit = 30;
    $offset = ($page - 1) * $limit;
    $thema = isset($_GET['thema']) ? $_GET['thema'] : null;
    $filterThema = isset($_GET['filterThema']) ? $_GET['filterThema'] : null;
    $userId = isset($_GET['userId']) ? $_GET['userId'] : "%";

    $sentences = array();

    if ($id) {
        $sql = "SELECT ID, zinID, glosArray, zinArray, sortArray, thema, videoTop FROM sentences WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errors[] = 'Prepare failed: ' . $conn->error;
            echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
            exit();
        }
        $stmt->bind_param('i', $id);
    } else {
        if ($thema) {
            $sql = "SELECT DISTINCT thema FROM sentences WHERE thema IS NOT NULL AND thema != '' ORDER BY thema";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $errors[] = 'Prepare failed: ' . $conn->error;
                echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
                exit();
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $themas = [];

            while ($row = $result->fetch_assoc()) {
                $themas[] = ['thema' => $row['thema']];
            }

            echo json_encode(['rows' => $themas, 'hasMoreRows' => false, 'errors' => $errors]);
            $stmt->close();
            $conn->close();
            exit();
        }
        elseif($filterThema)
        {
                        $sql = "SELECT ID, zinID, glosArray, zinArray, sortArray, thema, videoTop FROM sentences WHERE " . 
                        ($filterThema == "undefined" ? "" : "thema = ?");
                $stmt = $conn->prepare($sql);
                
                if (!$stmt) {
                    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                    exit();
                }
                
                if ($filterThema == "undefined") {
                } else {
                    $stmt->bind_param('s', $filterThema);
                }
     
        } else {
            $sql = "SELECT ID, zinID, glosArray, zinArray, sortArray, thema, videoTop FROM sentences LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $errors[] = 'Prepare failed: ' . $conn->error;
                echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
                exit();
            }
            $stmt->bind_param('ii', $limit, $offset);
        }
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        //we are going to get the videos for each sentence from CameraRecords
        $sql = "SELECT camera1, camera2, camera3, camera4, camera5, videoTop, datetime_ms 
        FROM CameraRecords 
        WHERE zOg='Zin' AND glosId = ?";

        $stmt2 = $conn->prepare($sql);
        $stmt2->bind_param('i', $row['ID']);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $videos = [];
        while ($row2 = $result2->fetch_assoc()) {
            $videos[] = $row2;
        }

        $sentences[] = array(
            'ID' => $row['ID'],
            'zinID' => $row['zinID'],
            'glosArray' => json_decode($row['glosArray'], true),
            'zinArray' => json_decode($row['zinArray'], true),
            'sortArray' => json_decode($row['sortArray'], true),
            'thema' => $row['thema'],
            'videoTop' => $row['videoTop'],
            'videos' => $videos
        );
    }

    if ($id) {
        echo json_encode($sentences[0] ?? []);
    } else {
        $hasMoreRows = $result->num_rows === $limit;
        echo json_encode(['rows' => $sentences, 'hasMoreRows' => $hasMoreRows, 'errors' => $errors]);
    }

    $stmt->close();
}

$conn->close();
?>
