<?php
/**
 * Autonomous collector for filament settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class FSW_Collector {
    private $log_file;
    
    public function __construct() {
        $this->log_file = plugin_dir_path(__FILE__) . '../logs/collector.log';
        
        // Register WP-CLI commands if available
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('fsw collect', [$this, 'cli_collect']);
            WP_CLI::add_command('fsw cleanup', [$this, 'cli_cleanup']);
        }
    }
    
    /**
     * WP-CLI command entry point
     */
    public function cli_collect($args, $assoc_args) {
        $this->log("Starting collector run...");
        
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            $counts = $this->normalize_and_insert();
            if (!empty($wpdb->last_error)) {
                throw new Exception($wpdb->last_error);
            }
            $wpdb->query('COMMIT');
            $this->log("Collector completed. Inserted: {$counts['inserted']}, Updated: {$counts['updated']}, Skipped: {$counts['skipped']}");
            WP_CLI::success("Collector completed. Inserted: {$counts['inserted']}, Updated: {$counts['updated']}, Skipped: {$counts['skipped']}");
        } catch (Exception $e) {
            global $wpdb;
            $wpdb->query('ROLLBACK');
            $this->log("ERROR: " . $e->getMessage());
            WP_CLI::error($e->getMessage());
        }
    }
    
    /**
     * WP-CLI cleanup command: remove old rejected settings and orphaned records
     */


    /**
     * FIX #6: AJAX-triggered collection without CLI output
     */
    public function ajax_run_collector() {
        global $wpdb;
        $this->log("Starting AJAX collector run...");

        try {
            $wpdb->query('START TRANSACTION');
            $counts = $this->normalize_and_insert();
            if (!empty($wpdb->last_error)) {
                throw new Exception($wpdb->last_error);
            }
            $wpdb->query('COMMIT');

            $this->log("AJAX collector completed. Inserted: {$counts['inserted']}, Updated: {$counts['updated']}, Skipped: {$counts['skipped']}");
            return [
                'success' => true,
                'message' => 'Collector completed',
                'counts' => $counts
            ];
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->log("AJAX collector error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function cli_cleanup($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']) && $assoc_args['dry-run'];
        $days_old = isset($assoc_args['days']) ? intval($assoc_args['days']) : 30;
        
        $this->log("Starting cleanup (dry-run: " . ($dry_run ? 'yes' : 'no') . ", days: $days_old)...");
        WP_CLI::log("Starting cleanup...");
        
        global $wpdb;
        $settings_table = $wpdb->prefix . 'fsw_settings';
        $sources_table = $wpdb->prefix . 'fsw_sources';
        
        // Count rejected settings older than threshold
        $rejected_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $settings_table 
             WHERE status = 'rejected' 
             AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));
        
        WP_CLI::log("Found $rejected_count rejected settings older than $days_old days");
        
        if (!$dry_run && $rejected_count > 0) {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $settings_table 
                 WHERE status = 'rejected' 
                 AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_old
            ));
            WP_CLI::success("Deleted $deleted old rejected settings");
            $this->log("Deleted $deleted old rejected settings");
        } elseif ($dry_run) {
            WP_CLI::log("DRY RUN: Would delete $rejected_count old rejected settings");
            $this->log("DRY RUN: Would delete $rejected_count old rejected settings");
        }
        
        // Optional: Clean up orphaned settings (no matching product or printer records)
        // Skipped for now since we allow generic/generic settings without FK constraints
        
        WP_CLI::success("Cleanup completed");
        $this->log("Cleanup completed");
    }
    
    /**
     * Discover sources from configured feeds
     * (Now reads from database sources table where active=1)
     */
    private function discover_sources() {
        $this->log("Discovering sources...");
        // Sources are now managed via admin UI and stored in fsw_sources
        $sources = $this->get_active_sources();
        $this->log("Found " . count($sources) . " active sources to process");
        return $sources;
    }
    
    /**
     * Get active sources from database
     */
    private function get_active_sources() {
        global $wpdb;
        $table = $wpdb->prefix . 'fsw_sources';
        return $wpdb->get_results("SELECT * FROM $table WHERE active = 1", ARRAY_A);
    }
    
    /**
     * Fetch content and extract settings from sources
     */
    private function fetch_and_extract() {
        $this->log("Fetching content from sources...");
        global $wpdb;
        
        $sources = $this->discover_sources();
        $all_settings = [];
        
        foreach ($sources as $source) {
            $this->log("Processing source: {$source['publisher']} ({$source['url']})");
            
            // Fetch content
            $content = $this->fetch_url($source['url']);
            if (!$content) {
                $this->log("Failed to fetch {$source['url']}, skipping");
                continue;
            }
            
            // Extract settings based on source type
            $settings = $this->extract_settings($content, $source);
            
            foreach ($settings as $setting) {
                $setting['source_id'] = $source['id'];
                $setting['source_publisher'] = $source['publisher'];
                $setting['source_type'] = $source['source_type'];
                $all_settings[] = $setting;
            }
            
            $this->log("Extracted " . count($settings) . " settings from {$source['publisher']}");
        }
        
        $this->log("Total settings extracted: " . count($all_settings));
        return $all_settings;
    }
    
    /**
     * Fetch URL content with error handling
     */
    private function fetch_url($url) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'FilamentSettingsWebApp/1.0 (OpenClaw; +https://3dput.com/bot)'
        ]);
        
        if (is_wp_error($response)) {
            $this->log("HTTP error fetching $url: " . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log("HTTP $code for $url");
            return false;
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * Extract settings from HTML content based on source type
     */
    private function extract_settings($content, $source) {
        $type = $source['source_type'];
        
        if ($type === 'manufacturer' || $type === 'slicer_vendor' || $type === 'printer_oem') {
            return $this->extract_general_settings($content, $source);
        }
        
        // Unknown type fallback
        return $this->extract_general_settings($content, $source);
    }
    
    /**
     * General extraction: look for temperature numbers and other heuristics
     */
    private function extract_general_settings($content, $source) {
        $settings = [];
        $dom = new DOMDocument();
        
        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);
        @$dom->loadHTML($content);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Find temperature mentions
        $temp_nodes = $xpath->query("//*[contains(text(), 'temp') or contains(text(), 'Temp') or contains(text(), 'Temperature')]");
        foreach ($temp_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match_all('/(\d+)\s*°?C/', $text, $m)) {
                // Use first two matches as bed and nozzle temps (common pattern)
                $bed_temp = isset($m[1][0]) ? (int)$m[1][0] : null;
                $nozzle_temp = isset($m[1][1]) ? (int)$m[1][1] : (isset($m[1][0]) ? (int)$m[1][0] : null);
                
                $settings[] = [
                    'printer_name' => 'Generic',
                    'filament_type' => $this->detect_filament_type($content, $source['publisher']),
                    'nozzle_size' => 0.4,
                    'bed_temp' => $bed_temp,
                    'nozzle_temp' => $nozzle_temp,
                    'print_speed' => null,
                    'layer_height' => null,
                    'cooling' => null,
                    'retraction_distance' => null,
                    'retraction_speed' => null,
                    'raw_text' => $text,
                    'confidence' => 0.7
                ];
            }
        }
        
        // If no extractions, return a default setting so the source is represented
        if (empty($settings)) {
            $settings[] = [
                'printer_name' => 'Generic',
                'filament_type' => 'PLA',
                'nozzle_size' => 0.4,
                'bed_temp' => 60,
                'nozzle_temp' => 200,
                'print_speed' => 50,
                'layer_height' => 0.2,
                'cooling' => 100,
                'retraction_distance' => 6,
                'retraction_speed' => 40,
                'raw_text' => 'Default settings (no extraction)',
                'confidence' => 0.3
            ];
        }
        
        return $settings;
    }
    
    /**
     * Detect filament type from content/publisher
     */
    private function detect_filament_type($content, $publisher) {
        $content_lower = strtolower($content);
        $publisher_lower = strtolower($publisher);
        
        if (strpos($content_lower, 'pla') !== false || strpos($publisher_lower, 'pla') !== false) {
            return 'PLA';
        }
        if (strpos($content_lower, 'petg') !== false || strpos($publisher_lower, 'petg') !== false) {
            return 'PETG';
        }
        if (strpos($content_lower, 'abs') !== false || strpos($publisher_lower, 'abs') !== false) {
            return 'ABS';
        }
        if (strpos($content_lower, 'tpu') !== false || strpos($publisher_lower, 'tpu') !== false) {
            return 'TPU';
        }
        if (strpos($content_lower, 'asa') !== false || strpos($publisher_lower, 'asa') !== false) {
            return 'ASA';
        }
        if (strpos($content_lower, 'nylon') !== false || strpos($publisher_lower, 'nylon') !== false) {
            return 'Nylon';
        }
        
        return 'PLA';
    }
    
    /**
     * Main normalization and database insertion
     */
    private function normalize_and_insert() {
        $this->log("Normalizing and inserting settings...");
        global $wpdb;
        
        $settings_table = $wpdb->prefix . 'fsw_settings';
        $sources = $this->get_active_sources();
        
        // Build source priority map (lower number = higher priority)
        $source_priority = [];
        foreach ($sources as $src) {
            $source_priority[$src['id']] = $src['priority'] ?? 10;
        }
        
        $extracted = $this->fetch_and_extract();
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        
        foreach ($extracted as $item) {
            // Normalize fields
            $normalized = $this->normalize_setting($item);
            
            // Build settings_json from normalized values
            $settings_json = json_encode([
                'nozzle_temp_c' => $normalized['nozzle_temp'],
                'bed_temp_c' => $normalized['bed_temp'],
                'print_speed_mm_s' => $normalized['print_speed'],
                'layer_height_mm' => $normalized['layer_height'],
                'cooling_percent' => $normalized['cooling'],
                'retraction_distance_mm' => $normalized['retraction_distance'],
                'retraction_speed_mm_s' => $normalized['retraction_speed']
            ], JSON_PRETTY_PRINT);
            
            // Compute fingerprint: SHA256 of combined settings to uniquely identify this profile
            $fp_string = implode('|', [
                $normalized['printer_name'] ?: 'any',
                $normalized['filament_type'] ?: 'any',
                number_format($normalized['nozzle_size'] ?? 0.4, 1),
                $normalized['bed_temp'] ?? 'any',
                $normalized['nozzle_temp'] ?? 'any',
                $normalized['print_speed'] ?? 'any',
                number_format($normalized['layer_height'] ?? 0, 2),
                $normalized['cooling'] ?? 'any',
                number_format($normalized['retraction_distance'] ?? 0, 1),
                $normalized['retraction_speed'] ?? 'any'
            ]);
            $fingerprint = hash('sha256', $fp_string);
            
            // Check for existing entry with same fingerprint
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, source_id FROM $settings_table WHERE fingerprint = %s",
                $fingerprint
            ));
            
            if ($existing) {
                $existing_priority = $source_priority[$existing->source_id] ?? 10;
                $new_priority = $source_priority[$item['source_id']] ?? 10;
                
                if ($new_priority < $existing_priority) {
                    // Higher priority source: update existing record
                    $wpdb->update(
                        $settings_table,
                        [
                            'filament_type' => $normalized['filament_type'],
                            'nozzle_diameter_mm' => $normalized['nozzle_size'],
                            'settings_json' => $settings_json,
                            'source_id' => $item['source_id'],
                            'source_priority' => $new_priority,
                            'confidence' => $normalized['confidence'],
                            'fingerprint' => $fingerprint,
                            'status' => 'active',
                            'updated_at' => current_time('mysql')
                        ],
                        ['id' => $existing->id]
                    );
                    $updated++;
                    $this->log("Updated ID {$existing->id} with higher priority source");
                } else {
                    $skipped++;
                    $this->log("Skipped duplicate ID {$existing->id} (existing source priority higher)");
                }
                continue;
            }
            
            // Insert new record
            $wpdb->insert(
                $settings_table,
                [
                    'filament_product_id' => 0,
                    'filament_type' => $normalized['filament_type'],
                    'printer_id' => 0,
                    'printer_scope' => 'generic',
                    'nozzle_diameter_mm' => $normalized['nozzle_size'],
                    'environment' => 'open',
                    'settings_json' => $settings_json,
                    'source_id' => $item['source_id'],
                    'source_priority' => $source_priority[$item['source_id']] ?? 10,
                    'confidence' => $normalized['confidence'],
                    'fingerprint' => $fingerprint,
                    'status' => 'active',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]
            );
            
            $inserted++;
            $this->log("Inserted new setting ID " . $wpdb->insert_id);
        }
        
        $this->log("Insert: $inserted, Update: $updated, Skip: $skipped");
        $cleanup = $this->cleanup_bad_data();
        $this->log("Cleanup: deleted {$cleanup['sources_deleted']} sources, fixed {$cleanup['printers_fixed']} printers");
        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
    }
    
    /**
     * Normalize a single setting item
     */
    private function normalize_setting($item) {
        return [
            'printer_name' => $this->normalize_string($item['printer_name'] ?? ''),
            'filament_type' => $this->normalize_filament_type($item['filament_type'] ?? ''),
            'nozzle_size' => $this->normalize_float($item['nozzle_size'] ?? 0.4),
            'bed_temp' => $this->normalize_int($item['bed_temp'] ?? null),
            'nozzle_temp' => $this->normalize_int($item['nozzle_temp'] ?? null),
            'print_speed' => $this->normalize_int($item['print_speed'] ?? null),
            'layer_height' => $this->normalize_float($item['layer_height'] ?? null),
            'cooling' => $this->normalize_int($item['cooling'] ?? null),
            'retraction_distance' => $this->normalize_float($item['retraction_distance'] ?? null),
            'retraction_speed' => $this->normalize_int($item['retraction_speed'] ?? null),
            'confidence' => $this->normalize_float($item['confidence'] ?? 0.5),
            'raw_text' => $this->normalize_string($item['raw_text'] ?? '')
        ];
    }
    
    private function normalize_string($val) {
        return trim(substr($val, 0, 500));
    }
    
    private function normalize_filament_type($val) {
        $map = [
            'pla' => 'PLA',
            'petg' => 'PETG',
            'abs' => 'ABS',
            'tpu' => 'TPU',
            'asa' => 'ASA',
            'nylon' => 'Nylon',
            'pc' => 'Polycarbonate',
            'hips' => 'HIPS',
            'pp' => 'PP'
        ];
        $lower = strtolower($val);
        return $map[$lower] ?? ucfirst($lower) ?: 'PLA';
    }
    
    private function normalize_int($val) {
        if ($val === null) return null;
        $int = intval($val);
        return $int > 0 ? $int : null;
    }
    
    private function normalize_float($val) {
        if ($val === null) return null;
        $float = floatval($val);
        return $float > 0 ? $float : null;
    }

    private function cleanup_bad_data() {
        global $wpdb;
        $printers_table = $wpdb->prefix . 'fsw_printers';
        $sources_table = $wpdb->prefix . 'fsw_sources';

        $deleted_sources = $wpdb->query($wpdb->prepare(
            "DELETE FROM $sources_table WHERE LOWER(url) LIKE %s OR LOWER(publisher) LIKE %s",
            '%3dput.com%', '%3dput%'
        ));
        if (!empty($wpdb->last_error)) {
            $this->log("Cleanup error deleting sources: {$wpdb->last_error}");
        }

        $known_brands = ['Anycubic', 'Bambu Lab', 'Creality', 'Prusa', 'Elegoo', 'QIDI', 'Artillery'];
        $updated_printers = 0;

        $unknown = $wpdb->get_results("
            SELECT id, model FROM $printers_table
            WHERE LOWER(maker) = 'unknown' OR maker IS NULL OR maker = ''
        ");

        if (!empty($wpdb->last_error)) {
            $this->log("Cleanup error fetching unknown makers: {$wpdb->last_error}");
            return [
                'sources_deleted' => $deleted_sources,
                'printers_fixed' => 0
            ];
        }

        foreach ($unknown as $printer) {
            $model = $printer->model;
            foreach ($known_brands as $brand) {
                if (stripos($model, $brand) === 0) {
                    $updated = $wpdb->query($wpdb->prepare(
                        "UPDATE $printers_table SET maker = %s WHERE id = %d",
                        $brand, $printer->id
                    ));
                    if ($updated !== false) {
                        $updated_printers++;
                    } else {
                        $this->log("Cleanup error updating printer {$printer->id}: {$wpdb->last_error}");
                    }
                    break;
                }
            }
        }

        return [
            'sources_deleted' => $deleted_sources,
            'printers_fixed' => $updated_printers
        ];
    }
    
    /**
     * Log message to file
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message\n";
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }
}
