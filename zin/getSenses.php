<?php
require_once __DIR__ . '/../db.php';
$conn = getConnection();

error_reporting(E_ERROR | E_PARSE);

// Retrieve $sense or $glosID from $_GET and convert to lowercase (for case-insensitive comparison)
$sense = isset($_GET['sense']) ? strtolower($_GET['sense']) : null;

//remove any symbol from $sense
if($sense != null){
    $sense = preg_replace('/[^A-Za-z0-9]/', '', $sense);

}


$glosID = isset($_GET['glosid']) ? $_GET['glosid'] : null;
$glosID = isset($_GET['glosId']) ? $_GET['glosId'] : $glosID;

if (empty($sense) && empty($glosID)) {
    echo json_encode(['error' => true, 'message' => 'No input provided']);
    exit();
}

$output = [];

// Function to check if a sense string matches the exact search term
function senseMatches($senseString, $searchTerm) {
    // Split the sense string into individual items, assuming they are comma-separated
    $senseArray = array_map('trim', explode(',', $senseString));

    foreach ($senseArray as $senseItem) {
        // Skip phrases and sentences
        if (strpos($senseItem, ' ') === false && strtolower($senseItem) === $searchTerm) {
            return true;
        }
    }
    return false;
}

// Function to get the base verb using an external API
function getBaseVerbFromApi($searchTerm, $debug = false) {

    // Define the API endpoint
    $apiUrl = 'https://leffe.science.uva.nl:8043/getWords/search?term=' . urlencode($searchTerm);

    // Initialize a cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // Execute the cURL request
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo json_encode(['error' => 'Error: ' . curl_error($ch)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        curl_close($ch);
        return null;
    }

    // Decode the JSON response
    $data = json_decode($response, true);

    // Close the cURL session
    curl_close($ch);

    $baseForm = null;

    // Iterate through the results to find the base form
    foreach ($data as $entry) {
        if (isset($entry['lang'], $entry['lang_code'], $entry['pos']) &&
            $entry['lang'] === 'Dutch' && $entry['lang_code'] === 'nl') {

            if ($entry['pos'] === 'verb') {
                // Check if the search term is already a valid verb in infinitive form
                if ($entry['word'] === $searchTerm && substr($searchTerm, -2) === 'en') {
                    $baseForm = $searchTerm;
                    break;
                }

                // Check if the entry contains links to a base verb with #Dutch
                if (isset($entry['senses'])) {
                    foreach ($entry['senses'] as $sense) {
                        if (isset($sense['links'][0][0])) {
                            foreach ($sense['links'] as $link) {
                                if (strpos($link[1], '#Dutch') !== false) {
                                    $baseForm = $link[0]; // Use the Dutch verb link
                                    break 3; // Exit all loops
                                }
                            }
                        }
                    }
                }
            } elseif ($entry['pos'] === 'noun') {
                // Check if the search term is already a valid noun
                if ($entry['word'] === $searchTerm) {
                    $baseForm = $searchTerm;
                }

                // Check if the entry contains links to a singular noun
                if (isset($entry['senses'])) {
                    foreach ($entry['senses'] as $sense) {
                        if (isset($sense['form_of'][0]['word'])) {
                            $baseForm = $sense['form_of'][0]['word'];
                            break 2; // Exit loops once a match is found
                        }
                    }
                }
            }
        }
    }

    // Prepare the JSON response
    // if ($baseForm) {
    //     echo json_encode(['base_form' => $baseForm], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    // } 

    // If debug mode is enabled, print the full JSON response
    if ($debug) {
        echo "\nDebug Mode: Full JSON Response:\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    return $baseForm;
}

// Function to perform search in Signbank and Signcollect
function searchInSignbankAndCollect($conn, $senseOrGlosID, $isSense = true) {
    $output = [];

    if ($isSense) {
        $searchTerm = "%\"{$senseOrGlosID}\"%";
        $query = "SELECT glos, senses, signbank, id FROM form_data WHERE senses LIKE ? AND glosZichtbaar = 0";
    } else {
        $searchTerm = $senseOrGlosID;
        $query = "SELECT glos, senses, signbank, id FROM form_data WHERE signbank = ? AND glosZichtbaar = 0";
    }

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $videoSource = '';

            // Get the video source if the source is Signcollect
            $id = $row['id'];
            $videoQuery = "SELECT m_file FROM matched_transcriptions WHERE m_transcription = ?";
            $videoStmt = $conn->prepare($videoQuery);
            $videoStmt->bind_param("i", $id);
            $videoStmt->execute();
            $videoResult = $videoStmt->get_result();
            
            if ($videoResult->num_rows > 0) {
                $videoRow = $videoResult->fetch_assoc();
                $videoFile = str_replace('.wav', '.mp4', $videoRow['m_file']);
                $videoSource = "https://leffe.science.uva.nl:8043/gebarenoverleg_media/studioFilesMini/raw/{$videoFile}";
            }

            $output[$row['signbank']] = [
                'glos' => $row['glos'],
                'glosID' => $row['signbank'],
                'sense' => json_decode($row['senses']), // Decode to remove backslashes
                'source' => 'Signcollect',
                'video' => $videoSource
            ];
        }
    }

    // Look for the glosID in the Signbank JSON file
    $signbankJson = "../glosses_transformed.json";
    $signbank = json_decode(file_get_contents($signbankJson), true);

    foreach ($signbank as $entry) {
        foreach ($entry as $key => $details) {
            if ($isSense) {
                $sensesDutch = $details['Senses: Dutch'] ?? [];
                $sensesEnglish = $details['Senses: English'] ?? [];
                $found = false;

                foreach ($sensesDutch as $senseString) {
                    if (senseMatches($senseString, $senseOrGlosID)) {
                        $found = true;
                        break;
                    }
                }

                foreach ($sensesEnglish as $senseString) {
                    if (senseMatches($senseString, $senseOrGlosID)) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) continue;
            } else {
                if ($key != $senseOrGlosID) continue;
            }

            $glos = $details['Annotation ID Gloss: Dutch'] ?? '';
            $videoSource = !empty($glos) ? "https://leffe.science.uva.nl:8043/uploads/{$glos}.mp4" : '';

            if (!empty($videoSource)) {
                $output[$key] = [
                    'glos' => $details['Annotation ID Gloss: Dutch'],
                    'glosID' => $key,
                    'sense' => $details['Senses: Dutch'], // Already decoded
                    'source' => 'Signbank',
                    'video' => $videoSource
                ];
            } elseif (isset($output[$key]) && empty($output[$key]['video'])) {
                $output[$key] = [
                    'glos' => $details['Annotation ID Gloss: Dutch'],
                    'glosID' => $key,
                    'sense' => $details['Senses: Dutch'], // Already decoded
                    'source' => 'Signbank',
                    'video' => $output[$key]['video'] // Keep the Signcollect video if it exists
                ];
            }
        }
    }

    return $output;
}

// Initial search in Signbank and Signcollect
$output = searchInSignbankAndCollect($conn, $sense ?? $glosID, isset($sense));

// If no results found and sense is provided, try looking up the base verb using the API
if (empty($output)) {

    $baseVerb = getBaseVerbFromApi($sense);

    if ($baseVerb) {
        // Perform search again using the base verb
        $output = searchInSignbankAndCollect($conn, $baseVerb, true);

        //if 
    }
}

// Convert the output array to a sequential array for JSON encoding
$filteredOutput = array_values($output);

// Return the filtered output
echo json_encode($filteredOutput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
