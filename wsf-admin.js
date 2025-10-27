jQuery(document).ready(function($){
    let jobId = null;
    let scanning = false;

    function log(msg){
        const $log = $('#wsf-log');
        $log.append(msg + "\n");
        $log[0].scrollTop = $log[0].scrollHeight;
    }

    function normalizeStatus(r){
        // try multiple possible names (backwards compatibility)
        if (typeof r.status !== 'undefined') return r.status;
        if (typeof r.status_code !== 'undefined') return r.status_code;
        if (typeof r.statusCode !== 'undefined') return r.statusCode;
        return 'unknown';
    }

    function normalizeFound(r){
        // result may be boolean, or string 'Yes'/'No', or numeric
        if (typeof r.found === 'boolean') return r.found;
        if (typeof r.found === 'string'){
            const v = r.found.toLowerCase();
            return (v === '1' || v === 'yes' || v === 'true');
        }
        if (typeof r.found === 'number') return r.found !== 0;
        return false;
    }

    function updateProgress(position,total){
        let percent = total > 0 ? Math.round((position / total) * 100) : 0;
        $('#wsf-progress').css('width', percent + '%').text(percent + '%');
        $('#wsf-stats').text(`Processed ${position} of ${total} URLs (${percent}%)`);
    }

    function processBatch(){
        if(!scanning || !jobId) return;

        let batchSize = parseInt($('#wsf-batch-size').val()) || 10;

        $.ajax({
            url: wsf_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'wsf_process_batch',
                nonce: wsf_ajax.nonce,
                job_id: jobId,
                batch_size: batchSize
            },
            dataType: 'json',
            success: function(resp){
                if(!resp.success){
                    log('Error: ' + (resp.data || 'Unknown'));
                    scanning = false;
                    $('#wsf-start').prop('disabled', false);
                    $('#wsf-stop').prop('disabled', true);
                    return;
                }

                let data = resp.data;

                if (Array.isArray(data.batch_results)) {
                    data.batch_results.forEach(function(r){
                        // normalize status & found
                        let status = normalizeStatus(r);
                        let found = normalizeFound(r);

                        // show only if not filtering OR if matched
                        if (!$('#wsf-show-matched').is(':checked') || found) {
                            const mark = found ? 'Found' : 'Not Found';
                            log(`${r.url},${mark},${status}${r.error && r.error.length ? ' Error: '+r.error : ''}`);
                        }
                    });
                }

                updateProgress(data.position, data.total);

                if (data.finished) {
                    scanning = false;
                    $('#wsf-start').prop('disabled', false);
                    $('#wsf-stop').prop('disabled', true);
                    log('Scan finished!');
                    if (data.results_file) {
                        $('#wsf-download').html(`<a href="${data.results_file}" target="_blank" class="button button-primary">📥 Download CSV Results</a>`);
                    }
                } else if (data.stopped) {
                    scanning = false;
                    log('Scan stopped by user.');
                    $('#wsf-start').prop('disabled', false);
                    $('#wsf-stop').prop('disabled', true);
                } else {
                    setTimeout(processBatch, parseInt($('#wsf-delay').val()) || 500);
                }
            },
            error: function(xhr){
                log('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
                scanning = false;
                $('#wsf-start').prop('disabled', false);
                $('#wsf-stop').prop('disabled', true);
            }
        });
    }

    $('#wsf-start').click(function(){
        let target = $('#wsf-target').val().trim();
        let file = $('#wsf-csv')[0].files[0];
        let showMatched = $('#wsf-show-matched').is(':checked');

        if(!target){ alert('Please enter target string'); return; }
        if(!file){ alert('Please select a CSV file'); return; }

        let formData = new FormData();
        formData.append('action','wsf_create_job');
        formData.append('nonce',wsf_ajax.nonce);
        formData.append('csv',file);
        formData.append('target',target);
        formData.append('show_matched', showMatched ? 1 : 0);

        $.ajax({
            url: wsf_ajax.ajax_url,
            method: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            beforeSend: function(){
                log('Starting new scan...');
                scanning = true;
                $('#wsf-start').prop('disabled', true);
                $('#wsf-stop').prop('disabled', false);
                $('#wsf-progress').css('width', '0%').text('0%');
                $('#wsf-stats').text('Initializing...');
                $('#wsf-log').text('');
                $('#wsf-download').html('');
            },
            success: function(resp){
                if(!resp.success){
                    log('Error: ' + (resp.data || 'Unknown'));
                    scanning = false;
                    $('#wsf-start').prop('disabled', false);
                    $('#wsf-stop').prop('disabled', true);
                    return;
                }
                jobId = resp.data.job_id;
                log(`Job created: ${jobId} — ${resp.data.total} URLs`);
                // small initial delay to let job update
                setTimeout(processBatch, 250);
            },
            error: function(xhr){
                log('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
                scanning = false;
                $('#wsf-start').prop('disabled', false);
                $('#wsf-stop').prop('disabled', true);
            }
        });
    });

    $('#wsf-stop').click(function(){
        if(!jobId) return;
        $.ajax({
            url: wsf_ajax.ajax_url,
            method: 'POST',
            data: { action: 'wsf_stop_job', nonce: wsf_ajax.nonce, job_id: jobId },
            dataType: 'json',
            success: function(resp){
                if(resp.success){
                    log('Stop request sent.');
                    scanning = false;
                    $('#wsf-start').prop('disabled', false);
                    $('#wsf-stop').prop('disabled', true);
                } else {
                    log('Stop request failed: ' + JSON.stringify(resp));
                }
            },
            error: function(xhr){
                log('Stop request AJAX error: ' + xhr.status);
            }
        });
    });
});
