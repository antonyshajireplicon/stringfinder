<?php
/*
Plugin Name: WP String Finder (AJAX Background)
Description: Upload CSV of URLs and search each page source for a string. Uses AJAX + small server-side batches to avoid long running requests.
Version: 1.1
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

define('WSF_JOB_OPTION_PREFIX', 'wsf_job_'); // option key prefix
define('WSF_RESULTS_SUBDIR', 'string-finder-results');

// --- Admin menu ---
add_action('admin_menu', function(){
    add_management_page('String Finder (AJAX)', 'String Finder (AJAX)', 'manage_options', 'wp-string-finder-ajax', 'wsf_admin_page');
});

// --- Enqueue scripts/styles ---
add_action('admin_enqueue_scripts', function($hook){
    if ($hook !== 'tools_page_wp-string-finder-ajax') return;
    wp_enqueue_script('wsf-admin-js', plugin_dir_url(__FILE__) . 'wsf-admin.js', ['jquery'], '1.0', true);
    wp_localize_script('wsf-admin-js', 'wsf_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wsf_nonce'),
    ]);
    wp_enqueue_style('wsf-admin-css', plugin_dir_url(__FILE__) . 'wsf-admin.css');
});

// --- Admin page HTML ---
function wsf_admin_page() {
    if (!current_user_can('manage_options')) return;
    $upload_dir = wp_upload_dir();
    $results_dir = trailingslashit($upload_dir['basedir']) . WSF_RESULTS_SUBDIR;
    if (!file_exists($results_dir)) wp_mkdir_p($results_dir);

    ?>
    <div class="wrap">
        <h1>🔎 WP String Finder (AJAX)</h1>
        <p>Upload a CSV (1 URL per row in first column). The scanner will run in small batches so it won't time out.</p>

        <div class="wsf-box">
            <label>Target string to search:</label>
            <input id="wsf-target" type="text" value="/ptt/case-studies/" />

            <label>Batch size (requests per AJAX call):</label>
            <input id="wsf-batch-size" type="number" min="1" max="200" value="10" />

            <label>Delay between batches (ms):</label>
            <input id="wsf-delay" type="number" min="0" max="10000" value="500" />

            <label>Upload CSV:</label>
            <input id="wsf-csv" type="file" accept=".csv" />

            <p><button id="wsf-start" class="button button-primary">Start Scan</button>
            <button id="wsf-stop" class="button" disabled>Stop</button></p>
        </div>

        <h2>Progress</h2>
        <div id="wsf-progress-wrap">
            <div id="wsf-progress" style="width:0%"></div>
        </div>
        <p id="wsf-stats">No job started.</p>

        <h2>Log</h2>
        <pre id="wsf-log" class="wsf-log"></pre>

        <div id="wsf-download" style="margin-top:16px;"></div>
    </div>

    <?php
}

// --- AJAX: upload CSV and create job ---
add_action('wp_ajax_wsf_create_job', 'wsf_create_job');
function wsf_create_job() {
    check_ajax_referer('wsf_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
    }

    if (empty($_FILES['csv']) || empty($_POST['target'])) {
        wp_send_json_error('Missing CSV file or target string', 400);
    }

    $target = sanitize_text_field(wp_unslash($_POST['target']));
    $uploaded = $_FILES['csv'];

    if ($uploaded['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('Upload error: ' . $uploaded['error'], 400);
    }

    $tmp = $uploaded['tmp_name'];
    $urls = [];

    if (($handle = fopen($tmp, 'r')) !== false) {
        while (($row = fgetcsv($handle)) !== false) {
            if (!empty($row[0])) {
                $u = trim($row[0]);
                // basic normalization
                if (!preg_match('#^https?://#i', $u)) {
                    // optionally skip or prepend scheme, here we'll skip invalid urls
                    continue;
                }
                $urls[] = esc_url_raw($u);
            }
        }
        fclose($handle);
    }

    if (empty($urls)) {
        wp_send_json_error('No valid URLs found in CSV', 400);
    }

    // job structure
    $job_id = uniqid('wsf_', true);
    $job = [
        'id' => $job_id,
        'created' => time(),
        'target' => $target,
        'urls' => $urls,
        'position' => 0, // next index to process
        'total' => count($urls),
        'results_file' => '', // to be set at completion
        'status' => 'queued', // queued | running | finished | stopped | error
        'errors' => [],
    ];

    // save job in options (transient would expire; use option for persistence)
    add_option(WSF_JOB_OPTION_PREFIX . $job_id, $job);

    wp_send_json_success([
        'job_id' => $job_id,
        'total' => $job['total'],
    ]);
}

// --- AJAX: process a small batch for job ---
add_action('wp_ajax_wsf_process_batch', 'wsf_process_batch');
function wsf_process_batch() {
    check_ajax_referer('wsf_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
    }

    $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;

    if (!$job_id) wp_send_json_error('Missing job_id', 400);

    $opt_key = WSF_JOB_OPTION_PREFIX . $job_id;
    $job = get_option($opt_key);

    if (!$job) wp_send_json_error('Job not found', 404);
    if ($job['status'] === 'finished') {
        wp_send_json_success(['finished' => true, 'position' => $job['position'], 'total' => $job['total']]);
    }
    if ($job['status'] === 'stopped') {
        wp_send_json_success(['stopped' => true]);
    }

    // mark running
    $job['status'] = 'running';
    update_option($opt_key, $job);

    $start = $job['position'];
    $end = min($job['total'], $start + $batch_size);
    $batch_urls = array_slice($job['urls'], $start, $end - $start);

    $results = [];
    foreach ($batch_urls as $url) {
        $res = wsf_fetch_and_search($url, $job['target']);
        $results[] = $res;
    }

    // append results to a temp results array inside job to avoid large memory
    if (!isset($job['partial_results'])) $job['partial_results'] = [];
    $job['partial_results'] = array_merge($job['partial_results'], $results);

    // advance position
    $job['position'] = $end;
    // if finished write CSV
    if ($job['position'] >= $job['total']) {
        // write CSV to uploads dir
        $upload_dir = wp_upload_dir();
        $results_dir = trailingslashit($upload_dir['basedir']) . WSF_RESULTS_SUBDIR;
        if (!file_exists($results_dir)) wp_mkdir_p($results_dir);

        $filename = 'results_' . $job_id . '_' . date('Ymd_His') . '.csv';
        $filepath = trailingslashit($results_dir) . $filename;
        $fh = fopen($filepath, 'w');
        fputcsv($fh, ['URL', 'Found', 'Status Code', 'Error']);

        foreach ($job['partial_results'] as $r) {
            fputcsv($fh, [$r['url'], $r['found'] ? 'Yes' : 'No', $r['status_code'], $r['error']]);
        }
        fclose($fh);

        $job['results_file'] = trailingslashit($upload_dir['baseurl']) . WSF_RESULTS_SUBDIR . '/' . $filename;
        $job['status'] = 'finished';
        // remove partial_results to save option size if desired
        unset($job['partial_results']);

        update_option($opt_key, $job);

        wp_send_json_success([
            'position' => $job['position'],
            'total' => $job['total'],
            'finished' => true,
            'results_file' => esc_url($job['results_file']),
            'batch_results' => $results,
        ]);
    }

    // not finished - save job and return batch results
    update_option($opt_key, $job);

    wp_send_json_success([
        'position' => $job['position'],
        'total' => $job['total'],
        'finished' => false,
        'batch_results' => $results,
    ]);
}

// --- AJAX: stop job ---
add_action('wp_ajax_wsf_stop_job', 'wsf_stop_job');
function wsf_stop_job() {
    check_ajax_referer('wsf_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);
    $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
    if (!$job_id) wp_send_json_error('Missing job_id', 400);
    $opt_key = WSF_JOB_OPTION_PREFIX . $job_id;
    $job = get_option($opt_key);
    if (!$job) wp_send_json_error('Job not found', 404);
    $job['status'] = 'stopped';
    update_option($opt_key, $job);
    wp_send_json_success(['stopped' => true]);
}

// --- Helper: fetch page and search string (short execution) ---
function wsf_fetch_and_search($url, $target) {
    // Use wp_remote_get with reasonable timeout
    $args = [
        'timeout' => 15,
        'redirection' => 5,
        'headers' => [
            'User-Agent' => 'WPStringFinder/1.0 (+https://example.com)',
        ],
    ];
    $result = wp_remote_get( $url, $args );
    $error = '';
    $status = 0;
    $found = false;

    if (is_wp_error($result)) {
        $error = $result->get_error_message();
    } else {
        $status = wp_remote_retrieve_response_code($result);
        $body = wp_remote_retrieve_body($result);
        if ($status === 200 && $body !== '') {
            // simple substring search; could improve with strip_tags for speed if needed
            $found = (strpos($body, $target) !== false);
        }
    }

    return [
        'url' => $url,
        'found' => $found,
        'status_code' => $status,
        'error' => $error,
    ];
}
