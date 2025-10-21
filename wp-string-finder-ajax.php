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
            sleep(rand(2, 4)
