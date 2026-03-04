<?php
// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_source'])) {
    check_admin_referer('fsw_save_source');
    global $wpdb;
    
    $source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : null;
    $source_type = sanitize_text_field($_POST['source_type']);
    $publisher = sanitize_text_field($_POST['publisher']);
    $url = esc_url_raw($_POST['url']);
    $priority = intval($_POST['priority']);
    $active = isset($_POST['active']) ? 1 : 0;
    
    $data = [
        'source_type' => $source_type,
        'publisher' => $publisher,
        'url' => $url,
        'priority' => $priority,
        'active' => $active
    ];
    
    if ($source_id) {
        // Update existing
        $wpdb->update(
            $wpdb->prefix . 'fsw_sources',
            $data,
            ['id' => $source_id],
            ['%s', '%s', '%s', '%d', '%d'],
            ['%d']
        );
        echo '<div class="notice notice-success"><p>Source updated.</p></div>';
    } else {
        // Insert new, generate hash_content for uniqueness
        $data['hash_content'] = sha1($url . $publisher . $source_type);
        $wpdb->insert(
            $wpdb->prefix . 'fsw_sources',
            $data,
            ['%s', '%s', '%s', '%d', '%d', '%s']
        );
        echo '<div class="notice notice-success"><p>Source added.</p></div>';
    }
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    check_admin_referer('fsw_delete_source_' . intval($_GET['id']));
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'fsw_sources', ['id' => intval($_GET['id'])]);
    echo '<div class="notice notice-success"><p>Source deleted.</p></div>';
}

// Fetch existing sources
global $wpdb;
$sources = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fsw_sources ORDER BY priority ASC, publisher ASC");

// Get source for editing if ID provided
$edit_source = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_source = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fsw_sources WHERE id = %d",
        intval($_GET['id'])
    ));
}
?>

<div class="fsw-admin-wrap">
    <h1>Manage Sources</h1>
    <p>Configure RSS feeds, manufacturer sites, and other sources for autonomous collection.</p>
    
    <h2><?php echo $edit_source ? 'Edit Source' : 'Add New Source'; ?></h2>
    <form method="post" action="">
        <?php wp_nonce_field('fsw_save_source'); ?>
        <?php if ($edit_source): ?>
            <input type="hidden" name="source_id" value="<?php echo $edit_source->id; ?>">
        <?php endif; ?>
        
        <table class="fsw-table">
            <tr>
                <th style="width: 150px;">Source Type</th>
                <td>
                    <select name="source_type" required style="width: 250px;">
                        <option value="manufacturer" <?php selected($edit_source && $edit_source->source_type == 'manufacturer'); ?>>Manufacturer</option>
                        <option value="printer_oem" <?php selected($edit_source && $edit_source->source_type == 'printer_oem'); ?>>Printer OEM</option>
                        <option value="slicer_vendor" <?php selected($edit_source && $edit_source->source_type == 'slicer_vendor'); ?>>Slicer Vendor</option>
                        <option value="community" <?php selected($edit_source && $edit_source->source_type == 'community'); ?>>Community</option>
                        <option value="unknown" <?php selected($edit_source && $edit_source->source_type == 'unknown'); ?>>Unknown</option>
                    </select>
                    <small>Type of source - affects extraction heuristics</small>
                </td>
            </tr>
            <tr>
                <th>Publisher/Brand</th>
                <td><input type="text" name="publisher" required style="width: 400px;" placeholder="e.g., Hatchbox, Prusa, Bambu Lab"></td>
            </tr>
            <tr>
                <th>URL</th>
                <td><input type="url" name="url" required style="width: 400px;" placeholder="https://example.com/filament-settings"></td>
            </tr>
            <tr>
                <th>Priority</th>
                <td>
                    <input type="number" name="priority" min="1" max="100" value="<?php echo $edit_source ? $edit_source->priority : 10; ?>" style="width: 80px;">
                    <small>Lower number = higher priority (1 is highest)</small>
                </td>
            </tr>
            <tr>
                <th>Active</th>
                <td>
                    <label><input type="checkbox" name="active" value="1" <?php checked(!$edit_source || $edit_source->active); ?>> Enable this source for collection</label>
                </td>
            </tr>
        </table>
        
        <p>
            <button type="submit" name="save_source" class="button button-primary"><?php echo $edit_source ? 'Update Source' : 'Add Source'; ?></button>
            <?php if ($edit_source): ?>
                <a href="?page=filament-settings-sources" class="button">Cancel Edit</a>
            <?php endif; ?>
        </p>
    </form>
    
    <h2>Existing Sources</h2>
    <?php
    global $wpdb;
    $sources = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fsw_sources ORDER BY priority DESC, id DESC");
    ?>
    <table class="fsw-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Publisher</th>
                <th>URL</th>
                <th>Priority</th>
                <th>Active</th>
                <th>Last Retrieved</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sources)): ?>
            <tr>
                <td colspan="8">No sources configured. Add your first source above.</td>
            </tr>
            <?php else: ?>
                <?php foreach ($sources as $s): ?>
                <tr>
                    <td><?php echo $s->id; ?></td>
                    <td><?php echo esc_html($s->source_type); ?></td>
                    <td><?php echo esc_html($s->publisher); ?></td>
                    <td><a href="<?php echo esc_url($s->url); ?>" target="_blank"><?php echo esc_html($s->url); ?></a></td>
                    <td><?php echo $s->priority; ?></td>
                    <td><?php echo $s->active ? 'Yes' : 'No'; ?></td>
                    <td><?php echo $s->retrieved_at ? $s->retrieved_at : 'Never'; ?></td>
                    <td>
                        <a href="?page=filament-settings-sources&action=edit&id=<?php echo $s->id; ?>&nonce=<?php echo wp_create_nonce('fsw_edit_source_' . $s->id); ?>" class="button button-small">Edit</a>
                        <a href="?page=filament-settings-sources&action=delete&id=<?php echo $s->id; ?>&nonce=<?php echo wp_create_nonce('fsw_delete_source_' . $s->id); ?>" 
                           onclick="return confirm('Delete this source? This cannot be undone.')" class="button button-small">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.fsw-admin-wrap { max-width: 1200px; margin: 20px; }
.fsw-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.fsw-table th, .fsw-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
.fsw-table th { background: #f1f1f1; }
.fsw-table input[type="text"], fsw-table input[type="url"], fsw-table select { width: 100%; padding: 6px; }
</style>
