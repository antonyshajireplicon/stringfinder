
jQuery(function($){
    let jobId = null;
    let running = false;

    function log(msg){
        const $log = $('#wsf-log');
        $log.append(msg + "\n");
        $log[0].scrollTop = $log[0].scrollHeight;
    }

    function updateProgress(pos, total){
        const pct = total ? Math.round((pos/total)*100) : 0;
        $('#wsf-progress').css('width', pct + '%').text(pct + '%');
        $('#wsf-stats').text(pos + ' / ' + total);
    }

    $('#wsf-start').on('click', function(e){
        e.preventDefault();
        const fileEl = $('#wsf-csv')[0];
        if (!fileEl.files || !fileEl.files[0]) {
            alert('Please select a CSV file.');
            return;
        }
        const target = $('#wsf-target').val().trim();
        if (!target) {
            alert('Please enter a target string.');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'wsf_create_job');
        fd.append('nonce', wsf_ajax.nonce);
        fd.append('csv', fileEl.files[0]);
        fd.append('target', target);

        $('#wsf-start').prop('disabled', true);
        log('Uploading CSV and creating job...');

        $.ajax({
            url: wsf_ajax.ajax_url,
            method: 'POST',
            processData: false,
            contentType: false,
            data: fd,
            success: function(resp){
                if (!resp.success) {
                    alert('Error: ' + resp.data);
                    $('#wsf-start').prop('disabled', false);
                    return;
                }
                jobId = resp.data.job_id;
                const total = resp.data.total;
                log('Job created: ' + jobId + ' — ' + total + ' URLs');
                $('#wsf-stop').prop('disabled', false);
                running = true;
                runLoop();
            },
            error: function(xhr){
                alert('AJAX error creating job');
                $('#wsf-start').prop('disabled', false);
            }
        });
    });

    $('#wsf-stop').on('click', function(){
        if (!jobId) return;
        $.post(wsf_ajax.ajax_url, { action: 'wsf_stop_job', nonce: wsf_ajax.nonce, job_id: jobId }, function(resp){
            if (resp.success) {
                log('Job stopped by user.');
                running = false;
                $('#wsf-stop').prop('disabled', true);
                $('#wsf-start').prop('disabled', false);
            } else {
                log('Stop request failed: ' + JSON.stringify(resp));
            }
        });
    });

    function runLoop(){
        if (!running || !jobId) return;
        const batch = parseInt($('#wsf-batch-size').val() || 10, 10);
        const delay = parseInt($('#wsf-delay').val() || 500, 10);
        // call process_batch
        $.post(wsf_ajax.ajax_url, {
            action: 'wsf_process_batch',
            nonce: wsf_ajax.nonce,
            job_id: jobId,
            batch_size: batch
        }, function(resp){
            if (!resp.success) {
                log('Error processing batch: ' + JSON.stringify(resp));
                running = false;
                $('#wsf-start').prop('disabled', false);
                $('#wsf-stop').prop('disabled', true);
                return;
            }
            const data = resp.data;
            if (data.batch_results && data.batch_results.length) {
                data.batch_results.forEach(r => {
                    const mark = r.found ? '✅' : '❌';
                    log(mark + ' ' + r.url + ' [Status:' + r.status_code + (r.error ? ' Error:'+r.error : '') + ']');
                });
            }
            updateProgress(data.position, data.total);

            if (data.finished) {
                log('Job finished. Results: ' + data.results_file);
                $('#wsf-download').html('<a class="button button-primary" href="' + data.results_file + '" target="_blank" download>Download Results CSV</a>');
                running = false;
                $('#wsf-start').prop('disabled', false);
                $('#wsf-stop').prop('disabled', true);
                return;
            }

            // schedule next batch
            setTimeout(runLoop, delay);
        }).fail(function(xhr){
            log('AJAX request failed for batch: ' + xhr.statusText);
            running = false;
            $('#wsf-start').prop('disabled', false);
        });
    }
});
