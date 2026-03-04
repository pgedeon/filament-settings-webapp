<?php
global $wpdb;
$table = $wpdb->prefix . 'fsw_printers';

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have permission to access this page.'));
}

// Handle add/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_printer'])) {
        check_admin_referer('fsw_add_printer');
        $data = collect_form_data();
        if (empty($data['maker']) || empty($data['model'])) {
            echo '<div class="notice notice-error"><p>Maker and model are required.</p></div>';
        } else {
            $wpdb->insert($table, $data);
            echo '<div class="notice notice-success"><p>Printer added.</p></div>';
        }
    } elseif (isset($_POST['edit_printer'])) {
        check_admin_referer('fsw_edit_printer_' . intval($_POST['printer_id']));
        $id = intval($_POST['printer_id']);
        $data = collect_form_data();
        if (empty($data['maker']) || empty($data['model'])) {
            echo '<div class="notice notice-error"><p>Maker and model are required.</p></div>';
        } else {
            $wpdb->update($table, $data, ['id' => $id]);
            echo '<div class="notice notice-success"><p>Printer updated.</p></div>';
        }
    }
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    check_admin_referer('fsw_delete_printer_' . intval($_GET['id']), 'nonce');
    $wpdb->delete($table, ['id' => intval($_GET['id'])]);
    echo '<div class="notice notice-success"><p>Printer deleted.</p></div>';
}

// Load single printer for editing
$editing = false;
$printer = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editing = true;
    $printer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['id'])));
    if (!$printer) {
        echo '<div class="notice notice-error"><p>Printer not found.</p></div>';
        $editing = false;
    }
}

// Fetch all printers for listing
$printers = $wpdb->get_results("SELECT * FROM $table ORDER BY maker, model");

// Helper: get form field value (for editing)
function fsw_field($printer, $field, $default = '') {
    global $editing, $printer;
    if ($editing && isset($printer->$field)) {
        return esc_attr($printer->$field);
    }
    return $default;
}

// Helper: get checkbox value
function fsw_checked($printer, $field, $value = 1) {
    global $editing, $printer;
    if ($editing && isset($printer->$field)) {
        return $printer->$field == $value ? 'checked' : '';
    }
    return '';
}
?>

<div class="fsw-admin-wrap">
    <h1>Manage Printers</h1>
    
    <h2><?php echo $editing ? 'Edit Printer' : 'Add New Printer'; ?></h2>
    <form method="post" action="">
        <?php 
        if ($editing) {
            wp_nonce_field('fsw_edit_printer_' . $printer->id);
            echo '<input type="hidden" name="printer_id" value="' . $printer->id . '">';
        } else {
            wp_nonce_field('fsw_add_printer');
        }
        ?>
        
        <h3>Basic Information</h3>
        <table class="fsw-table">
            <tr>
                <th>Maker/Brand</th>
                <td><input type="text" name="maker" required style="width: 100%; padding: 6px;" 
                       placeholder="e.g., Creality, Prusa, Bambu Lab" 
                       value="<?php echo fsw_field($printer, 'maker'); ?>"></td>
            </tr>
            <tr>
                <th>Model</th>
                <td><input type="text" name="model" required style="width: 100%; padding: 6px;" 
                       placeholder="e.g., Ender 3 V2, X1 Carbon"
                       value="<?php echo fsw_field($printer, 'model'); ?>"></td>
            </tr>
        </table>
        
        <h3>Build Volume (mm)</h3>
        <table class="fsw-table">
            <tr>
                <th>X (Width)</th>
                <td><input type="number" step="0.1" name="build_volume_x_mm" style="width: 100%; padding: 6px;" 
                       placeholder="e.g., 220" 
                       value="<?php echo fsw_field($printer, 'build_volume_x_mm'); ?>"></td>
            </tr>
            <tr>
                <th>Y (Depth)</th>
                <td><input type="number" step="0.1" name="build_volume_y_mm" style="width: 100%; padding: 6px;" 
                       placeholder="e.g., 220"
                       value="<?php echo fsw_field($printer, 'build_volume_y_mm'); ?>"></td>
            </tr>
            <tr>
                <th>Z (Height)</th>
                <td><input type="number" step="0.1" name="build_volume_z_mm" style="width: 100%; padding: 6px;" 
                       placeholder="e.g., 250"
                       value="<?php echo fsw_field($printer, 'build_volume_z_mm'); ?>"></td>
            </tr>
        </table>
        
        <h3>Enclosure & Environment</h3>
        <table class="fsw-table">
            <tr>
                <th>Enclosure</th>
                <td>
                    <label><input type="checkbox" name="enclosure" value="1" <?php echo fsw_checked($printer, 'enclosure'); ?>> Has Physical Enclosure</label>
                </td>
            </tr>
            <tr>
                <th>Heated Enclosure</th>
                <td>
                    <label><input type="checkbox" name="heated_enclosure" value="1" <?php echo fsw_checked($printer, 'heated_enclosure'); ?>> Active Heating</label>
                </td>
            </tr>
            <tr>
                <th>Max Enclosure Temp (°C)</th>
                <td><input type="number" name="enclosure_temp_max_c" min="20" max="80" style="width: 100px;" 
                       value="<?php echo fsw_field($printer, 'enclosure_temp_max_c'); ?>"></td>
            </tr>
            <tr>
                <th>Chamber Heated</th>
                <td>
                    <label><input type="checkbox" name="chamber_heated" value="1" <?php echo fsw_checked($printer, 'chamber_heated'); ?>> Chamber Heating</label>
                </td>
            </tr>
        </table>
        
        <h3>Bed & Leveling</h3>
        <table class="fsw-table">
            <tr>
                <th>Max Bed Temp (°C)</th>
                <td><input type="number" name="max_bed_temp_c" min="50" max="200" style="width: 100px;"
                       value="<?php echo fsw_field($printer, 'max_bed_temp_c', 120); ?>"></td>
            </tr>
            <tr>
                <th>Auto Level Type</th>
                <td>
                    <select name="autolevel_type">
                        <option value="none" <?php selected(fsw_field($printer, 'autolevel_type'), 'none'); ?>>None (Manual)</option>
                        <option value="blob" <?php selected(fsw_field($printer, 'autolevel_type'), 'blob'); ?>>BLTouch/3D Touch</option>
                        <option value="mesh" <?php selected(fsw_field($printer, 'autolevel_type'), 'mesh'); ?>>Mesh Bed Leveling</option>
                        <option value="bed_visualizer" <?php selected(fsw_field($printer, 'autolevel_type'), 'bed_visualizer'); ?>>Bed Visualizer</option>
                        <option value="capacitive" <?php selected(fsw_field($printer, 'autolevel_type'), 'capacitive'); ?>>Capacitive Probe</option>
                        <option value="inductive" <?php selected(fsw_field($printer, 'autolevel_type'), 'inductive'); ?>>Inductive Probe</option>
                        <option value="other" <?php selected(fsw_field($printer, 'autolevel_type'), 'other'); ?>>Other</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Probe Points</th>
                <td><input type="number" name="autolevel_points" min="3" max="10000" style="width: 100px;"
                       value="<?php echo fsw_field($printer, 'autolevel_points'); ?>"></td>
            </tr>
            <tr>
                <th>Build Surface</th>
                <td>
                    <select name="build_surface_type">
                        <option value="other" <?php selected(fsw_field($printer, 'build_surface_type'), 'other'); ?>>Other/Unknown</option>
                        <option value="glass" <?php selected(fsw_field($printer, 'build_surface_type'), 'glass'); ?>>Glass</option>
                        <option value="pei" <?php selected(fsw_field($printer, 'build_surface_type'), 'pei'); ?>>PEI Spring Steel</option>
                        <option value="peek" <?php selected(fsw_field($printer, 'build_surface_type'), 'peek'); ?>>PEEK</option>
                        <option value="g10" <?php selected(fsw_field($printer, 'build_surface_type'), 'g10'); ?>>G10/Fiberboard</option>
                        <option value="buildtak" <?php selected(fsw_field($printer, 'build_surface_type'), 'buildtak'); ?>>BuildTak</option>
                        <option value="pcb" <?php selected(fsw_field($printer, 'build_surface_type'), 'pcb'); ?>>PCB Heatbed</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Removable Build Plate</th>
                <td>
                    <label><input type="checkbox" name="build_surface_removable" value="1" <?php echo fsw_checked($printer, 'build_surface_removable'); ?>> Yes</label>
                </td>
            </tr>
        </table>
        
        <h3>Hotend & Extrusion</h3>
        <table class="fsw-table">
            <tr>
                <th>Extruder Type</th>
                <td>
                    <select name="extruder_type">
                        <option value="bowden" <?php selected(fsw_field($printer, 'extruder_type'), 'bowden'); ?>>Bowden</option>
                        <option value="direct" <?php selected(fsw_field($printer, 'extruder_type'), 'direct'); ?>>Direct Drive</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Hotend Type</th>
                <td>
                    <select name="hotend_type">
                        <option value="bowden" <?php selected(fsw_field($printer, 'hotend_type'), 'bowden'); ?>>Standard (V6, etc.)</option>
                        <option value="direct" <?php selected(fsw_field($printer, 'hotend_type'), 'direct'); ?>>Direct (e.g., Mercury, Dragon)</option>
                        <option value="all-metal" <?php selected(fsw_field($printer, 'hotend_type'), 'all-metal'); ?>>All-Metal (high temp)</option>
                        <option value="volcano" <?php selected(fsw_field($printer, 'hotend_type'), 'volcano'); ?>>Volcano/High-Flow</option>
                        <option value="other" <?php selected(fsw_field($printer, 'hotend_type'), 'other'); ?>>Other</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Max Hotend Temp (°C)</th>
                <td><input type="number" name="max_hotend_temp_c" min="200" max="500" style="width: 100px;"
                       value="<?php echo fsw_field($printer, 'max_hotend_temp_c', 300); ?>"></td>
            </tr>
            <tr>
                <th>Nozzle Count</th>
                <td><input type="number" name="nozzle_count" min="1" max="4" style="width: 60px;"
                       value="<?php echo fsw_field($printer, 'nozzle_count', 1); ?>"></td>
            </tr>
            <tr>
                <th>Mixing Extruder</th>
                <td>
                    <label><input type="checkbox" name="mixing_extruder" value="1" <?php echo fsw_checked($printer, 'mixing_extruder'); ?>> Can mix multiple filaments</label>
                </td>
            </tr>
            <tr>
                <th>Fast Hotend</th>
                <td>
                    <label><input type="checkbox" name="fast_hotend" value="1" <?php echo fsw_checked($printer, 'fast_hotend'); ?>> High-flow hotend</label>
                </td>
            </tr>
        </table>
        
        <h3>Motion & Frame</h3>
        <table class="fsw-table">
            <tr>
                <th>Frame Type</th>
                <td>
                    <select name="frame_type">
                        <option value="open" <?php selected(fsw_field($printer, 'frame_type'), 'open'); ?>>Open Frame</option>
                        <option value="enclosed" <?php selected(fsw_field($printer, 'frame_type'), 'enclosed'); ?>>Enclosed Frame</option>
                        <option value="cubic" <?php selected(fsw_field($printer, 'frame_type'), 'cubic'); ?>>Cubic/Box</option>
                        <option value="delta" <?php selected(fsw_field($printer, 'frame_type'), 'delta'); ?>>Delta</option>
                        <option value="corexy" <?php selected(fsw_field($printer, 'frame_type'), 'corexy'); ?>>CoreXY</option>
                        <option value="coredxy" <?php selected(fsw_field($printer, 'frame_type'), 'coredxy'); ?>>CoreXY with Dual Stepper</option>
                        <option value="hbot" <?php selected(fsw_field($printer, 'frame_type'), 'hbot'); ?>>H-Bot</option>
                        <option value="other" <?php selected(fsw_field($printer, 'frame_type'), 'other'); ?>>Other</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Travel Speed (mm/s)</th>
                <td><input type="number" name="travel_speed_mm_s" min="50" max="1000" style="width: 100px;"
                       placeholder="e.g., 200"
                       value="<?php echo fsw_field($printer, 'travel_speed_mm_s'); ?>"></td>
            </tr>
            <tr>
                <th>Linear Rails (Axes)</th>
                <td>
                    <input type="text" name="linear_rail_xyz" style="width: 150px;"
                       placeholder="e.g., X,Y or all or none"
                       value="<?php echo fsw_field($printer, 'linear_rail_xyz'); ?>">
                </td>
            </tr>
            <tr>
                <th>Belt Drive</th>
                <td>
                    <label><input type="checkbox" name="belt_drive" value="1" <?php echo fsw_checked($printer, 'belt_drive', 1); ?>> Yes (belt-driven)</label>
                </td>
            </tr>
        </table>
        
        <h3>Connectivity & Display</h3>
        <table class="fsw-table">
            <tr>
                <th>Display Type</th>
                <td>
                    <select name="display_type">
                        <option value="lcd_12864" <?php selected(fsw_field($printer, 'display_type'), 'lcd_12864'); ?>>128x64 LCD (monochrome)</option>
                        <option value="lcd_320240" <?php selected(fsw_field($printer, 'display_type'), 'lcd_320240'); ?>>320x240 LCD (color)</option>
                        <option value="touchscreen" <?php selected(fsw_field($printer, 'display_type'), 'touchscreen'); ?>>Touchscreen</option>
                        <option value="none" <?php selected(fsw_field($printer, 'display_type'), 'none'); ?>>None (standalone/smartphone)</option>
                        <option value="smartphone" <?php selected(fsw_field($printer, 'display_type'), 'smartphone'); ?>>Smartphone Only</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>TFT Color Display</th>
                <td>
                    <label><input type="checkbox" name="tft_display" value="1" <?php echo fsw_checked($printer, 'tft_display'); ?>> Color TFT screen</label>
                </td>
            </tr>
            <tr>
                <th>WiFi Enabled</th>
                <td>
                    <label><input type="checkbox" name="wifi_enabled" value="1" <?php echo fsw_checked($printer, 'wifi_enabled'); ?>> WiFi connectivity</label>
                </td>
            </tr>
            <tr>
                <th>Ethernet Enabled</th>
                <td>
                    <label><input type="checkbox" name="ethernet_enabled" value="1" <?php echo fsw_checked($printer, 'ethernet_enabled'); ?>> Ethernet port</label>
                </td>
            </tr>
            <tr>
                <th>USB Media Support</th>
                <td>
                    <label><input type="checkbox" name="usb_media" value="1" <?php echo fsw_checked($printer, 'usb_media', 1); ?>> USB flash drive</label>
                </td>
            </tr>
        </table>
        
        <h3>Advanced Features</h3>
        <table class="fsw-table">
            <tr>
                <th>Pressure Advance</th>
                <td>
                    <label><input type="checkbox" name="pressure_advance" value="1" <?php echo fsw_checked($printer, 'pressure_advance'); ?>> Supports pressure advance</label>
                </td>
            </tr>
            <tr>
                <th>Input Shaping</th>
                <td>
                    <label><input type="checkbox" name="input_shaping" value="1" <?php echo fsw_checked($printer, 'input_shaping'); ?>> Resonance compensation</label>
                </td>
            </tr>
            <tr>
                <th>Multi-Material</th>
                <td>
                    <label><input type="checkbox" name="multi_material" value="1" <?php echo fsw_checked($printer, 'multi_material'); ?>> AMS/MMU support</label>
                </td>
            </tr>
            <tr>
                <th>Spool Sensors</th>
                <td>
                    <label><input type="checkbox" name="spool_sensors" value="1" <?php echo fsw_checked($printer, 'spool_sensors'); ?>> Filament usage monitoring</label>
                </td>
            </tr>
            <tr>
                <th>Power Loss Recovery</th>
                <td>
                    <label><input type="checkbox" name="power_loss_recovery" value="1" <?php echo fsw_checked($printer, 'power_loss_recovery'); ?>> Resume after power loss</label>
                </td>
            </tr>
            <tr>
                <th>Filament Sensor</th>
                <td>
                    <label><input type="checkbox" name="filament_sensor" value="1" <?php echo fsw_checked($printer, 'filament_sensor'); ?>> Dedicated filament runout sensor</label>
                </td>
            </tr>
        </table>
        
        <p>
            <button type="submit" name="<?php echo $editing ? 'edit_printer' : 'add_printer'; ?>" class="button button-primary">
                <?php echo $editing ? 'Update Printer' : 'Add Printer'; ?>
            </button>
            <?php if ($editing): ?>
                <a href="?page=filament-settings-printers" class="button">Cancel Edit</a>
            <?php endif; ?>
        </p>
    </form>
    
    <h2>Existing Printers</h2>
    <table class="fsw-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Maker</th>
                <th>Model</th>
                <th>Build Volume</th>
                <th>Enclosure</th>
                <th>Heated</th>
                <th>Auto Level</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printers as $p): ?>
            <tr>
                <td><?php echo $p->id; ?></td>
                <td><?php echo esc_html($p->maker); ?></td>
                <td><?php echo esc_html($p->model); ?></td>
                <td>
                    <?php if ($p->build_volume_x_mm && $p->build_volume_y_mm && $p->build_volume_z_mm): ?>
                        <?php echo $p->build_volume_x_mm; ?> × <?php echo $p->build_volume_y_mm; ?> × <?php echo $p->build_volume_z_mm; ?> mm
                    <?php else: ?>
                        <em>Not specified</em>
                    <?php endif; ?>
                </td>
                <td><?php echo $p->enclosure ? 'Yes' : 'No'; ?></td>
                <td><?php echo $p->heated_enclosure ? 'Yes' : 'No'; ?></td>
                <td><?php echo esc_html($p->autolevel_type); ?></td>
                <td>
                    <a href="?page=filament-settings-printers&action=edit&id=<?php echo $p->id; ?>" 
                       class="button button-small">Edit</a>
                    <a href="?page=filament-settings-printers&action=delete&id=<?php echo $p->id; ?>&nonce=<?php echo wp_create_nonce('fsw_delete_printer_' . $p->id); ?>" 
                       onclick="return confirm('Delete this printer?')" class="button button-small">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
// Helper function to collect and sanitize all form fields
function collect_form_data() {
    return [
        'maker' => sanitize_text_field($_POST['maker'] ?? ''),
        'model' => sanitize_text_field($_POST['model'] ?? ''),
        'extruder_type' => sanitize_text_field($_POST['extruder_type'] ?? ''),
        'hotend_type' => sanitize_text_field($_POST['hotend_type'] ?? ''),
        'max_hotend_temp_c' => intval($_POST['max_hotend_temp_c'] ?? 0),
        'nozzle_count' => intval($_POST['nozzle_count'] ?? 0),
        'mixing_extruder' => isset($_POST['mixing_extruder']) ? 1 : 0,
        'fast_hotend' => isset($_POST['fast_hotend']) ? 1 : 0,
        'build_volume_x_mm' => floatval($_POST['build_volume_x_mm'] ?? 0) ?: null,
        'build_volume_y_mm' => floatval($_POST['build_volume_y_mm'] ?? 0) ?: null,
        'build_volume_z_mm' => floatval($_POST['build_volume_z_mm'] ?? 0) ?: null,
        'enclosure' => isset($_POST['enclosure']) ? 1 : 0,
        'heated_enclosure' => isset($_POST['heated_enclosure']) ? 1 : 0,
        'enclosure_temp_max_c' => intval($_POST['enclosure_temp_max_c'] ?? 0) ?: null,
        'chamber_heated' => isset($_POST['chamber_heated']) ? 1 : 0,
        'max_bed_temp_c' => intval($_POST['max_bed_temp_c'] ?? 0),
        'autolevel_type' => sanitize_text_field($_POST['autolevel_type'] ?? ''),
        'autolevel_points' => intval($_POST['autolevel_points'] ?? 0) ?: null,
        'build_surface_type' => sanitize_text_field($_POST['build_surface_type'] ?? ''),
        'build_surface_removable' => isset($_POST['build_surface_removable']) ? 1 : 0,
        'frame_type' => sanitize_text_field($_POST['frame_type'] ?? ''),
        'travel_speed_mm_s' => intval($_POST['travel_speed_mm_s'] ?? 0) ?: null,
        'linear_rail_xyz' => sanitize_text_field($_POST['linear_rail_xyz'] ?? ''),
        'belt_drive' => isset($_POST['belt_drive']) ? 1 : 0,
        'display_type' => sanitize_text_field($_POST['display_type'] ?? ''),
        'tft_display' => isset($_POST['tft_display']) ? 1 : 0,
        'wifi_enabled' => isset($_POST['wifi_enabled']) ? 1 : 0,
        'ethernet_enabled' => isset($_POST['ethernet_enabled']) ? 1 : 0,
        'usb_media' => isset($_POST['usb_media']) ? 1 : 0,
        'pressure_advance' => isset($_POST['pressure_advance']) ? 1 : 0,
        'input_shaping' => isset($_POST['input_shaping']) ? 1 : 0,
        'multi_material' => isset($_POST['multi_material']) ? 1 : 0,
        'spool_sensors' => isset($_POST['spool_sensors']) ? 1 : 0,
        'power_loss_recovery' => isset($_POST['power_loss_recovery']) ? 1 : 0,
        'filament_sensor' => isset($_POST['filament_sensor']) ? 1 : 0,
    ];
}
?>
