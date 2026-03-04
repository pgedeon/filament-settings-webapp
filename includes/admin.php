<?php
/**
 * Admin interface for Filament Settings Web App
 */

if (!defined('ABSPATH')) {
    exit;
}

class FSW_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_fsw_setting_action', [$this, 'ajax_setting_action']);
        add_action('wp_ajax_fsw_reset_votes', [$this, 'ajax_reset_votes']);
        add_action('wp_ajax_fsw_run_collector', [$this, 'ajax_run_collector']);
        add_action('wp_ajax_fsw_run_cleanup', [$this, 'ajax_run_cleanup']);
    }
    
    private function count_table($table, $where = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . $table;
        if ($where) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE $where"));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Filament Settings',
            'Filament Settings',
            'manage_options',
            'filament-settings',
            [$this, 'render_dashboard'],
            'dash-admin-generic',
            30
        );
        
        add_submenu_page(
            'filament-settings',
            'Printers',
            'Printers',
            'manage_options',
            'filament-settings-printers',
            [$this, 'render_printers']
        );
        
        add_submenu_page(
            'filament-settings',
            'Filaments',
            'Filaments',
            'manage_options',
            'filament-settings-filaments',
            [$this, 'render_filaments']
        );
        
        add_submenu_page(
            'filament-settings',
            'Settings',
            'Settings',
            'manage_options',
            'filament-settings-list',
            [$this, 'render_settings_list']
        );
        
        add_submenu_page(
            'filament-settings',
            'Sources',
            'Sources',
            'manage_options',
            'filament-settings-sources',
            [$this, 'render_sources']
        );
        
        add_submenu_page(
            'filament-settings',
            'Quality Review',
            'Quality Review',
            'manage_options',
            'filament-settings-quality',
            [$this, 'render_quality']
        );
        
        add_submenu_page(
            'filament-settings',
            'Collector',
            'Collector',
            'manage_options',
            'filament-settings-collector',
            [$this, 'render_collector']
        );
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'filament-settings') !== false) {
            wp_enqueue_style('fsw-admin', FSW_PLUGIN_URL . 'assets/admin.css');
            wp_enqueue_script('fsw-admin', FSW_PLUGIN_URL . 'assets/admin.js', ['jquery'], null, true);
        }
    }
    
    public function render_dashboard() {
        include FSW_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }
    
    public function render_printers() {
        include FSW_PLUGIN_DIR . 'templates/admin/printers.php';
    }
    
    public function render_filaments() {
        include FSW_PLUGIN_DIR . 'templates/admin/filaments.php';
    }
    
    public function render_sources() {
        include FSW_PLUGIN_DIR . 'templates/admin/sources.php';
    }
    
    public function render_quality() {
        include FSW_PLUGIN_DIR . 'templates/admin/quality.php';
    }
    
    public function render_settings_list() {
        include FSW_PLUGIN_DIR . 'templates/admin/settings.php';
    }
    
    public function render_collector() {
        // Get last 50 lines of collector log
        $log_file = plugin_dir_path(__FILE__) . '../logs/collector.log';
        $log_tail = 'No log file yet. Run the collector to generate logs.';
        if (file_exists($log_file)) {
            $lines = file($log_file, FILE_IGNORE_NEW_LINES);
            $lines = array_slice($lines, -50);
            $log_tail = implode("\n", $lines);
        }
        
        $last_run = 'Never';
        if (file_exists($log_file) && ($lines = file($log_file))) {
            foreach (array_reverse($lines) as $line) {
                if (strpos($line, 'Starting collector') !== false) {
                    $last_run = trim(substr($line, 1, 19)); // extract timestamp
                    break;
                }
            }
        }
        
        include FSW_PLUGIN_DIR . 'templates/admin/collector.php';
    }
    
    public function ajax_setting_action() {
        check_ajax_referer('fsw_settings_action');
        
        $setting_id = intval($_POST['setting_id']);
        $new_status = sanitize_text_field($_POST['status']);
        
        if (!in_array($new_status, ['active', 'superseded', 'rejected'])) {
            wp_die('Invalid status');
        }
        
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'fsw_settings',
            ['status' => $new_status],
            ['id' => $setting_id],
            ['%s'],
            ['%d']
        );
        
        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to update');
        }
    }
    
    public function ajax_reset_votes() {
        check_ajax_referer('fsw_settings_action');
        
        $setting_id = intval($_POST['setting_id']);
        
        if (!$setting_id) {
            wp_send_json_error('Invalid setting ID');
        }
        
        global $wpdb;
        $result = $wpdb->delete(
            $wpdb->prefix . 'fsw_setting_votes',
            ['setting_id' => $setting_id],
            ['%d']
        );
        
        if ($result !== false) {
            // Also reset confidence to original source confidence? No, keep as is.
            wp_send_json_success(['deleted' => $result]);
        } else {
            wp_send_json_error('Failed to delete votes');
        }
    }
    
    public function ajax_run_collector() {
        check_ajax_referer('fsw_run_collector');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        try {
            $collector = new FSW_Collector();
            $result = $collector->ajax_run_collector();
            if (isset($result['success']) && !$result['success']) {
                wp_send_json_error($result['message']);
            }
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_run_cleanup() {
        check_ajax_referer('fsw_run_cleanup');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        try {
            $collector = new FSW_Collector();
            // Capture output
            ob_start();
            $collector->cli_cleanup([], ['dry-run' => false]);
            $output = ob_get_clean();
            wp_send_json_success($output);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
