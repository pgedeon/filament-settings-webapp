<?php
// Handle bulk quality actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quality_action'])) {
    check_admin_referer('fsw_quality_action');
    global $wpdb;
    
    $action = sanitize_text_field($_POST['quality_action']);
    $setting_ids = isset($_POST['setting_ids']) ? array_map('intval', $_POST['setting_ids']) : [];
    
    if (empty($setting_ids)) {
        echo '<div class="notice notice-error"><p>No settings selected.</p></div>';
    } else {
        $status = ($action === 'approve') ? 'active' : (($action === 'reject') ? 'rejected' : 'superseded');
        $placeholders = implode(',', array_fill(0, count($setting_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fsw_settings SET status = %s WHERE id IN ($placeholders)",
            array_merge([$status], $setting_ids)
        ));
        echo '<div class="notice notice-success"><p>' . count($setting_ids) . ' settings updated.</p></div>';
    }
}

// Fetch quality review settings: low confidence (<0.6) or recently added (last 24h) and still active
global $wpdb;
$quality_settings = $wpdb->get_results($wpdb->prepare(
    "SELECT s.*, 
            p.maker as printer_maker, p.model as printer_model,
            f.brand as filament_brand, f.product_name as filament_product
     FROM {$wpdb->prefix}fsw_settings s
     LEFT JOIN {$wpdb->prefix}fsw_printers pr ON s.printer_id = pr.id
     LEFT JOIN {$wpdb->prefix}fsw_filament_products f ON s.filament_product_id = f.id
     WHERE s.status = 'active' 
       AND (s.confidence < %f OR s.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY))
     ORDER BY s.confidence ASC, s.created_at DESC
     LIMIT 100",
    0.6
));
?>

<div class="fsw-admin-wrap">
    <h1>Quality Review</h1>
    <p>Review recently added or low-confidence settings for quality control.</p>
    
    <form method="post" action="">
        <?php wp_nonce_field('fsw_quality_action'); ?>
        <select name="quality_action" required>
            <option value="">Bulk Action</option>
            <option value="approve">Approve Selected</option>
            <option value="reject">Reject Selected</option>
            <option value="supersede">Supersede Selected</option>
        </select>
        <button type="submit" class="button button-primary">Apply</button>
        <a href="?page=filament-settings-settings" class="button">Back to Full Registry</a>
    </form>
    
    <table class="fsw-table">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all"></th>
                <th>ID</th>
                <th>Filament</th>
                <th>Printer</th>
                <th>Nozzle</th>
                <th>Temps (Nozzle/Bed)</th>
                <th>Confidence</th>
                <th>Created</th>
                <th>Source</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($quality_settings)): ?>
            <tr>
                <td colspan="10">No settings require review at this time.</td>
            </tr>
            <?php else: ?>
                <?php foreach ($quality_settings as $s): 
                    $settings = json_decode($s->settings_json, true);
                    $nozzle_temp = $settings['nozzle_temp_c'] ?? 'N/A';
                    $bed_temp = $settings['bed_temp_c'] ?? 'N/A';
                    $filament_name = $s->filament_product ? $s->filament_brand . ' ' . $s->filament_product : $s->filament_type;
                    $printer_name = $s->printer_maker ? $s->printer_maker . ' ' . $s->printer_model : 'Generic';
                ?>
                <tr>
                    <td><input type="checkbox" name="setting_ids[]" value="<?php echo $s->id; ?>"></td>
                    <td><?php echo $s->id; ?></td>
                    <td><?php echo esc_html($filament_name); ?></td>
                    <td><?php echo esc_html($printer_name); ?></td>
                    <td><?php echo $s->nozzle_diameter_mm; ?>mm</td>
                    <td><?php echo $nozzle_temp; ?>°C / <?php echo $bed_temp; ?>°C</td>
                    <td><?php echo round($s->confidence * 100); ?>%</td>
                    <td><?php echo date('Y-m-d H:i', strtotime($s->created_at)); ?></td>
                    <td><?php echo esc_html($s->publisher); ?> (prio: <?php echo $s->source_priority; ?>)</td>
                    <td>
                        <button class="fsw-btn fsw-btn-secondary" data-action="approve" data-id="<?php echo $s->id; ?>">Approve</button>
                        <button class="fsw-btn fsw-btn-secondary" data-action="reject" data-id="<?php echo $s->id; ?>">Reject</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
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
        if (confirm('Mark this setting as ' + action + '?')) {
            $.post(ajaxurl, {
                action: 'fsw_setting_action',
                setting_id: id,
                status: action === 'reject' ? 'rejected' : ($(this).data('action') === 'supersede' ? 'superseded' : 'active'),
                nonce: '<?php echo wp_create_nonce('fsw_settings_action'); ?>'
            }, function() {
                location.reload();
            });
        }
    });
});
</script>

<style>
.fsw-admin-wrap { max-width: 1200px; margin: 20px; }
.fsw-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.fsw-table th, .fsw-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
.fsw-table th { background: #f1f1f1; }
.fsw-btn { margin: 2px; }
</style>
