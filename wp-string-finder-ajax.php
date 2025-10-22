<?php
/*
Plugin Name: WP String Finder (AJAX + Multi-cURL)
Description: Upload CSV of URLs and search each page source for a string. Uses AJAX + multi-cURL for fast parallel scanning with retries and matched-only filter.
Version: 1.2
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

define('WSF_JOB_OPTION_PREFIX','wsf_job_');
define('WSF_RESULTS_SUBDIR','string-finder-results');

// ---------------- Admin Menu ----------------
add_action('admin_menu', function(){
    add_management_page('String Finder', 'String Finder', 'manage_options', 'wp-string-finder-ajax', 'wsf_admin_page');
});

// ---------------- Enqueue Scripts ----------------
add_action('admin_enqueue_scripts', function($hook){
    if($hook!=='tools_page_wp-string-finder-ajax') return;
    wp_enqueue_script('wsf-admin-js', plugin_dir_url(__FILE__).'wsf-admin.js',['jquery'],'1.0',true);
    wp_localize_script('wsf-admin-js','wsf_ajax',[
        'ajax_url'=>admin_url('admin-ajax.php'),
        'nonce'=>wp_create_nonce('wsf_nonce'),
    ]);
    wp_enqueue_style('wsf-admin-css',plugin_dir_url(__FILE__).'wsf-admin.css');
});

// ---------------- Admin Page ----------------
function wsf_admin_page(){
    if(!current_user_can('manage_options')) return;
    $upload_dir=wp_upload_dir();
    $results_dir=trailingslashit($upload_dir['basedir']).WSF_RESULTS_SUBDIR;
    if(!file_exists($results_dir)) wp_mkdir_p($results_dir);
    ?>
    <div class="wrap">
    <h1>🔎 WP String Finder (AJAX + Multi-cURL)</h1>

    <div class="wsf-box">
        <label>Target String:</label>
        <input type="text" id="wsf-target" value="/ptt/case-studies/" />

        <label>Batch Size (parallel requests):</label>
        <input type="number" id="wsf-batch-size" value="10" min="1" max="100">

        <label>Delay between batches (ms):</label>
        <input type="number" id="wsf-delay" value="500" min="0" max="5000">

        <label>Upload CSV:</label>
        <input type="file" id="wsf-csv" accept=".csv">

        <div class="form-check mt-2">
            <input type="checkbox" class="form-check-input" id="wsf-show-matched">
            <label class="form-check-label" for="wsf-show-matched">Show only matched URLs</label>
        </div>

        <p class="mt-3">
            <button id="wsf-start" class="button button-primary">Start Scan</button>
            <button id="wsf-stop" class="button" disabled>Stop</button>
        </p>
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

// ---------------- AJAX: Create Job ----------------
add_action('wp_ajax_wsf_create_job','wsf_create_job');
function wsf_create_job(){
    check_ajax_referer('wsf_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);

    if(empty($_FILES['csv']) || empty($_POST['target'])){
        wp_send_json_error('Missing CSV or target',400);
    }

    $target=sanitize_text_field($_POST['target']);
    $showMatched = isset($_POST['show_matched']) ? true : false;

    $uploaded=$_FILES['csv'];
    if($uploaded['error']!==UPLOAD_ERR_OK) wp_send_json_error('Upload error: '.$uploaded['error'],400);

    $tmp=$uploaded['tmp_name'];
    $urls=[];
    if(($handle=fopen($tmp,'r'))!==false){
        while(($row=fgetcsv($handle))!==false){
            if(!empty($row[0])){
                $u=trim($row[0]);
                if(!preg_match('#^https?://#i',$u)) continue;
                $urls[]=esc_url_raw($u);
            }
        }
        fclose($handle);
    }

    if(empty($urls)) wp_send_json_error('No valid URLs found',400);

    $job_id=uniqid('wsf_',true);
    $job=[
        'id'=>$job_id,
        'created'=>time(),
        'target'=>$target,
        'urls'=>$urls,
        'position'=>0,
        'total'=>count($urls),
        'batch_results'=>[],
        'results_file'=>'',
        'status'=>'queued',
        'show_matched'=>$showMatched,
    ];

    add_option(WSF_JOB_OPTION_PREFIX.$job_id,$job);
    wp_send_json_success(['job_id'=>$job_id,'total'=>$job['total']]);
}

// ---------------- AJAX: Process Batch with Multi-cURL ----------------
add_action('wp_ajax_wsf_process_batch','wsf_process_batch');
function wsf_process_batch(){
    check_ajax_referer('wsf_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);

    $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']):'';
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']):10;

    if(!$job_id) wp_send_json_error('Missing job_id',400);
    $opt_key = WSF_JOB_OPTION_PREFIX.$job_id;
    $job = get_option($opt_key);
    if(!$job) wp_send_json_error('Job not found',404);

    if($job['status']=='finished'){
        wp_send_json_success(['finished'=>true,'position'=>$job['position'],'total'=>$job['total'],'results_file'=>$job['results_file']]);
    }
    if($job['status']=='stopped'){
        wp_send_json_success(['stopped'=>true]);
    }

    $job['status']='running';
    update_option($opt_key,$job);

    $start = $job['position'];
    $end = min($job['total'],$start+$batch_size);
    $batch_urls = array_slice($job['urls'],$start,$end-$start);

    list($batch_results,$retry_urls) = wsf_fetch_multi($batch_urls,$job['target']);

    // retry failed URLs once
    if($retry_urls){
        list($retry_results,) = wsf_fetch_multi($retry_urls,$job['target']);
        $batch_results = array_merge($batch_results,$retry_results);
    }

    // append results
    $job['batch_results'] = array_merge($job['batch_results'],$batch_results);
    $job['position']=$end;

    if($job['position'] >= $job['total']){
        $upload_dir=wp_upload_dir();
        $results_dir=trailingslashit($upload_dir['basedir']).WSF_RESULTS_SUBDIR;
        if(!file_exists($results_dir)) wp_mkdir_p($results_dir);

        $filename='results_'.$job_id.'_'.date('Ymd_His').'.csv';
        $filepath=trailingslashit($results_dir).$filename;
        $fp=fopen($filepath,'w');
        fputcsv($fp,['URL','Status Code','Match Found']);
        foreach($job['batch_results'] as $r){
            if(!$job['show_matched'] || $r['found'])
                fputcsv($fp,[$r['url'],$r['status'],$r['found']?'Yes':'No']);
        }
        fclose($fp);
        $job['results_file']=trailingslashit($upload_dir['baseurl']).WSF_RESULTS_SUBDIR.'/'.$filename;
        $job['status']='finished';
        unset($job['batch_results']);
    }

    update_option($opt_key,$job);
    wp_send_json_success([
        'position'=>$job['position'],
        'total'=>$job['total'],
        'finished'=>($job['status']=='finished'),
        'results_file'=>$job['results_file']??'',
        'batch_results'=>$batch_results
    ]);
}

// ---------------- AJAX: Stop Job ----------------
add_action('wp_ajax_wsf_stop_job','wsf_stop_job');
function wsf_stop_job(){
    check_ajax_referer('wsf_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']):'';
    if(!$job_id) wp_send_json_error('Missing job_id',400);
    $opt_key = WSF_JOB_OPTION_PREFIX.$job_id;
    $job = get_option($opt_key);
    if(!$job) wp_send_json_error('Job not found',404);
    $job['status']='stopped';
    update_option($opt_key,$job);
    wp_send_json_success(['stopped'=>true]);
}

// ---------------- Helper: Multi-cURL Fetch ----------------
// ---------------- Helper: Multi-cURL Fetch (updated) ----------------
function wsf_fetch_multi($urls, $target) {
    $results = [];
    $retry_urls = [];

    // normalize target for case-insensitive search
    $target_norm = (string) $target;

    $mh = curl_multi_init();
    $handles = [];

    foreach ($urls as $url) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            // Accept compressed responses
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
            ],
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[$url] = $ch;
    }

    $running = null;
    // run the handles
    do {
        $mrc = curl_multi_exec($mh, $running);
        // small sleep to avoid CPU spin if no activity
        if ($mrc === CURLM_CALL_MULTI_PERFORM) {
            continue;
        }
        curl_multi_select($mh, 0.5);
    } while ($running > 0);

    foreach ($handles as $url => $ch) {
        $content = curl_multi_getcontent($ch);
        $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $error = curl_error($ch);

        // normalize content for multibyte and do case-insensitive search
        $found = false;
        if (is_string($content) && $content !== '') {
            // use mb_stripos for case-insensitive substring search
            if (mb_stripos($content, $target_norm) !== false) {
                $found = true;
            } else {
                // sometimes string appears as HTML-encoded or with extra whitespace,
                // we can also search decoded entities as fallback
                $decoded = html_entity_decode($content, ENT_COMPAT | ENT_HTML401, 'UTF-8');
                if (mb_stripos($decoded, $target_norm) !== false) {
                    $found = true;
                }
            }
        }

        // If server returned 500 or empty content, schedule for a retry
        if ($status === 500 || $content === '' || $content === false) {
            $retry_urls[] = $url;
        }

        // ensure consistent keys and types
        $results[] = [
            'url'   => $url,
            'status'=> $status,
            'found' => $found,
            'error' => $error ?: '',
        ];

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    return [$results, $retry_urls];
}

