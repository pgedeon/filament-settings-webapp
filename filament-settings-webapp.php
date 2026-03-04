<?php
/**
 * Plugin Name: Filament Settings Web App
 * Plugin URI:  https://3dput.com/
 * Description: Store and serve 3D printing filament settings with autonomous collection from manufacturer and community sources.
 * Version:     1.0.0
 * Author:       OpenClaw
 * License:     GPL v2 or later
 * Text Domain: filament-settings-webapp
 *
 * @package Filament_Settings_Web_App
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FSW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FSW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FSW_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('FSW_VERSION', '1.0.0');

// Activation/deactivation hooks - MUST be at top level
register_activation_hook(__FILE__, ['Filament_Settings_Web_App', 'activate']);
register_deactivation_hook(__FILE__, ['Filament_Settings_Web_App', 'deactivate']);

// Autoloader for includes (simple manual loader)
require_once FSW_PLUGIN_DIR . 'includes/database.php';
require_once FSW_PLUGIN_DIR . 'includes/rest-api.php';
require_once FSW_PLUGIN_DIR . 'includes/admin.php';
require_once FSW_PLUGIN_DIR . 'includes/frontend.php';
require_once FSW_PLUGIN_DIR . 'includes/collector.php';

class Filament_Settings_Web_App {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize components
        $this->init_database();
        $this->init_rest_api();
        $this->init_admin();
        $this->init_frontend();
        $this->init_collector();
    }

    public static function activate() {
        $db = new FSW_Database();
        $db->create_tables();
        // Schedule collector cron if needed
        if (!wp_next_scheduled('fsw_daily_collector')) {
            wp_schedule_event(time(), 'daily', 'fsw_daily_collector');
        }
    }

    public static function deactivate() {
        // Clean up cron
        wp_clear_scheduled_hook('fsw_daily_collector');
    }

    private function init_database() {
        new FSW_Database();
    }

    private function init_rest_api() {
        new FSW_REST_API();
    }

    private function init_admin() {
        new FSW_Admin();
    }

    private function init_frontend() {
        new FSW_Frontend();
    }

    private function init_collector() {
        // Hook collector into daily cron
        add_action('fsw_daily_collector', function() {
            $collector = new FSW_Collector();
            $collector->run_collection();
        });
    }
}

// Initialize the plugin
function fsw_init() {
    $instance = Filament_Settings_Web_App::get_instance();
    do_action('fsw_initialized');
    return $instance;
}

// Kick it off
add_action('plugins_loaded', 'fsw_init');

// Fallback manual initialization if the class system fails
add_action('plugins_loaded', 'fsw_fallback_init', 20);
function fsw_fallback_init() {
    if (did_action('fsw_initialized')) {
        return;
    }
    // Initialize Database
    if (class_exists('FSW_Database')) {
        global $fsw_db;
        $fsw_db = new FSW_Database();
    }
    // Initialize REST API
    if (class_exists('FSW_REST_API')) {
        global $fsw_rest;
        $fsw_rest = new FSW_REST_API();
        add_action('rest_api_init', [$fsw_rest, 'register_routes']);
    }
    // Initialize Frontend (registers shortcodes)
    if (class_exists('FSW_Frontend')) {
        global $fsw_frontend;
        $fsw_frontend = new FSW_Frontend();
    }
    // Initialize Admin if in admin area
    if (is_admin() && class_exists('FSW_Admin')) {
        global $fsw_admin;
        $fsw_admin = new FSW_Admin();
    }
    do_action('fsw_initialized');
}
