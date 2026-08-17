<?php
// api.php
error_reporting(E_ERROR | E_PARSE);
require_once __DIR__ . '/../db.php';
$conn = getConnection();

$action = getString('action', 'dashboard');
$page = getInt('page', 1);
$search = getString('search', '');
$order = getString('order', 'cnt_desc');

if ($page < 1) { $page = 1; }
$perPage = getInt('perPage', 25);
$offset = ($page - 1) * $perPage;

header('Content-Type: application/json; charset=utf-8');

switch ($action) {
  case 'dashboard':
    $indexCount = $conn->query("SELECT COUNT(*) as count FROM hh_index")->fetch_assoc()['count'];
    $sentencesCount = $conn->query("SELECT COUNT(*) as count FROM hh_sentences")->fetch_assoc()['count'];
    $wordsCount = $conn->query("SELECT COUNT(*) as count FROM hh_words")->fetch_assoc()['count'];
    
    // Add unique lemma count
    $uniqueLemmasCount = $conn->query("SELECT COUNT(DISTINCT lemma) as count FROM hh_lemma")->fetch_assoc()['count'];
    
    $data = [
      'hh_index'     => $indexCount,
      'hh_sentences' => $sentencesCount,
      'hh_words'     => $wordsCount,
      'unique_lemmas' => $uniqueLemmasCount
    ];
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    break;

  case 'keywords':
    // Get top keywords and their counts
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10000;
    
    // First, get all keywords from JSON arrays and count their occurrences
    $result = $conn->query("SELECT keywords FROM hh_index WHERE keywords IS NOT NULL AND keywords != '[]'");
    
    $keywordCounts = [];
    while ($row = $result->fetch_assoc()) {
      // Fix: Make sure to properly decode and validate the JSON before accessing elements
      $keywordsJson = $row['keywords'];
      $keywordsArray = json_decode($keywordsJson, true);
      
      // Make sure $keywordsArray is actually an array before proceeding
      if (is_array($keywordsArray)) {
        foreach ($keywordsArray as $keyword) {
          // Make sure each keyword is a non-empty string
          if (is_string($keyword) && trim($keyword) !== '') {
            $keyword = trim($keyword); // Normalize by trimming whitespace
            if (array_key_exists($keyword, $keywordCounts)) {
              $keywordCounts[$keyword]++;
            } else {
              $keywordCounts[$keyword] = 1;
            }
          }
        }
      }
    }
    
    // Sort by count (descending)
    arsort($keywordCounts);
    
    // Take only the top keywords based on limit
    $topKeywords = array_slice($keywordCounts, 0, $limit, true);
    
    // Format for response
    $formattedKeywords = [];
    foreach ($topKeywords as $keyword => $count) {
      $formattedKeywords[] = [
        'keyword' => $keyword,
        'count' => $count
      ];
    }
    
    echo json_encode([
      'keywords' => $formattedKeywords,
      'total' => count($keywordCounts)
    ], JSON_UNESCAPED_UNICODE);
    break;

    case 'contents':
      $whereConditions = [];
      $bindTypes = '';
      $bindParams = [];
      $ngt_not_captured = getInt('ngt_not_captured', 0);

      if ($ngt_not_captured) {
        $whereConditions[] = "ngt_text IS NOT NULL";
      }

      if (!empty($search)) {
        $whereConditions[] = "(url LIKE ? OR plain_text LIKE ?)";
        $bindTypes .= 'ss';
        $bindParams[] = '%' . $search . '%';
        $bindParams[] = '%' . $search . '%';
      }

      if (isset($_GET['keyword']) && !empty($_GET['keyword'])) {
        $keywords = explode(',', $_GET['keyword']);
        $keywordConditions = [];
        foreach ($keywords as $keyword) {
          $keyword = trim($keyword);
          if (!empty($keyword)) {
            $keywordConditions[] = "JSON_SEARCH(keywords, 'one', ?) IS NOT NULL";
            $bindTypes .= 's';
            $bindParams[] = $keyword;
          }
        }
        if (!empty($keywordConditions)) {
          $whereConditions[] = "(" . implode(" AND ", $keywordConditions) . ")";
        }
      }

      if (isset($_GET['status']) && $_GET['status'] !== '') {
        $status = intval($_GET['status']);
        if ($status === 1) {
          $whereConditions[] = "status = 1";
        } else if ($status === 2) {
          $whereConditions[] = "status = 2";
        } else if ($status === 0) {
          $whereConditions[] = "(status IS NULL OR status = 0)";
        }
      }

      if (isset($_GET['label']) && !empty($_GET['label'])) {
        $whereConditions[] = "JSON_SEARCH(labels, 'one', ?) IS NOT NULL";
        $bindTypes .= 's';
        $bindParams[] = $_GET['label'];
      }

      $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : '';

      $sortField = getString('sort', 'id');
      $sortOrder = (getString('order') !== null && strtolower(getString('order')) === 'desc') ? 'DESC' : 'ASC';
      $allowedSortFields = ['id', 'priority'];
      if (!in_array($sortField, $allowedSortFields)) { $sortField = 'id'; }
      $orderByClause = "ORDER BY $sortField $sortOrder";

      $sql = "SELECT * FROM hh_index $whereClause $orderByClause LIMIT ?, ?";
      $bindTypes .= 'ii';
      $bindParams[] = $offset;
      $bindParams[] = $perPage;
      $rows = queryAll($conn, $sql, $bindTypes, $bindParams);

      // Total count (reuse where clause without LIMIT params)
      $totalBindTypes = substr($bindTypes, 0, -2);
      $totalBindParams = array_slice($bindParams, 0, -2);
      $totalRow = queryOne($conn, "SELECT COUNT(*) as total FROM hh_index $whereClause", $totalBindTypes, $totalBindParams);
      $total = $totalRow['total'];

      jsonResponse([
        'data'    => $rows,
        'total'   => $total,
        'page'    => $page,
        'perPage' => $perPage
      ]);
      break;
    
  case 'content_extended':
    $whereConditions = [];
    $ceBindTypes = '';
    $ceBindParams = [];
    $ngt_not_captured = getInt('ngt_not_captured', 0);

    if ($ngt_not_captured) {
      $whereConditions[] = "(ngt_text IS NOT NULL OR ngt_text2 IS NOT NULL OR ngt_text3 IS NOT NULL OR ngt_text4 IS NOT NULL) AND status IS NULL";
    }

    if (!empty($search)) {
      $whereConditions[] = "(url LIKE ? OR plain_text LIKE ?)";
      $ceBindTypes .= 'ss';
      $ceBindParams[] = '%' . $search . '%';
      $ceBindParams[] = '%' . $search . '%';
    }

    if (isset($_GET['keyword']) && !empty($_GET['keyword'])) {
      $keywords = explode(',', $_GET['keyword']);
      $keywordConditions = [];
      foreach ($keywords as $keyword) {
        $keyword = trim($keyword);
        if (!empty($keyword)) {
          $keywordConditions[] = "JSON_SEARCH(keywords, 'one', ?) IS NOT NULL";
          $ceBindTypes .= 's';
          $ceBindParams[] = $keyword;
        }
      }
      if (!empty($keywordConditions)) {
        $whereConditions[] = "(" . implode(" AND ", $keywordConditions) . ")";
      }
    }

    if (isset($_GET['status']) && $_GET['status'] !== '') {
      $status = intval($_GET['status']);
      if ($status === 1) {
        $whereConditions[] = "status = 1";
      } else if ($status === 0) {
        $whereConditions[] = "(status IS NULL OR status = 0)";
      }
    }

    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : '';

    $sortField = getString('sort', 'id');
    $sortOrder = (getString('order') !== null && strtolower(getString('order')) === 'desc') ? 'DESC' : 'ASC';
    $allowedSortFields = ['id', 'priority'];
    if (!in_array($sortField, $allowedSortFields)) { $sortField = 'id'; }
    $orderByClause = "ORDER BY $sortField $sortOrder";

    $ceAllRows = queryAll($conn, "SELECT * FROM hh_index $whereClause $orderByClause", $ceBindTypes, $ceBindParams);
    $rows = [];

    // Transform the data into the extended format
    foreach ($ceAllRows as $row) {
      $baseRecord = [
        'keywords' => $row['keywords'],
        'labels' => $row['labels'],
        'priority' => $row['priority'],
        'status' => $row['status'],
        'url' => $row['url'] . '_1',
        'plain_text' => $row['plain_text'],

      ];
      
      // First entry with ngt_text
      if (!empty($row['ngt_text'])) {
        $rows[] = array_merge($baseRecord, [
          'id' => $row['id'],
          'plain_text' => $row['ngt_text'],
            'url' => $row['url'] . '_1'

        ]);
      }
      
      // Second entry with ngt_text2
      if (!empty($row['ngt_text2'])) {
        $rows[] = array_merge($baseRecord, [
          'id' => $row['id'] . '_2',
          'plain_text' => $row['ngt_text2'],
          'url' => $row['url'] . '_2'
        ]);
      }
      
      // Third entry with ngt_text3
      if (!empty($row['ngt_text3'])) {
        $rows[] = array_merge($baseRecord, [
          'id' => $row['id'] . '_3',
          'plain_text' => $row['ngt_text3'],
          'url' => $row['url'] . '_3'
        ]);
      }
      
      // Fourth entry with ngt_text4
      if (!empty($row['ngt_text4'])) {
        $rows[] = array_merge($baseRecord, [
          'id' => $row['id'] . '_4',
          'plain_text' => $row['ngt_text4'],
          'url' => $row['url'] . '_4'
        ]);
      }
      
      // If no NGT texts exist, include the original record with empty NGT fields
      if (empty($row['ngt_text']) && empty($row['ngt_text2']) && 
          empty($row['ngt_text3']) && empty($row['ngt_text4'])) {
        $rows[] = array_merge($baseRecord, [
          'id' => $row['id']
        ]);
      }
    }
    
    // Get total count of original records
    $ceTotalRow = queryOne($conn, "SELECT COUNT(*) as total FROM hh_index $whereClause", $ceBindTypes, $ceBindParams);
    $total = $ceTotalRow['total'];

    jsonResponse([
      'data'    => $rows,
      'total'   => $total,
      'page'    => $page,
      'perPage' => $perPage
    ]);
    break;

  case 'words':
    $wWhereClause = '';
    $wBindTypes = '';
    $wBindParams = [];
    if (!empty($search)) {
      $wWhereClause = "WHERE w.word LIKE ?";
      $wBindTypes .= 's';
      $wBindParams[] = '%' . $search . '%';
    }
    
    // Update order clause to order by lemma when requested
    $orderByClause = 'ORDER BY cnt DESC';
    switch ($order) {
      case 'cnt_asc':
        $orderByClause = 'ORDER BY cnt ASC';
        break;
      case 'word_asc':
        $orderByClause = 'ORDER BY w.lemma ASC';
        break;
      case 'word_desc':
        $orderByClause = 'ORDER BY w.lemma DESC';
        break;
    }
    
    $query = "SELECT w.lemma, MAX(l.video) AS video, MAX(l.video_id) AS video_id, MAX(l.origin) AS origin, COUNT(*) AS cnt
    FROM hh_words w JOIN hh_lemma l ON w.lemma = l.lemma $wWhereClause GROUP BY w.lemma $orderByClause LIMIT ?, ?";
    $wBindTypes .= 'ii';
    $wBindParams[] = $offset;
    $wBindParams[] = $perPage;
    $rows = queryAll($conn, $query, $wBindTypes, $wBindParams);

    $wTotalTypes = substr($wBindTypes, 0, -2);
    $wTotalParams = array_slice($wBindParams, 0, -2);
    $totalRow = queryOne($conn, "SELECT COUNT(DISTINCT w.lemma) as total FROM hh_words w JOIN hh_lemma l ON w.lemma = l.lemma $wWhereClause", $wTotalTypes, $wTotalParams);
    $total = $totalRow['total'];

    $maxRow = queryOne($conn, "SELECT MAX(cnt) as maxCount FROM (SELECT COUNT(*) as cnt FROM hh_words w JOIN hh_lemma l ON w.lemma = l.lemma $wWhereClause GROUP BY w.lemma) as t", $wTotalTypes, $wTotalParams);
    $maxCount = $maxRow['maxCount'] ? $maxRow['maxCount'] : 1;

    jsonResponse([
      'data'     => $rows,
      'total'    => $total,
      'maxCount' => $maxCount,
      'page'     => $page,
      'perPage'  => $perPage
    ]);
    break;

  case 'sentences':
    $sWhereClause = '';
    $sBindTypes = '';
    $sBindParams = [];
    if (!empty($search)) {
      $sWhereClause = "WHERE sentence LIKE ?";
      $sBindTypes .= 's';
      $sBindParams[] = '%' . $search . '%';
    }
    
    $orderByClause = 'ORDER BY cnt DESC';
    switch ($order) {
      case 'cnt_asc':
        $orderByClause = 'ORDER BY cnt ASC';
        break;
      case 'sentence_asc':
        $orderByClause = 'ORDER BY sentence ASC';
        break;
      case 'sentence_desc':
        $orderByClause = 'ORDER BY sentence DESC';
        break;
    }
    
    $sBindTypes .= 'ii';
    $sBindParams[] = $offset;
    $sBindParams[] = $perPage;
    $rows = queryAll($conn, "SELECT sentence, COUNT(*) as cnt FROM hh_sentences $sWhereClause GROUP BY sentence $orderByClause LIMIT ?, ?", $sBindTypes, $sBindParams);
    $totalRow = queryOne($conn, "SELECT COUNT(DISTINCT sentence) as total FROM hh_sentences");
    $total = $totalRow['total'];
    $maxRow = queryOne($conn, "SELECT MAX(cnt) as maxCount FROM (SELECT COUNT(*) as cnt FROM hh_sentences GROUP BY sentence) as t");
    $maxCount = $maxRow['maxCount'] ? $maxRow['maxCount'] : 1;
    jsonResponse([
      'data'     => $rows,
      'total'    => $total,
      'maxCount' => $maxCount,
      'page'     => $page,
      'perPage'  => $perPage
    ]);
    break;

  case 'keyword_connections':
    // Get keyword connections based on co-occurrences
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 30; // Limit to top keywords
    $minConnections = isset($_GET['min_connections']) ? intval($_GET['min_connections']) : 1; // Minimum number of connections
    
    // First, get all keywords and their occurrences
    $result = $conn->query("SELECT keywords FROM hh_index WHERE keywords IS NOT NULL AND keywords != '[]'");
    
    $keywordCounts = [];
    $connections = [];
    
    while ($row = $result->fetch_assoc()) {
      $keywordsArray = json_decode($row['keywords'], true);
      
      // Make sure it's an array before proceeding
      if (is_array($keywordsArray)) {
        // Count individual keywords
        foreach ($keywordsArray as $keyword) {
          if (is_string($keyword) && trim($keyword) !== '') {
            $keyword = trim($keyword);
            if (isset($keywordCounts[$keyword])) {
              $keywordCounts[$keyword]++;
            } else {
              $keywordCounts[$keyword] = 1;
            }
          }
        }
        
        // Track connections between pairs of keywords
        $uniqueKeywords = array_unique(array_filter($keywordsArray, function($k) {
          return is_string($k) && trim($k) !== '';
        }));
        
        // Normalize keywords
        $uniqueKeywords = array_map('trim', $uniqueKeywords);
        
        // Generate all possible pairs and count co-occurrences
        for ($i = 0; $i < count($uniqueKeywords); $i++) {
          for ($j = $i + 1; $j < count($uniqueKeywords); $j++) {
            $keyA = $uniqueKeywords[$i];
            $keyB = $uniqueKeywords[$j];
            
            // Create a connection identifier (alphabetically ordered pair)
            $connectionId = $keyA < $keyB ? "$keyA-$keyB" : "$keyB-$keyA";
            
            if (isset($connections[$connectionId])) {
              $connections[$connectionId]['weight']++;
            } else {
              $connections[$connectionId] = [
                'source' => $keyA,
                'target' => $keyB,
                'weight' => 1
              ];
            }
          }
        }
      }
    }
    
    // Sort keywords by frequency
    arsort($keywordCounts);
    $topKeywords = array_slice($keywordCounts, 0, $limit, true);
    
    // Filter connections to only include top keywords and meet minimum connection threshold
    $topKeywordSet = array_keys($topKeywords);
    $filteredConnections = array_filter($connections, function($conn) use ($topKeywordSet, $minConnections) {
      return in_array($conn['source'], $topKeywordSet) && 
             in_array($conn['target'], $topKeywordSet) &&
             $conn['weight'] >= $minConnections;
    });
    
    // Format data for graph visualization
    $nodes = [];
    foreach ($topKeywords as $keyword => $count) {
      $nodes[] = [
        'id' => $keyword,
        'count' => $count
      ];
    }
    
    $links = array_values($filteredConnections);
    
    echo json_encode([
      'nodes' => $nodes,
      'links' => $links
    ], JSON_UNESCAPED_UNICODE);
    break;

  case 'get_content_ngt_text':
    $id = getInt('id', 0);
    if ($id <= 0) {
      jsonResponse(['error' => 'Valid ID is required']);
      break;
    }

    $row = queryOne($conn, "SELECT ngt_text, ngt_text2, ngt_text3, ngt_text4, plain_text, zelfopname, status FROM hh_index WHERE id = ?", "i", [$id]);
    if ($row) {
      $ngtText = !empty($row['ngt_text']) ? $row['ngt_text'] : $row['plain_text'];
      
      // Process recorded videos if they exist
      $recordedVideos = [];
      if (!empty($row['zelfopname'])) {
        $zelfopname = json_decode($row['zelfopname'], true);
        if (is_array($zelfopname)) {
          $recordedVideos = $zelfopname;
        }
      }
      
      echo json_encode([
        'ngt_text' => $ngtText,
        'ngt_text2' => $row['ngt_text2'],
        'ngt_text3' => $row['ngt_text3'],
        'ngt_text4' => $row['ngt_text4'],
        'plain_text' => $row['plain_text'],
        'recorded_videos' => $recordedVideos,
        'status' => $row['status'] // Include status in the response
      ], JSON_UNESCAPED_UNICODE);
    } else {
      echo json_encode(['error' => 'Record not found'], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'get_content_ngt_text_extended':
    $id_param = isset($_GET['id']) ? $_GET['id'] : '';
    
    if (empty($id_param)) {
      echo json_encode(['error' => 'Valid ID is required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    // Parse ID to check if it contains a suffix (e.g., 8848_2)
    $id_parts = explode('_', $id_param);
    $base_id = intval($id_parts[0]);
    $text_version = isset($id_parts[1]) ? intval($id_parts[1]) : 1; // Default to 1 if no suffix
    
    if ($base_id <= 0) {
      echo json_encode(['error' => 'Valid base ID is required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $row = queryOne($conn, "SELECT ngt_text, ngt_text2, ngt_text3, ngt_text4, plain_text, zelfopname, status FROM hh_index WHERE id = ?", "i", [$base_id]);
    if ($row) {
      
      // Determine which NGT text to return based on version
      $target_text = '';
      $source_field = '';
      
      switch ($text_version) {
        case 2:
          $target_text = $row['ngt_text2'];
          $source_field = 'ngt_text2';
          break;
        case 3:
          $target_text = $row['ngt_text3'];
          $source_field = 'ngt_text3';
          break;
        case 4:
          $target_text = $row['ngt_text4'];
          $source_field = 'ngt_text4';
          break;
        default:
          $target_text = $row['ngt_text'];
          $source_field = 'ngt_text';
          break;
      }
      
      // Fall back to plain text if the specified NGT text is empty
      if (empty($target_text)) {
        $target_text = $row['plain_text'];
      }
      
      // Process recorded videos if they exist
      $recordedVideos = [];
      if (!empty($row['zelfopname'])) {
        $zelfopname = json_decode($row['zelfopname'], true);
        if (is_array($zelfopname)) {
          $recordedVideos = $zelfopname;
        }
      }
      
      echo json_encode([
        'ngt_text' => $target_text,     // Return as ngt_text regardless of source
        'plain_text' => $row['plain_text'],
        'recorded_videos' => $recordedVideos,
        'status' => $row['status'],
        'source_field' => $source_field, // Information about which field was used
        'base_id' => $base_id,
        'text_version' => $text_version
      ], JSON_UNESCAPED_UNICODE);
    } else {
      echo json_encode(['error' => 'Record not found'], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'update_content_ngt_text':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $id = isset($postData['id']) ? intval($postData['id']) : 0;
    $ngtText = $postData['ngt_text'] ?? '';
    $ngtText2 = $postData['ngt_text2'] ?? '';
    $ngtText3 = $postData['ngt_text3'] ?? '';
    $ngtText4 = $postData['ngt_text4'] ?? '';

    if ($id <= 0) {
      jsonResponse(['error' => 'Valid ID is required']);
      break;
    }

    execute($conn, "UPDATE hh_index SET ngt_text = ?, ngt_text2 = ?, ngt_text3 = ?, ngt_text4 = ? WHERE id = ?", "ssssi", [$ngtText, $ngtText2, $ngtText3, $ngtText4, $id]);
    jsonResponse(['success' => true]);
    break;

  case 'update_content_status':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $id = isset($postData['id']) ? intval($postData['id']) : 0;
    
    // Handle 'null' as a string from JSON
    if (isset($postData['status']) && $postData['status'] === 'null') {
      $statusSql = "NULL"; // This will be inserted directly in the SQL query
    } else {
      $status = isset($postData['status']) ? intval($postData['status']) : null;
      $statusSql = $status === null ? "NULL" : $status;
    }
    
    if ($id <= 0) {
      echo json_encode(['error' => 'Valid ID is required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    execute($conn, "UPDATE hh_index SET status = " . $statusSql . " WHERE id = ?", "i", [$id]);
    $row = queryOne($conn, "SELECT status FROM hh_index WHERE id = ?", "i", [$id]);
    if ($row) {
      
      jsonResponse(['success' => true, 'status' => $row['status']]);
    } else {
      jsonResponse(['error' => 'Update failed']);
    }
    break;

  case 'update_content_naam':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      jsonResponse(['error' => 'Method not allowed']);
      break;
    }

    $postData = json_decode(file_get_contents('php://input'), true);
    $id = isset($postData['id']) ? intval($postData['id']) : 0;
    $naam = $postData['naam'] ?? '';

    if ($id <= 0) {
      jsonResponse(['error' => 'Valid ID is required']);
      break;
    }

    execute($conn, "UPDATE hh_index SET naam = ? WHERE id = ?", "si", [$naam, $id]);
    $row = queryOne($conn, "SELECT naam FROM hh_index WHERE id = ?", "i", [$id]);
    jsonResponse(['success' => true, 'naam' => $row['naam'] ?? '']);
    break;

  case 'upload_video':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    // Handle content_id case (for content pages)
    if (isset($_POST['content_id']) && !empty($_POST['content_id'])) {
      $contentId = intval($_POST['content_id']);
      
      if ($contentId <= 0) {
        echo json_encode(['error' => 'Invalid content ID'], JSON_UNESCAPED_UNICODE);
        break;
      }
      
      if (!isset($_FILES['video']) || $_FILES['video']['error'] != UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Video upload failed'], JSON_UNESCAPED_UNICODE);
        break;
      }
      
      // Create uploads directory if it doesn't exist
      $uploadDir = '../uploads/';
      if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
      }
      
      // Generate unique filename with MD5 hash
      $filename = md5(uniqid() . time()) . '.webm';
      $uploadFile = $uploadDir . $filename;
      
      if (move_uploaded_file($_FILES['video']['tmp_name'], $uploadFile)) {
        // Get existing zelfopname data
        $row = queryOne($conn, "SELECT zelfopname FROM hh_index WHERE id = ?", "i", [$contentId]);
        if ($row) {
          
          // Update the zelfopname field (add to existing array or create new array)
          $zelfopname = !empty($row['zelfopname']) ? json_decode($row['zelfopname'], true) : [];
          if (!is_array($zelfopname)) {
            $zelfopname = [];
          }
          $zelfopname[] = $filename;
          $zelfopnameJson = json_encode($zelfopname);
          execute($conn, "UPDATE hh_index SET zelfopname = ? WHERE id = ?", "si", [$zelfopnameJson, $contentId]);
          if (true) {
            echo json_encode([
              'success' => true, 
              'filename' => $filename,
              'url' => '/uploads/' . $filename
            ], JSON_UNESCAPED_UNICODE);
          } else {
            echo json_encode(['error' => 'Update failed: ' . $conn->error], JSON_UNESCAPED_UNICODE);
          }
        } else {
          echo json_encode(['error' => 'Record not found'], JSON_UNESCAPED_UNICODE);
        }
      } else {
        echo json_encode(['error' => 'Failed to save the video'], JSON_UNESCAPED_UNICODE);
      }
      break;
    }
    
    // Handle lemma case (existing functionality)
    $lemma = isset($_POST['lemma']) ? $conn->real_escape_string($_POST['lemma']) : '';
    
    // ...existing code for lemma case...
    if (empty($lemma)) {
      echo json_encode(['error' => 'Lemma or content ID is required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    // ...rest of existing upload_video case...
    break;
    
  case 'delete_recorded_video':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $id = isset($postData['id']) ? intval($postData['id']) : 0;
    $filename = isset($postData['filename']) ? $conn->real_escape_string($postData['filename']) : '';
    
    if ($id <= 0 || empty($filename)) {
      echo json_encode(['error' => 'Valid ID and filename are required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    // Get current zelfopname array
    $delRow = queryOne($conn, "SELECT zelfopname FROM hh_index WHERE id = ?", "i", [$id]);
    if (!$delRow) {
      echo json_encode(['error' => 'Record not found'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $zelfopname = !empty($delRow['zelfopname']) ? json_decode($delRow['zelfopname'], true) : [];
    if (!is_array($zelfopname)) {
      $zelfopname = [];
    }

    $zelfopname = array_filter($zelfopname, function($item) use ($filename) {
      return $item !== $filename;
    });

    $zelfopnameJson = json_encode(array_values($zelfopname));
    execute($conn, "UPDATE hh_index SET zelfopname = ? WHERE id = ?", "si", [$zelfopnameJson, $id]);
    if (true) {
      echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
      echo json_encode(['error' => 'Update failed: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'get_glossary':
    $contentId = isset($_GET['content_id']) ? intval($_GET['content_id']) : 0;
    
    if ($contentId <= 0) {
      echo json_encode(['error' => 'Valid content ID is required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $entries = queryAll($conn, "SELECT id, glos, text FROM hh_index_glos WHERE hh_index_id = ? ORDER BY glos ASC", "i", [$contentId]);
    jsonResponse(['entries' => $entries, 'content_id' => $contentId]);
    break;
    
  case 'add_glossary_entry':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $contentId = isset($postData['content_id']) ? intval($postData['content_id']) : 0;
    // Convert the glossary term to uppercase
    $glos = isset($postData['glos']) ? strtoupper($conn->real_escape_string($postData['glos'])) : '';
    $text = isset($postData['text']) ? $conn->real_escape_string($postData['text']) : '';
    
    if ($contentId <= 0 || empty($glos) || empty($text)) {
      echo json_encode(['error' => 'Content ID, glossary term, and definition are required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    // Check if the content ID exists in hh_index
    $checkQuery = "SELECT id FROM hh_index WHERE id = $contentId";
    $checkResult = $conn->query($checkQuery);
    
    if (!$checkResult || $checkResult->num_rows === 0) {
      echo json_encode(['error' => 'Content ID does not exist'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    // Insert the new glossary entry
    $insertStmt = execute($conn, "INSERT INTO hh_index_glos (hh_index_id, glos, text) VALUES (?, ?, ?)", "iss", [$contentId, $glos, $text]);
    if ($insertStmt->affected_rows > 0) {
      $newId = $conn->insert_id;
      echo json_encode([
        'success' => true,
        'id' => $newId,
        'glos' => $glos,
        'text' => $text
      ], JSON_UNESCAPED_UNICODE);
    } else {
      echo json_encode(['error' => 'Failed to add glossary entry: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    }
    break;
    
  case 'update_glossary_entry':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $id = isset($postData['id']) ? intval($postData['id']) : 0;
    $glos = isset($postData['glos']) ? $conn->real_escape_string($postData['glos']) : '';
    $text = isset($postData['text']) ? $conn->real_escape_string($postData['text']) : '';
    
    if ($id <= 0 || empty($glos) || empty($text)) {
      echo json_encode(['error' => 'Entry ID, glossary term, and definition are required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    // Update the glossary entry
    $updateStmt = execute($conn, "UPDATE hh_index_glos SET glos = ?, text = ? WHERE id = ?", "ssi", [$glos, $text, $id]);
    if ($updateStmt) {
      echo json_encode([
        'success' => true,
        'id' => $id,
        'glos' => $glos,
        'text' => $text
      ], JSON_UNESCAPED_UNICODE);
    } else {
      echo json_encode(['error' => 'Failed to update glossary entry: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    }
    break;
    
  case 'delete_glossary_entry':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $id = isset($postData['id']) ? intval($postData['id']) : 0;
    
    if ($id <= 0) {
      echo json_encode(['error' => 'Valid entry ID is required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    // Delete the glossary entry
    $deleteStmt = execute($conn, "DELETE FROM hh_index_glos WHERE id = ?", "i", [$id]);
    if ($deleteStmt) {
      echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
      echo json_encode(['error' => 'Failed to delete glossary entry: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'get_lemmas':
    if (!isset($_GET['text']) || empty($_GET['text'])) {
      echo json_encode(['error' => 'Text parameter is required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $text = $_GET['text'];
    // Split the text by spaces and other separators
    $words = preg_split('/[\s,\.;:!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    
    // Remove duplicates to avoid processing the same word multiple times
    $words = array_unique($words);
    
    $lemmasWithSign = [];
    $lemmasWithoutSign = [];
    
    foreach ($words as $word) {
      $cleanWord = trim(strtolower($word));
      $cleanWord = preg_replace('/[^a-z0-9]/u', '', $cleanWord);
      if (empty($cleanWord) || mb_strlen($cleanWord) <= 3) continue;

      $lemmaRow = queryOne($conn, "SELECT lemma FROM hh_words WHERE word = ? LIMIT 1", "s", [$cleanWord]);

      if ($lemmaRow) {
        $lemma = $lemmaRow['lemma'];

        $videoRow = queryOne($conn, "SELECT lemma, video, origin FROM hh_lemma WHERE lemma = ? AND video IS NOT NULL AND video != '' LIMIT 1", "s", [$lemma]);

        if ($videoRow) {
          $lemmasWithSign[] = [
            'lemma' => $videoRow['lemma'],
            'video' => $videoRow['video'],
            'origin' => $videoRow['origin']
          ];
        } else {
          $formDataResult = queryOne($conn, "SELECT glos FROM form_data WHERE glos = ? AND extern = 1 LIMIT 1", "s", [$lemma]);
          
          // Only add to lemmasWithoutSign if not found in form_data
          if (!$formDataResult) {
            // Check if lemma is already in the list before adding
            $lemmaExists = false;
            foreach ($lemmasWithoutSign as $existingItem) {
              if ($existingItem['lemma'] === $lemma) {
                $lemmaExists = true;
                break;
              }
            }
            
            if (!$lemmaExists) {
              $lemmasWithoutSign[] = [
                'lemma' => $lemma
              ];
            }
          }
        }
      } else {
        $formDataResult2 = queryOne($conn, "SELECT glos FROM form_data WHERE glos = ? AND extern = 1 LIMIT 1", "s", [$word]);

        if (!$formDataResult2) {
          // Word not found in hh_words, add as-is to lemmas without sign
          // Check if word is already in the list before adding
          $wordExists = false;
          foreach ($lemmasWithoutSign as $existingItem) {
            if ($existingItem['lemma'] === $word) {
              $wordExists = true;
              break;
            }
          }
          
          if (!$wordExists) {
            $lemmasWithoutSign[] = [
              'lemma' => $word
            ];
          }
        }
      }
    }
    
    // Return the results
    echo json_encode([
      'lemmas_with_sign' => $lemmasWithSign,
      'lemmas_without_sign' => $lemmasWithoutSign,
      'text' => $text
    ], JSON_UNESCAPED_UNICODE);
    break;

  // Add new endpoint to get themas
  case 'get_themas':
    // Forward the request to the external API
    $externalUrl = "https://signcollect.nl/uniqueThema.php?extern=1";
    
    // Use cURL to make the request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $externalUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
      echo json_encode(['error' => 'Failed to fetch themas: ' . curl_error($ch)], JSON_UNESCAPED_UNICODE);
      curl_close($ch);
      break;
    }
    
    curl_close($ch);
    
    // Pass through the response
    echo $response;
    break;

  case 'get_all_glossary':
    $gagWhereClause = '';
    $gagBindTypes = '';
    $gagBindParams = [];
    if (!empty($search)) {
      $gagWhereClause = "WHERE hig.glos LIKE ? OR hig.text LIKE ?";
      $gagBindTypes .= 'ss';
      $gagBindParams[] = '%' . $search . '%';
      $gagBindParams[] = '%' . $search . '%';
    }

    $allRows = queryAll($conn, "SELECT hig.id as glos_id, hig.glos, hig.text, hi.id as content_id, hi.url
              FROM hh_index_glos hig
              LEFT JOIN hh_index hi ON hig.hh_index_id = hi.id
              $gagWhereClause
              ORDER BY hig.glos ASC, hig.text ASC", $gagBindTypes, $gagBindParams);
    
    // Group results by glos and text in PHP
    $groupedData = [];
    foreach ($allRows as $row) {
      $key = $row['glos'] . '||' . $row['text']; // Unique key for grouping
      
      if (!isset($groupedData[$key])) {
        $groupedData[$key] = [
          'glos' => $row['glos'],
          'text' => $row['text'],
          'original_glos' => $row['glos'], // Store original for updates
          'original_text' => $row['text'], // Store original for updates
          'sources' => [] // Initialize sources array
        ];
      }
      
      // Add source if content_id exists, include glos_id
      if ($row['content_id']) {
        $groupedData[$key]['sources'][] = [
          'glos_id' => $row['glos_id'], // Add glos_id here
          'content_id' => $row['content_id'],
          'url' => $row['url']
        ];
      } else {
         // Handle cases where a glos entry might not have a source link (optional)
         // If you want to track these, add them with null content_id/url but include glos_id
         $groupedData[$key]['sources'][] = [
             'glos_id' => $row['glos_id'],
             'content_id' => null,
             'url' => null
         ];
      }
    }
    
    // Convert grouped data to indexed array
    $finalGroupedData = array_values($groupedData);
    
    // Calculate total based on grouped data
    $total = count($finalGroupedData);
    
    // Apply pagination to the grouped data
    $paginatedData = array_slice($finalGroupedData, $offset, $perPage);
    
    echo json_encode([
      'data' => $paginatedData, // Send paginated grouped data
      'total' => $total,       // Send total count of unique groups
      'page' => $page,
      'perPage' => $perPage
    ], JSON_UNESCAPED_UNICODE);
    break;

  case 'update_glossary_group':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $originalGlos = $postData['original_glos'] ?? '';
    $originalText = $postData['original_text'] ?? '';
    $newGlos = $postData['new_glos'] ?? '';
    $newText = $postData['new_text'] ?? '';

    if (empty($originalGlos) || empty($originalText) || empty($newGlos) || empty($newText)) {
      jsonResponse(['error' => 'Original and new glossary term/definition are required']);
      break;
    }

    $ugStmt = execute($conn, "UPDATE hh_index_glos SET glos = ?, text = ? WHERE glos = ? AND text = ?", "ssss", [$newGlos, $newText, $originalGlos, $originalText]);
    if ($ugStmt) {
      jsonResponse(['success' => true, 'affected_rows' => $ugStmt->affected_rows]);
    } else {
      echo json_encode(['error' => 'Failed to update glossary group: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'delete_glossary_source':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $glosId = isset($postData['glos_id']) ? intval($postData['glos_id']) : 0;
    
    if ($glosId <= 0) {
      echo json_encode(['error' => 'Valid glossary entry ID (glos_id) is required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $delSourceStmt = execute($conn, "DELETE FROM hh_index_glos WHERE id = ?", "i", [$glosId]);
    if ($delSourceStmt) {
       if ($delSourceStmt->affected_rows > 0) {
           echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
       } else {
           echo json_encode(['error' => 'Glossary source not found or already deleted'], JSON_UNESCAPED_UNICODE);
       }
    } else {
      echo json_encode(['error' => 'Failed to delete glossary source: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'get_glossary_definition':
    $glos = getString('glos', '');
    if (empty($glos)) {
      jsonResponse(['error' => 'Glossary term is required']);
      break;
    }

    $defRow = queryOne($conn, "SELECT text, COUNT(*) as cnt FROM hh_index_glos WHERE glos = ? GROUP BY text ORDER BY cnt DESC LIMIT 1", "s", [$glos]);
    jsonResponse(['definition' => $defRow ? $defRow['text'] : null]);
    break;

  case 'bulk_add_glossary_entries':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $contentId = isset($postData['content_id']) ? intval($postData['content_id']) : 0;
    $bulkText = isset($postData['bulk_text']) ? $postData['bulk_text'] : '';
    $thema = isset($postData['thema']) ? $conn->real_escape_string($postData['thema']) : '';
    
    if ($contentId <= 0 || empty($bulkText)) {
      echo json_encode(['error' => 'Content ID and bulk text are required'], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $lines = preg_split('/\r\n|\r|\n/', $bulkText, -1, PREG_SPLIT_NO_EMPTY);
    $results = [
      'processed' => 0,
      'linked_existing' => 0,
      'added_new' => 0,
      'signbank_added' => [],
      'signbank_errors' => [],
      'errors' => []
    ];
    
    // Prepare statements for efficiency
    $findDefStmt = $conn->prepare("SELECT text, COUNT(*) as cnt FROM hh_index_glos WHERE glos = ? GROUP BY text ORDER BY cnt DESC LIMIT 1");
    $insertStmt = $conn->prepare("INSERT INTO hh_index_glos (hh_index_id, glos, text) VALUES (?, ?, ?)");
    
    foreach ($lines as $line) {
      $results['processed']++;
      $parts = explode(',', $line, 2); // Split by the first comma only
      
      // Convert the term to uppercase
      $term = isset($parts[0]) ? trim(strtoupper($conn->real_escape_string($parts[0]))) : '';
      $definition = isset($parts[1]) ? trim($conn->real_escape_string($parts[1])) : '';
      
      if (empty($term)) {
        $results['errors'][] = "Skipped line (empty term): " . $line;
        continue;
      }
      
      // Check if term exists and get most common definition
      $findDefStmt->bind_param("s", $term);
      $findDefStmt->execute();
      $defResult = $findDefStmt->get_result();
      
      $existingDefinition = null;
      if ($defResult && $defResult->num_rows > 0) {
        $existingDefinition = $defResult->fetch_assoc()['text'];
      }
      
      $finalDefinition = '';
      $isNewTerm = false;
      
      if ($existingDefinition !== null) {
        // Term exists, use its most common definition
        $finalDefinition = $existingDefinition;
        $results['linked_existing']++;
      } else {
        // Term is new, use the provided definition
        if (empty($definition)) {
           $results['errors'][] = "Skipped new term (no definition provided): " . $term;
           continue;
        }
        $finalDefinition = $definition;
        $results['added_new']++;
        $isNewTerm = true;
      }
      
      // Insert into hh_index_glos
      $insertStmt->bind_param("iss", $contentId, $term, $finalDefinition);
      if (!$insertStmt->execute()) {
        $results['errors'][] = "Failed to add/link '$term': " . $insertStmt->error;
        // If insert failed, don't attempt Signbank add
        continue; 
      }
      
      // If it was a new term, try adding to Signbank
      if ($isNewTerm) {
        if (empty($thema)) {
           $results['signbank_errors'][] = "Skipped Signbank add for '$term' (no thema provided).";
           continue;
        }

        // Use cURL to call the external batch_add.php script
        $signbankUrl = 'https://signcollect.nl/batch_add.php';
        $postFields = [
            'wordList' => $term, // Send the raw term, not escaped
            'thema' => $thema,   // Send the raw thema
            'userId' => '6',     // Assuming default user ID
            'checkDuplicatesWithSuffix' => 'true',  // Or 'false' based on desired behavior
            //      formData.append('labels', JSON.stringify(['TYDbase', 'HealthHolland']));
            'labels' => json_encode(['TYDbase', 'HealthHolland']), // Send as JSON
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $signbankUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Use cautiously, consider proper verification
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Add a timeout

        $signbankResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $results['signbank_errors'][] = "Signbank API call failed for '$term': " . $curlError;
        } else {
            $signbankResult = json_decode($signbankResponse, true);
            if ($signbankResult) {
                if (isset($signbankResult['success']) && !empty($signbankResult['success'])) {
                    $results['signbank_added'] = array_merge($results['signbank_added'], $signbankResult['success']);
                }
                if (isset($signbankResult['errors']) && !empty($signbankResult['errors'])) {
                    $results['signbank_errors'][] = "Signbank error for '$term': " . implode(', ', $signbankResult['errors']);
                }
                 if (isset($signbankResult['error']) && !empty($signbankResult['error'])) { // Handle single error string
                    $results['signbank_errors'][] = "Signbank error for '$term': " . $signbankResult['error'];
                }
                // Handle other potential responses like duplicates etc. if needed
            } else {
                $results['signbank_errors'][] = "Invalid Signbank API response for '$term'.";
            }
        }
      }
    }
    
    $findDefStmt->close();
    $insertStmt->close();
    
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    break;

  case 'check_term_video':
    $glos = getString('glos', '');
    if (empty($glos)) {
      jsonResponse(['error' => 'Term is required']);
      break;
    }

    $formRow = queryOne($conn, "SELECT id FROM form_data WHERE glos = ? LIMIT 1", "s", [$glos]);

    if ($formRow) {
      $form_data_id = $formRow['id'];

      $mfRow = queryOne($conn, "SELECT m_file FROM matched_transcriptions WHERE m_transcription = ? AND zOg = 'extern' ORDER BY m_file DESC LIMIT 1", "i", [$form_data_id]);

      if ($mfRow) {
        $row = $mfRow;
        $m_file = $row['m_file'];
        
        // Replace .wav with .mp4 and build the URL
        $videoUrl = str_replace(".wav", ".mp4", $m_file);
        $fullVideoUrl = "https://media.signcollect.nl/" . $videoUrl;
        
        echo json_encode([
          'has_video' => true,
          'video_url' => $fullVideoUrl
        ], JSON_UNESCAPED_UNICODE);
      } else {
        $nmmRow = queryOne($conn, "SELECT id FROM nmm_data WHERE glos = ? LIMIT 1", "s", [$glos]);

        if ($nmmRow) {
          $nmm_id = $nmmRow['id'];

          $nmmMfRow = queryOne($conn, "SELECT m_file FROM matched_transcriptions WHERE m_transcription = ? AND zOg LIKE 'nmm' LIMIT 1", "i", [$nmm_id]);

          if ($nmmMfRow) {
            $row = $nmmMfRow;
            $m_file = $row['m_file'];
            
            // Replace .wav with .mp4 and build the URL
            $videoUrl = str_replace(".wav", ".mp4", $m_file);
            $fullVideoUrl = "https://media.signcollect.nl/" . $videoUrl;
            
            echo json_encode([
              'has_video' => true,
              'video_url' => $fullVideoUrl
            ], JSON_UNESCAPED_UNICODE);
          } else {
            echo json_encode(['has_video' => false], JSON_UNESCAPED_UNICODE);
          }
        } else {
          echo json_encode(['has_video' => false], JSON_UNESCAPED_UNICODE);
        }
      }
    } else {
      echo json_encode(['has_video' => false], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'ngt_comparison_stats':
    // Return pre-computed NGT text comparison statistics
    $resultsFile = __DIR__ . '/ngt_comparison_results.json';

    if (file_exists($resultsFile)) {
      $jsonContent = file_get_contents($resultsFile);
      echo $jsonContent;
    } else {
      echo json_encode([
        'error' => 'Comparison results not found. Run compare_ngt_texts.py first.',
        'hint' => 'Execute: python3 /web/hh/compare_ngt_texts.py'
      ], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'get_unique_labels':
    // Get all unique labels from the hh_index table
    $result = $conn->query("SELECT labels FROM hh_index WHERE labels IS NOT NULL AND labels != '[]'");

    $uniqueLabels = [];
    while ($row = $result->fetch_assoc()) {
      $labelsJson = $row['labels'];
      $labelsArray = json_decode($labelsJson, true);

      if (is_array($labelsArray)) {
        foreach ($labelsArray as $label) {
          if (is_string($label) && trim($label) !== '' && !in_array($label, $uniqueLabels)) {
            $uniqueLabels[] = $label;
          }
        }
      }
    }

    sort($uniqueLabels);
    echo json_encode(['labels' => $uniqueLabels], JSON_UNESCAPED_UNICODE);
    break;

  default:
    echo json_encode(['error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    break;
}

$conn->close();
?>
