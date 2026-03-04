<?php
/**
 * Database handling for Filament Settings Web App
 */

if (!defined('ABSPATH')) {
    exit;
}

class FSW_Database {
    const VERSION = '1.1';
    
    /**
     * Create all required tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Run any pending migrations first
        self::migrate();
        
        // Printers table (enhanced)
        $printers_table = $wpdb->prefix . 'fsw_printers';
        $printers_sql = "CREATE TABLE IF NOT EXISTS $printers_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            maker VARCHAR(100) NOT NULL,
            model VARCHAR(200) NOT NULL,
            -- Extruder/Hotend
            extruder_type VARCHAR(50) DEFAULT 'bowden',
            hotend_type VARCHAR(50) DEFAULT 'bowden',
            max_hotend_temp_c INT UNSIGNED DEFAULT 300,
            nozzle_count TINYINT UNSIGNED DEFAULT 1,
            mixing_extruder TINYINT(1) DEFAULT 0,
            fast_hotend TINYINT(1) DEFAULT 0,
            -- Build Volume
            build_volume_x_mm DECIMAL(8,2) NULL,
            build_volume_y_mm DECIMAL(8,2) NULL,
            build_volume_z_mm DECIMAL(8,2) NULL,
            -- Enclosure
            enclosure TINYINT(1) DEFAULT 0,
            heated_enclosure TINYINT(1) DEFAULT 0,
            enclosure_temp_max_c INT NULL,
            chamber_heated TINYINT(1) DEFAULT 0,
            -- Bed & Leveling
            max_bed_temp_c INT UNSIGNED DEFAULT 120,
            autolevel_type ENUM('none', 'blob', 'mesh', 'bed_visualizer', 'capacitive', 'inductive', 'other') DEFAULT 'none',
            autolevel_points INT NULL,
            build_surface_type ENUM('glass', 'pei', 'peek', 'g10', 'buildtak', 'pcb', 'other') DEFAULT 'other',
            build_surface_removable TINYINT(1) DEFAULT 0,
            -- Motion & Frame
            frame_type ENUM('open', 'enclosed', 'cubic', 'delta', 'corexy', 'coredxy', 'hbot', 'other') DEFAULT 'open',
            travel_speed_mm_s INT UNSIGNED NULL,
            linear_rail_xyz VARCHAR(20) NULL,
            belt_drive TINYINT(1) DEFAULT 1,
            -- Connectivity & Display
            display_type ENUM('lcd_12864', 'lcd_320240', 'touchscreen', 'none', 'smartphone') DEFAULT 'lcd_12864',
            tft_display TINYINT(1) DEFAULT 0,
            wifi_enabled TINYINT(1) DEFAULT 0,
            ethernet_enabled TINYINT(1) DEFAULT 0,
            usb_media TINYINT(1) DEFAULT 1,
            -- Advanced Features
            pressure_advance TINYINT(1) DEFAULT 0,
            input_shaping TINYINT(1) DEFAULT 0,
            multi_material TINYINT(1) DEFAULT 0,
            spool_sensors TINYINT(1) DEFAULT 0,
            power_loss_recovery TINYINT(1) DEFAULT 0,
            filament_sensor TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY maker (maker),
            KEY model (model),
            KEY enclosure (enclosure),
            KEY heated_enclosure (heated_enclosure),
            KEY autolevel_type (autolevel_type)
        ) $charset_collate;";
        
        // Filament Products table
        $products_table = $wpdb->prefix . 'fsw_filament_products';
        $products_sql = "CREATE TABLE IF NOT EXISTS $products_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            filament_type VARCHAR(50) NOT NULL,
            brand VARCHAR(100) NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            diameter_mm DECIMAL(4,2) DEFAULT 1.75,
            manufacturer_url VARCHAR(500),
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY filament_type (filament_type),
            KEY brand (brand)
        ) $charset_collate;";
        
        // Sources table
        $sources_table = $wpdb->prefix . 'fsw_sources';
        $sources_sql = "CREATE TABLE IF NOT EXISTS $sources_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type ENUM('manufacturer', 'printer_oem', 'slicer_vendor', 'community', 'unknown') DEFAULT 'unknown',
            publisher VARCHAR(100),
            url VARCHAR(500) NOT NULL,
            priority TINYINT UNSIGNED DEFAULT 10,
            active TINYINT(1) DEFAULT 1,
            retrieved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            hash_content VARCHAR(64),
            PRIMARY KEY (id),
            UNIQUE KEY url_hash (url, hash_content),
            KEY source_type (source_type),
            KEY active (active)
        ) $charset_collate;";
        
        // Settings table
        $settings_table = $wpdb->prefix . 'fsw_settings';
        $settings_sql = "CREATE TABLE IF NOT EXISTS $settings_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            filament_product_id BIGINT(20) UNSIGNED NOT NULL,
            filament_type VARCHAR(50) NOT NULL,
            printer_id BIGINT(20) UNSIGNED NOT NULL,
            printer_scope ENUM('exact_model', 'maker_family', 'generic') DEFAULT 'generic',
            nozzle_diameter_mm DECIMAL(4,2) DEFAULT 0.40,
            environment ENUM('open', 'enclosed') DEFAULT 'open',
            settings_json LONGTEXT NOT NULL,
            source_id BIGINT(20) UNSIGNED NOT NULL,
            source_priority TINYINT UNSIGNED DEFAULT 5,
            confidence FLOAT DEFAULT 1.0,
            fingerprint CHAR(64) NOT NULL,
            status ENUM('active', 'superseded', 'rejected') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY fingerprint (fingerprint),
            KEY filament_product (filament_product_id),
            KEY printer (printer_id),
            KEY source_priority (source_priority),
            KEY status (status)
        ) $charset_collate;";
        
        // Setting Votes table
        $votes_table = $wpdb->prefix . 'fsw_setting_votes';
        $votes_sql = "CREATE TABLE IF NOT EXISTS $votes_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            voter_ip VARCHAR(45) NULL,
            vote TINYINT NOT NULL COMMENT '1 = up, -1 = down',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_setting (setting_id, user_id),
            UNIQUE KEY unique_ip_setting (setting_id, voter_ip),
            KEY setting_id (setting_id),
            KEY user_id (user_id),
            KEY voter_ip (voter_ip),
            FOREIGN KEY (setting_id) REFERENCES {$wpdb->prefix}fsw_settings(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Add foreign keys (optional, for integrity)
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($printers_sql);
        dbDelta($products_sql);
        dbDelta($sources_sql);
        dbDelta($settings_sql);
        dbDelta($votes_sql);
        
        // Store version
        add_option('fsw_db_version', self::VERSION);
    }
    
    /**
     * Migrate database from older versions to current
     */
    private static function migrate() {
        global $wpdb;
        $current_version = get_option('fsw_db_version', '1.0');
        
        if (version_compare($current_version, self::VERSION, '>=')) {
            return; // Already up to date
        }
        
        $printers_table = $wpdb->prefix . 'fsw_printers';
        
        // Migration from 1.0 to 1.1: add new printer fields
        if (version_compare($current_version, '1.1', '<')) {
            $columns_to_add = [
                'hotend_type' => "ALTER TABLE $printers_table ADD COLUMN hotend_type VARCHAR(50) DEFAULT 'bowden' AFTER extruder_type",
                'max_hotend_temp_c' => "ALTER TABLE $printers_table ADD COLUMN max_hotend_temp_c INT UNSIGNED DEFAULT 300 AFTER hotend_type",
                'nozzle_count' => "ALTER TABLE $printers_table ADD COLUMN nozzle_count TINYINT UNSIGNED DEFAULT 1 AFTER max_hotend_temp_c",
                'mixing_extruder' => "ALTER TABLE $printers_table ADD COLUMN mixing_extruder TINYINT(1) DEFAULT 0 AFTER nozzle_count",
                'fast_hotend' => "ALTER TABLE $printers_table ADD COLUMN fast_hotend TINYINT(1) DEFAULT 0 AFTER mixing_extruder",
                'build_volume_x_mm' => "ALTER TABLE $printers_table ADD COLUMN build_volume_x_mm DECIMAL(8,2) AFTER mixing_extruder",
                'build_volume_y_mm' => "ALTER TABLE $printers_table ADD COLUMN build_volume_y_mm DECIMAL(8,2) AFTER build_volume_x_mm",
                'build_volume_z_mm' => "ALTER TABLE $printers_table ADD COLUMN build_volume_z_mm DECIMAL(8,2) AFTER build_volume_y_mm",
                'heated_enclosure' => "ALTER TABLE $printers_table ADD COLUMN heated_enclosure TINYINT(1) DEFAULT 0 AFTER enclosure",
                'enclosure_temp_max_c' => "ALTER TABLE $printers_table ADD COLUMN enclosure_temp_max_c INT AFTER heated_enclosure",
                'chamber_heated' => "ALTER TABLE $printers_table ADD COLUMN chamber_heated TINYINT(1) DEFAULT 0 AFTER enclosure_temp_max_c",
                'autolevel_type' => "ALTER TABLE $printers_table ADD COLUMN autolevel_type ENUM('none','blob','mesh','bed_visualizer','capacitive','inductive','other') DEFAULT 'none' AFTER max_bed_temp_c",
                'autolevel_points' => "ALTER TABLE $printers_table ADD COLUMN autolevel_points INT AFTER autolevel_type",
                'build_surface_type' => "ALTER TABLE $printers_table ADD COLUMN build_surface_type ENUM('glass','pei','peek','g10','buildtak','pcb','other') DEFAULT 'other' AFTER autolevel_points",
                'build_surface_removable' => "ALTER TABLE $printers_table ADD COLUMN build_surface_removable TINYINT(1) DEFAULT 0 AFTER build_surface_type",
                'travel_speed_mm_s' => "ALTER TABLE $printers_table ADD COLUMN travel_speed_mm_s INT UNSIGNED AFTER belt_drive",
                'linear_rail_xyz' => "ALTER TABLE $printers_table ADD COLUMN linear_rail_xyz VARCHAR(20) AFTER travel_speed_mm_s",
                'display_type' => "ALTER TABLE $printers_table ADD COLUMN display_type ENUM('lcd_12864','lcd_320240','touchscreen','none','smartphone') DEFAULT 'lcd_12864' AFTER usb_media",
                'tft_display' => "ALTER TABLE $printers_table ADD COLUMN tft_display TINYINT(1) DEFAULT 0 AFTER display_type",
                'wifi_enabled' => "ALTER TABLE $printers_table ADD COLUMN wifi_enabled TINYINT(1) DEFAULT 0 AFTER tft_display",
                'ethernet_enabled' => "ALTER TABLE $printers_table ADD COLUMN ethernet_enabled TINYINT(1) DEFAULT 0 AFTER wifi_enabled",
                'pressure_advance' => "ALTER TABLE $printers_table ADD COLUMN pressure_advance TINYINT(1) DEFAULT 0 AFTER power_loss_recovery",
                'input_shaping' => "ALTER TABLE $printers_table ADD COLUMN input_shaping TINYINT(1) DEFAULT 0 AFTER pressure_advance",
                'multi_material' => "ALTER TABLE $printers_table ADD COLUMN multi_material TINYINT(1) DEFAULT 0 AFTER input_shaping",
                'spool_sensors' => "ALTER TABLE $printers_table ADD COLUMN spool_sensors TINYINT(1) DEFAULT 0 AFTER multi_material",
                'filament_sensor' => "ALTER TABLE $printers_table ADD COLUMN filament_sensor TINYINT(1) DEFAULT 0 AFTER spool_sensors",
            ];
            
            $wpdb->query('START TRANSACTION');
            $has_error = false;
            foreach ($columns_to_add as $col_name => $sql) {
                // Check if column exists first
                $exists = $wpdb->get_var("SHOW COLUMNS FROM $printers_table LIKE '$col_name'");
                if (!$exists) {
                    $result = $wpdb->query($sql);
                    if ($result === false) {
                        $has_error = true;
                        break;
                    }
                }

            if ($has_error) {
                $wpdb->query('ROLLBACK');
                error_log('FSW Migration failed: rolled back column changes');
                return;
            }
            }
            
            // Add some keys for new columns
            $wpdb->query("ALTER TABLE $printers_table ADD INDEX idx_enclosure (enclosure)");
            $wpdb->query("ALTER TABLE $printers_table ADD INDEX idx_heated_enclosure (heated_enclosure)");
            $wpdb->query("ALTER TABLE $printers_table ADD INDEX idx_autolevel_type (autolevel_type)");
            
            // Update version
            $wpdb->query('COMMIT');
            update_option('fsw_db_version', '1.1');
        }
        
        // Future migrations go here in version blocks
    }
    
    /**
     * Insert sample data for testing
     */
    public static function insert_sample_data() {
        global $wpdb;
        
        // Check if already has data
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fsw_printers");
        if ($existing > 0) {
            return; // Skip if data exists
        }
        
        // Sample printer: Ender 3 V2
        $wpdb->insert(
            $wpdb->prefix . 'fsw_printers',
            [
                'maker' => 'Creality',
                'model' => 'Ender 3 V2',
                'extruder_type' => 'bowden',
                'max_hotend_temp_c' => 260,
                'max_bed_temp_c' => 110,
                'enclosure' => 0
            ]
        );
        $printer_id = $wpdb->insert_id;
        
        // Sample filament: PLA
        $wpdb->insert(
            $wpdb->prefix . 'fsw_filament_products',
            [
                'filament_type' => 'PLA',
                'brand' => 'Hatchbox',
                'product_name' => 'PLA 1.75mm',
                'diameter_mm' => 1.75,
                'manufacturer_url' => 'https://www.hatchbox.com',
                'notes' => 'Good for beginners, low warping'
            ]
        );
        $product_id = $wpdb->insert_id;
        
        // Sample source
        $wpdb->insert(
            $wpdb->prefix . 'fsw_sources',
            [
                'source_type' => 'manufacturer',
                'publisher' => 'Hatchbox',
                'url' => 'https://www.hatchbox.com/pla-settings',
                'hash_content' => sha1('sample-hatchbox-pla-settings')
            ]
        );
        $source_id = $wpdb->insert_id;
        
        // Sample settings
        $settings = [
            'temps' => [
                'nozzle_c' => ['min' => 190, 'max' => 220, 'recommended' => 210],
                'bed_c' => ['min' => 50, 'max' => 60, 'recommended' => 55]
            ],
            'cooling' => [
                'fan_percent' => ['min' => 50, 'max' => 100, 'recommended' => 100]
            ],
            'speed' => [
                'outer_wall_mm_s' => 35,
                'infill_mm_s' => 80
            ],
            'notes' => [
                'drying' => 'Dry if stringing occurs',
                'warnings' => ['Avoid overheating']
            ]
        ];
        
        $fingerprint = hash('sha256', implode('|', [
            $product_id,
            $printer_id,
            0.40,
            'open',
            json_encode($settings)
        ]));
        
        $wpdb->insert(
            $wpdb->prefix . 'fsw_settings',
            [
                'filament_product_id' => $product_id,
                'filament_type' => 'PLA',
                'printer_id' => $printer_id,
                'printer_scope' => 'exact_model',
                'nozzle_diameter_mm' => 0.40,
                'environment' => 'open',
                'settings_json' => json_encode($settings, JSON_PRETTY_PRINT),
                'source_id' => $source_id,
                'source_priority' => 1,
                'confidence' => 0.9,
                'fingerprint' => $fingerprint,
                'status' => 'active'
            ]
        );
    }
}
