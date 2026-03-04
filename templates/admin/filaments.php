<?php
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_filament'])) {
    check_admin_referer('fsw_add_filament');
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'fsw_filament_products',
        [
            'filament_type' => sanitize_text_field($_POST['filament_type']),
            'brand' => sanitize_text_field($_POST['brand']),
            'product_name' => sanitize_text_field($_POST['product_name']),
            'diameter_mm' => floatval($_POST['diameter_mm']),
            'manufacturer_url' => esc_url_raw($_POST['manufacturer_url']),
            'notes' => sanitize_textarea_field($_POST['notes'])
        ]
    );
    echo '<div class="notice notice-success"><p>Filament added.</p></div>';
}

// Fetch existing filaments
global $wpdb;
$filaments = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fsw_filament_products ORDER BY filament_type, brand");
?>

<div class="fsw-admin-wrap">
    <h1>Manage Filament Products</h1>
    
    <h2>Add New Filament</h2>
    <form method="post" action="">
        <?php wp_nonce_field('fsw_add_filament'); ?>
        <table class="fsw-table">
            <tr>
                <th>Type</th>
                <td><input type="text" name="filament_type" required placeholder="e.g., PLA, PETG" style="width: 100%; padding: 6px;"></td>
            </tr>
            <tr>
                <th>Brand</th>
                <td><input type="text" name="brand" required style="width: 100%; padding: 6px;"></td>
            </tr>
            <tr>
                <th>Product Name</th>
                <td><input type="text" name="product_name" required style="width: 100%; padding: 6px;"></td>
            </tr>
            <tr>
                <th>Diameter (mm)</th>
                <td><input type="number" step="0.01" name="diameter_mm" value="1.75" min="1.0" max="3.0"></td>
            </tr>
            <tr>
                <th>Manufacturer URL</th>
                <td><input type="url" name="manufacturer_url" style="width: 100%; padding: 6px;"></td>
            </tr>
            <tr>
                <th>Notes</th>
                <td><textarea name="notes" rows="3" style="width: 100%;"></textarea></td>
            </tr>
        </table>
        <p><button type="submit" name="add_filament" class="button button-primary">Add Filament</button></p>
    </form>
    
    <h2>Existing Filaments</h2>
    <table class="fsw-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Brand</th>
                <th>Product</th>
                <th>Diameter</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($filaments as $f): ?>
            <tr>
                <td><?php echo $f->id; ?></td>
                <td><?php echo esc_html($f->filament_type); ?></td>
                <td><?php echo esc_html($f->brand); ?></td>
                <td><?php echo esc_html($f->product_name); ?></td>
                <td><?php echo $f->diameter_mm; ?>mm</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
