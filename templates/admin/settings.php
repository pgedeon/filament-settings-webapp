<div class="fsw-admin-wrap">
    <h1>Settings Registry</h1>
    
    <p>Active settings are served to users via the frontend app. Conflicts are kept and ranked by source priority.</p>
    
    <form method="post" action="">
        <?php wp_nonce_field('fsw_bulk_action'); ?>
        <select name="bulk_action">
            <option value="">Bulk Action</option>
            <option value="reject">Reject Selected</option>
        </select>
        <button type="submit" name="apply_bulk" class="button">Apply</button>
    </form>
    
    <?php
    global $wpdb;
    // Query settings with vote stats included
    $settings = $wpdb->get_results("
        SELECT s.*, 
               p.brand, p.product_name,
               pr.maker, pr.model,
               s.settings_json,
               COALESCE(v.total_votes, 0) as total_votes,
               COALESCE(v.up_votes, 0) as up_votes,
               COALESCE(v.down_votes, 0) as down_votes
        FROM {$wpdb->prefix}fsw_settings s
        LEFT JOIN {$wpdb->prefix}fsw_filament_products p ON s.filament_product_id = p.id
        LEFT JOIN {$wpdb->prefix}fsw_printers pr ON s.printer_id = pr.id
        LEFT JOIN (
            SELECT setting_id,
                   COUNT(*) as total_votes,
                   SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END) as up_votes,
                   SUM(CASE WHEN vote = -1 THEN 1 ELSE 0 END) as down_votes
            FROM {$wpdb->prefix}fsw_setting_votes
            GROUP BY setting_id
        ) v ON s.id = v.setting_id
        ORDER BY s.id DESC
    ");
    ?>
    
    <table class="fsw-table">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all"></th>
                <th>ID</th>
                <th>Filament</th>
                <th>Printer</th>
                <th>Nozzle</th>
                <th>Source</th>
                <th>Priority</th>
                <th>Confidence</th>
                <th>Votes</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($settings as $s): ?>
            <tr>
                <td><input type="checkbox" name="setting_ids[]" value="<?php echo $s->id; ?>"></td>
                <td><?php echo $s->id; ?></td>
                <td><?php echo esc_html($s->brand . ' ' . $s->product_name); ?></td>
                <td><?php echo esc_html($s->maker . ' ' . $s->model); ?></td>
                <td><?php echo $s->nozzle_diameter_mm; ?>mm</td>
                <td><?php echo esc_html($s->publisher); ?></td>
                <td><?php echo $s->source_priority; ?></td>
                <td><?php echo round($s->confidence * 100); ?>%</td>
                <td>
                    <span title="Up: <?php echo $s->up_votes; ?>, Down: <?php echo $s->down_votes; ?>">
                        <?php echo $s->total_votes; ?> (↑<?php echo $s->up_votes; ?> ↓<?php echo $s->down_votes; ?>)
                    </span>
                </td>
                <td class="fsw-status-<?php echo $s->status; ?>"><?php echo ucfirst($s->status); ?></td>
                <td>
                    <?php if ($s->status === 'active'): ?>
                    <button class="fsw-btn fsw-btn-secondary" data-action="supersede" data-id="<?php echo $s->id; ?>">Supersede</button>
                    <?php endif; ?>
                    <button class="fsw-btn fsw-btn-secondary" data-action="reject" data-id="<?php echo $s->id; ?>">Reject</button>
                    <?php if ($s->total_votes > 0): ?>
                    <button class="fsw-btn fsw-btn-secondary" data-action="reset_votes" data-id="<?php echo $s->id; ?>">Reset Votes</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(function($) {
    $('#select-all').on('change', function() {
        $('input[name="setting_ids[]"]').prop('checked', this.checked);
    });
    
    $('.fsw-btn-secondary').on('click', function() {
        var id = $(this).data('id');
        var action = $(this).data('action');
        if (confirm('Are you sure?')) {
            if (action === 'reset_votes') {
                $.post(ajaxurl, {
                    action: 'fsw_reset_votes',
                    setting_id: id,
                    nonce: '<?php echo wp_create_nonce('fsw_settings_action'); ?>'
                }, function() {
                    location.reload();
                });
            } else {
                $.post(ajaxurl, {
                    action: 'fsw_setting_action',
                    setting_id: id,
                    status: action === 'reject' ? 'rejected' : 'superseded',
                    nonce: '<?php echo wp_create_nonce('fsw_settings_action'); ?>'
                }, function() {
                    location.reload();
                });
            }
        }
    });
});
</script>
