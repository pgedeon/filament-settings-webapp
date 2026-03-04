<div class="fsw-admin-wrap">
    <h1>Collector</h1>
    
    <p>Runs autonomous collection of filament settings from manufacturer feeds, printer OEM sites, and slicer vendor documentation.</p>
    
    <div class="fsw-collector-status">
        <h2>Last Run</h2>
        <p><?php echo $last_run ? $last_run : 'Never'; ?></p>
        
        <h2>Run Now</h2>
        <button id="fsw-run-collector" class="button button-primary">Start Collector</button>
        <div id="fsw-collector-output" style="margin-top: 1rem; padding: 1rem; background: #f1f1f1; display: none;"></div>
    </div>
    
    <h2>Maintenance</h2>
    <p>Perform periodic cleanup of old rejected settings and orphaned records.</p>
    <button id="fsw-run-cleanup" class="button">Run Cleanup</button>
    <div id="fsw-cleanup-output" style="margin-top: 1rem; padding: 1rem; background: #f1f1f1; display: none;"></div>
    
    <h2>Collector Log</h2>
    <pre style="background: #f1f1f1; padding: 1rem; max-height: 400px; overflow: auto;"><?php echo esc_html($log_tail); ?></pre>
</div>

<script>
jQuery(function($) {
    $('#fsw-run-collector').on('click', function() {
        var $btn = $(this);
        var $out = $('#fsw-collector-output');
        $btn.prop('disabled', true).text('Running...');
        $out.show().text('Starting collector...');
        
        $.post(ajaxurl, {
            action: 'fsw_run_collector',
            nonce: '<?php echo wp_create_nonce('fsw_run_collector'); ?>'
        }, function(resp) {
            $btn.prop('disabled', false).text('Start Collector');
            if (resp.success) {
                $out.text('Collector completed successfully. Check log for details.').after('<p>Refreshing...</p>');
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                $out.text('Error: ' + resp.data);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Start Collector');
            $out.text('AJAX request failed.');
        });
    });
    
    $('#fsw-run-cleanup').on('click', function() {
        var $btn = $(this);
        var $out = $('#fsw-cleanup-output');
        $btn.prop('disabled', true).text('Running cleanup...');
        $out.show().text('Starting cleanup...');
        
        $.post(ajaxurl, {
            action: 'fsw_run_cleanup',
            nonce: '<?php echo wp_create_nonce('fsw_run_cleanup'); ?>'
        }, function(resp) {
            $btn.prop('disabled', false).text('Run Cleanup');
            if (resp.success) {
                $out.text('Cleanup completed successfully: ' + resp.data).after('<p>Refreshing...</p>');
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                $out.text('Error: ' + resp.data);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Run Cleanup');
            $out.text('AJAX request failed.');
        });
    });
});
</script>
