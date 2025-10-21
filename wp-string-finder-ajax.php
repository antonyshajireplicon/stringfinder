<?php
// string_finder.php

ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');
ob_implicit_flush(true);
ob_end_flush();

$searchString = '/ptt/case-studies/';
$results = [];
$outputFile = 'search_results_' . date('Ymd_His') . '.csv';

function fetchPage($url) {
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.5',
        'Connection: keep-alive',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['content' => $content, 'httpCode' => $httpCode, 'error' => $error];
}

function processUrls($urls, $searchString, $outputFile) {
    $fp = fopen($outputFile, 'w');
    fputcsv($fp, ['URL', 'Status Code', 'Match Found']);

    foreach ($urls as $index => $url) {
        $url = trim($url);
        if (!$url) continue;

        echo "Checking ($index): $url ... ";

        $attempts = 0;
        $result = null;
        while ($attempts < 2) { // retry once if failure
            $attempts++;
            $result = fetchPage($url);
            if ($result['httpCode'] != 0 && $result['httpCode'] != 500 && $result['content']) break;
            sleep(rand(2, 4)); // wait before retry
        }

        $matchFound = false;
        if ($result['content'] && strpos($result['content'], $searchString) !== false) {
            $matchFound = true;
        }

        fputcsv($fp, [$url, $result['httpCode'], $matchFound ? 'Yes' : 'No']);
        echo ($matchFound ? "✅ MATCH FOUND" : "❌ Not found") . " [HTTP {$result['httpCode']}]<br>";
        flush();

        sleep(rand(1, 3)); // delay between requests
    }

    fclose($fp);
    echo "<br><b>Done!</b> <a href='$outputFile' target='_blank'>Download Results CSV</a><br>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $showMatchedOnly = isset($_POST['show_matched']) ? true : false;
    $file = $_FILES['csv_file']['tmp_name'];
    $urls = array_map('str_getcsv', file($file));
    $urls = array_column($urls, 0);

    ob_start();
    echo "<pre>";
    processUrls($urls, $searchString, $outputFile);
    echo "</pre>";
    $output = ob_get_clean();

    // If "show matched only" selected, filter matched
    if ($showMatchedOnly) {
        $filtered = [];
        if (($handle = fopen($outputFile, 'r')) !== false) {
            $headers = fgetcsv($handle);
            while (($data = fgetcsv($handle)) !== false) {
                if (trim($data[2]) === 'Yes') {
                    $filtered[] = $data;
                }
            }
            fclose($handle);
        }

        $filteredFile = str_replace('.csv', '_matched.csv', $outputFile);
        $fp2 = fopen($filteredFile, 'w');
        fputcsv($fp2, ['URL', 'Status Code', 'Match Found']);
        foreach ($filtered as $row) {
            fputcsv($fp2, $row);
        }
        fclose($fp2);
        $output .= "<br><b>Filtered file:</b> <a href='$filteredFile' target='_blank'>Download Matched URLs CSV</a>";
    }

    echo $output;
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>String Finder in URLs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5 p-4 bg-white rounded shadow-sm">
    <h2 class="mb-3">🔍 Find String in Page Source</h2>
    <form method="post" enctype="multipart/form-data">
        <div class="mb-3">
            <label for="csv_file" class="form-label">Upload CSV (1st column = URL list)</label>
            <input type="file" name="csv_file" id="csv_file" class="form-control" required>
        </div>
        <div class="mb-3">
            <input type="checkbox" name="show_matched" id="show_matched">
            <label for="show_matched">Show only matched URLs in output</label>
        </div>
        <button type="submit" class="btn btn-primary">Start Search</button>
    </form>
</div>
</body>
</html>
