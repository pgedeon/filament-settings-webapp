<?php
/**
 * REST API for Filament Settings Web App
 */

if (!defined('ABSPATH')) {
    exit;
}

class FSW_REST_API {
    const NAMESPACE = 'fsw/v1';
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes() {
        // GET /selectors (public)
        register_rest_route(self::NAMESPACE, '/selectors', [
            'methods' => 'GET',
            'callback' => [$this, 'get_selectors'],
            'permission_callback' => '__return_true'
        ]);
        
        // GET /printers/search (public) - lightweight search for typeahead
        register_rest_route(self::NAMESPACE, '/printers/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search_printers'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => [
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'limit' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0 && intval($param) <= 200;
                    }
                ],
                'enclosure' => [
                    'validate_callback' => function($param) {
                        return in_array($param, ['0', '1', 0, 1], true);
                    }
                ],
                'frame_type' => [
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // GET /printers/compare (public) - bulk fetch full specs
        register_rest_route(self::NAMESPACE, '/printers/compare', [
            'methods' => 'GET',
            'callback' => [$this, 'compare_printers'],
            'permission_callback' => '__return_true',
            'args' => [
                'ids' => [
                    'validate_callback' => function($param) {
                        if (!$param) return false;
                        $ids = explode(',', $param);
                        foreach ($ids as $id) {
                            if (!is_numeric($id) || intval($id) <= 0) {
                                return false;
                            }
                        }
                        return true;
                    }
                ]
            ]
        ]);
        
        // GET /settings (public)
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_settings'],
            'permission_callback' => '__return_true',
            'args' => [
                'printer_id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0;
                    }
                ],
                'filament_type' => [
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'filament_product_id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0;
                    }
                ],
                // FIX #2: Pagination parameters
                'limit' => [
                    'default' => 20,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0 && intval($param) <= 100;
                    }
                ],
                'offset' => [
                    'default' => 0,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) >= 0;
                    }
                ],
            ]
        ]);
        
        // POST /settings (batch insert - admin only)
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'create_setting'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        // GET /settings/(?P<id>\d+)/votes (public)
        register_rest_route(self::NAMESPACE, '/settings/(?P<id>\d+)/votes', [
            'methods' => 'GET',
            'callback' => [$this, 'get_setting_votes'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0;
                    }
                ]
            ]
        ]);

        // POST/PATCH /vote (public, rate-limited)
        register_rest_route(self::NAMESPACE, '/vote', [
            'methods' => ['POST', 'PATCH'],
            'callback' => [$this, 'handle_vote'],
            'permission_callback' => '__return_true',
            'args' => [
                'setting_id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0;
                    }
                ],
                'vote' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return in_array($param, [1, -1, '1', '-1'], true);
                    }
                ]
            ]
        ]);

        // PUT /printers/{id} (admin only)
        register_rest_route(self::NAMESPACE, '/printers/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_printer'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0;
                    }
                ]
            ]
        ]);
    }
    
    public function get_selectors(WP_REST_Request $request) {
        global $wpdb;
        
        // Get all printers with all specifications
        $printers = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}fsw_printers 
            ORDER BY maker, model
        ");
        
        // Get filament types
        $types = $wpdb->get_col("
            SELECT DISTINCT filament_type 
            FROM {$wpdb->prefix}fsw_filament_products 
            ORDER BY filament_type
        ");
        
        // Get brands per type (simplified)
        $brands = $wpdb->get_results("
            SELECT filament_type, brand 
            FROM {$wpdb->prefix}fsw_filament_products 
            GROUP BY filament_type, brand 
            ORDER BY brand
        ");
        
        return new WP_REST_Response([
            'printers' => $printers,
            'filament_types' => $types,
            'brands' => $brands
        ], 200);
    }
    
    /**
     * GET /fsw/v1/printers/search
     * Lightweight search for typeahead + filters
     */
    public function search_printers(WP_REST_Request $request) {
        global $wpdb;
        
        $params = $request->get_params();
        $q = isset($params['q']) ? trim(sanitize_text_field($params['q'])) : '';
        $limit = isset($params['limit']) ? min(200, max(1, intval($params['limit']))) : 50;
        $enclosure = isset($params['enclosure']) ? intval($params['enclosure']) : null;
        $frame_type = isset($params['frame_type']) ? sanitize_text_field($params['frame_type']) : '';
        
        // Build cache key
        $cache_key = 'fsw_printers_search_' . md5(json_encode($params));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }
        
        // Build query - only select lightweight columns
        $query = "SELECT id, maker, model, enclosure, frame_type, 
                         build_volume_x_mm, build_volume_y_mm, build_volume_z_mm, 
                         max_hotend_temp_c 
                  FROM {$wpdb->prefix}fsw_printers 
                  WHERE 1=1";
        $args = [];
        
        if ($q !== '') {
            $query .= " AND (maker LIKE %s OR model LIKE %s)";
            $args[] = '%' . $wpdb->esc_like($q) . '%';
            $args[] = '%' . $wpdb->esc_like($q) . '%';
        }
        
        if ($enclosure !== null) {
            $query .= " AND enclosure = %d";
            $args[] = $enclosure;
        }
        
        if ($frame_type !== '') {
            $query .= " AND frame_type = %s";
            $args[] = $frame_type;
        }
        
        $query .= " ORDER BY maker, model LIMIT %d";
        $args[] = $limit;
        
        if (!empty($args)) {
            $query = $wpdb->prepare($query, ...$args);
        } else {
            // When no args, prepare just the limit
            $query = $wpdb->prepare($query, $limit);
        }
        
        $printers = $wpdb->get_results($query);
        
        // Cache for 5-15 minutes (random to avoid cache stampede)
        set_transient($cache_key, $printers, 5 * MINUTE_IN_SECONDS + rand(0, 10 * MINUTE_IN_SECONDS));
        
        return new WP_REST_Response(['printers' => $printers], 200);
    }
    
    /**
     * GET /fsw/v1/printers/compare
     * Fetch full specs for selected printer IDs
     */
    public function compare_printers(WP_REST_Request $request) {
        global $wpdb;
        
        $ids_param = $request->get_param('ids');
        if (!$ids_param) {
            return new WP_Error('missing_ids', 'ids parameter is required', ['status' => 400]);
        }
        
        $ids = array_map('intval', explode(',', $ids_param));
        $ids = array_filter($ids, function($id) { return $id > 0; });
        
        if (empty($ids)) {
            return new WP_Error('invalid_ids', 'No valid printer IDs provided', ['status' => 400]);
        }
        
        if (count($ids) > 4) {
            return new WP_Error('too_many', 'Maximum 4 printers allowed', ['status' => 400]);
        }
        
        // Prepare placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        // Fetch full printer records
        $query = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fsw_printers WHERE id IN ($placeholders) ORDER BY maker, model",
            $ids
        );
        
        $printers = $wpdb->get_results($query);
        
        // Verify all requested IDs were found
        $found_ids = wp_list_pluck($printers, 'id');
        $missing = array_diff($ids, $found_ids);
        if (!empty($missing)) {
            return new WP_Error('not_found', 'Some printer IDs not found: ' . implode(',', $missing), ['status' => 404]);
        }
        
        return new WP_REST_Response(['printers' => $printers], 200);
    }
    
    public function get_settings(WP_REST_Request $request) {
        global $wpdb;
        
        $params = $request->get_params();
        $printer_id = isset($params['printer_id']) ? intval($params['printer_id']) : 0;
        $product_id = isset($params['filament_product_id']) ? intval($params['filament_product_id']) : 0;
        $filament_type = isset($params['filament_type']) ? sanitize_text_field($params['filament_type']) : '';
        // FIX #2: Get pagination params with defaults
        $limit = isset($params['limit']) ? intval($params['limit']) : 20;
        $offset = isset($params['offset']) ? intval($params['offset']) : 0;
        if (!$printer_id && !$product_id) {
            return new WP_Error('missing_params', 'printer_id or filament_product_id is required', ['status' => 400]);
        }
        
        $query = $wpdb->prepare("
            SELECT s.id, s.settings_json, s.source_priority, s.confidence, s.status, s.updated_at,
                   p.brand, p.product_name, p.diameter_mm, p.filament_type,
                   pr.maker, pr.model,
                   src.publisher, src.url
            FROM {$wpdb->prefix}fsw_settings s
            JOIN {$wpdb->prefix}fsw_filament_products p ON s.filament_product_id = p.id
            JOIN {$wpdb->prefix}fsw_printers pr ON s.printer_id = pr.id
            LEFT JOIN {$wpdb->prefix}fsw_sources src ON s.source_id = src.id
            WHERE s.status = 'active'
        ");
        
        $args = [];
        if ($printer_id) {
            $query .= " AND s.printer_id = %d";
            $args[] = $printer_id;
        }
        if ($filament_type) {
            $query .= " AND s.filament_type = %s";
            $args[] = $filament_type;
        }
        if ($product_id) {
            $query .= " AND s.filament_product_id = %d";
            $args[] = $product_id;
        }
        
        $query .= " ORDER BY s.source_priority ASC, s.confidence DESC, s.id DESC";
        $query .= " LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;

        if (!empty($args)) {
            $query = $wpdb->prepare($query, ...$args);
        }
        
        $results = $wpdb->get_results($query);
        
        // Append vote statistics for each setting
        if (!empty($results)) {
            $setting_ids = wp_list_pluck($results, 'id');
            $placeholders = implode(',', array_fill(0, count($setting_ids), '%d'));
            
            // Get aggregate vote stats
            $vote_stats = $wpdb->get_results($wpdb->prepare(
                "SELECT setting_id,
                        COUNT(*) as total_votes,
                        SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END) as up_votes,
                        SUM(CASE WHEN vote = -1 THEN 1 ELSE 0 END) as down_votes
                 FROM {$wpdb->prefix}fsw_setting_votes
                 WHERE setting_id IN ($placeholders)
                 GROUP BY setting_id",
                $setting_ids
            ));
            $stats_by_setting = [];
            foreach ($vote_stats as $stat) {
                $stats_by_setting[$stat->setting_id] = $stat;
            }
            
            // Get current user/IP vote for these settings
            $user_id = get_current_user_id();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_votes = [];
            if ($user_id) {
                $user_votes = $wpdb->get_results($wpdb->prepare(
                    "SELECT setting_id, vote FROM {$wpdb->prefix}fsw_setting_votes 
                     WHERE setting_id IN ($placeholders) AND user_id = %d",
                    array_merge($setting_ids, [$user_id])
                ));
            } elseif ($ip) {
                $user_votes = $wpdb->get_results($wpdb->prepare(
                    "SELECT setting_id, vote FROM {$wpdb->prefix}fsw_setting_votes 
                     WHERE setting_id IN ($placeholders) AND voter_ip = %s",
                    array_merge($setting_ids, [$ip])
                ));
            }
            $votes_by_setting = [];
            foreach ($user_votes as $v) {
                $votes_by_setting[$v->setting_id] = (int)$v->vote;
            }
            
            // Merge stats and user vote into results
            foreach ($results as &$row) {
                $stats = $stats_by_setting[$row->id] ?? (object)[
                    'total_votes' => 0,
                    'up_votes' => 0,
                    'down_votes' => 0
                ];
                $row->total_votes = $stats->total_votes;
                $row->up_votes = $stats->up_votes;
                $row->down_votes = $stats->down_votes;
                $row->user_vote = $votes_by_setting[$row->id] ?? null;
            }
        }
        
        // Parse settings JSON
        foreach ($results as &$row) {
            $row->settings_json = json_decode($row->settings_json, true);
            // FIX #7: Normalize notes to object if it's a string
            if (isset($row->notes) && is_string($row->notes)) {
                $row->notes = json_decode($row->notes, true) ?: [];
            }
        }
        
        return new WP_REST_Response($results, 200);
    }
    
    public function create_setting(WP_REST_Request $request) {
        global $wpdb;
        
        $params = $request->get_json_params();
        
        // Support both batch and single formats
        $settings_list = [];
        if (isset($params['settings']) && is_array($params['settings'])) {
            $settings_list = $params['settings'];
        } else {
            // Single setting format (backward compatibility)
            $settings_list = [$params];
        }
        
        $results = [];
        foreach ($settings_list as $item_params) {
            // Required fields
            $required = ['brand', 'filament_type', 'printer_model', 'nozzle_temp', 'bed_temp'];
            foreach ($required as $field) {
                if (empty($item_params[$field])) {
                    return new WP_Error('missing_field', "Missing required field: $field in one of the settings", ['status' => 400]);
                }
            }
            
            // Normalize and validate
            $brand = sanitize_text_field($item_params['brand']);
            $filament_type = sanitize_text_field($item_params['filament_type']);
            $printer_model = sanitize_text_field($item_params['printer_model']);
            $nozzle_temp = intval($item_params['nozzle_temp']);
            $bed_temp = intval($item_params['bed_temp']);
            $print_speed = isset($item_params['print_speed']) ? intval($item_params['print_speed']) : null;
            $retraction_distance = isset($item_params['retraction_distance']) ? intval($item_params['retraction_distance']) : null;
            $retraction_speed = isset($item_params['retraction_speed']) ? intval($item_params['retraction_speed']) : null;
            $cooling_percent = isset($item_params['cooling_percent']) ? intval($item_params['cooling_percent']) : null;
            $layer_height = isset($item_params['layer_height']) ? floatval($item_params['layer_height']) : null;
            $filament_diameter = isset($item_params['filament_diameter']) ? floatval($item_params['filament_diameter']) : 1.75;
            $enclosure_required = !empty($item_params['enclosure_required']) ? 1 : 0;
            $drying_required = !empty($item_params['drying_required']) ? 1 : 0;
            $notes_raw = isset($item_params['notes']) ? $item_params['notes'] : '';
            $source_url = isset($item_params['source_url']) ? esc_url_raw($item_params['source_url']) : '';
            $confidence = isset($item_params['confidence']) ? floatval($item_params['confidence']) : 0.7;

            if (is_string($notes_raw)) {
                $decoded_notes = json_decode($notes_raw, true);
                $notes_array = is_array($decoded_notes) ? $decoded_notes : ['drying' => '', 'warnings' => []];
            } elseif (is_array($notes_raw)) {
                $notes_array = $notes_raw;
            } else {
                $notes_array = ['drying' => '', 'warnings' => []];
            }
            $warnings_raw = $notes_array['warnings'] ?? [];
            if (is_string($warnings_raw)) {
                $warnings_raw = [$warnings_raw];
            }
            if (!is_array($warnings_raw)) {
                $warnings_raw = [];
            }
            $notes_array = [
                'drying' => isset($notes_array['drying']) ? sanitize_text_field($notes_array['drying']) : '',
                'warnings' => array_values(array_filter(array_map('sanitize_text_field', $warnings_raw)))
            ];
            
            // Lookup or create printer with basic specs
            $printer_id = $this->get_or_create_printer($printer_model, $item_params);
            if (!$printer_id) {
                return new WP_Error('db_error', 'Failed to find or create printer', ['status' => 500]);
            }
            
            // Lookup or create filament product
            $product_id = $this->get_or_create_filament_product($brand, $filament_type, $item_params);
            if (!$product_id) {
                $this->log("Invalid brand rejected in create_setting: '$brand'");
                return new WP_Error('invalid_brand', "Invalid or missing filament brand: '$brand'", ['status' => 400]);
            }
            
            // Lookup or create source
            $source_id = $this->get_or_create_source($source_url);
            
            // Compute fingerprint for deduplication
            $fingerprint = $this->compute_fingerprint([
                'brand' => $brand,
                'filament_type' => $filament_type,
                'printer_model' => $printer_model,
                'nozzle_temp' => $nozzle_temp,
                'bed_temp' => $bed_temp,
            ]);
            
            // Check for duplicate
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status FROM {$wpdb->prefix}fsw_settings WHERE fingerprint = %s",
                $fingerprint
            ));
            
            if ($existing) {
                // If existing is lower priority, we'd update but for now just skip
                $results[] = ['id' => $existing->id, 'status' => 'duplicate', 'message' => 'Duplicate setting exists'];
                continue;
            }
            
            // Build settings JSON
            $settings_json = json_encode([
                'nozzle_temp' => $nozzle_temp,
                'bed_temp' => $bed_temp,
                'print_speed' => $print_speed,
                'retraction_distance' => $retraction_distance,
                'retraction_speed' => $retraction_speed,
                'cooling_percent' => $cooling_percent,
                'layer_height' => $layer_height,
                'filament_diameter' => $filament_diameter,
                'enclosure_required' => (bool) $enclosure_required,
                'drying_required' => (bool) $drying_required,
                'notes' => $notes_array,
            ]);
            
            // Insert setting
            $result = $wpdb->insert(
                $wpdb->prefix . 'fsw_settings',
                [
                    'printer_id' => $printer_id,
                    'filament_product_id' => $product_id,
                    'filament_type' => $filament_type,
                    'source_id' => $source_id,
                    'fingerprint' => $fingerprint,
                    'settings_json' => $settings_json,
                    'source_priority' => 50, // default, can be improved
                    'confidence' => $confidence,
                    'status' => 'active',
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%d', '%s', '%d', '%s', '%s', '%d', '%f', '%s', '%s']
            );
            
            if ($result === false) {
                $results[] = ['id' => null, 'status' => 'error', 'message' => 'Failed to insert setting'];
            } else {
                $results[] = ['id' => $wpdb->insert_id, 'status' => 'created', 'message' => 'Setting created'];
            }
        }
        
        return new WP_REST_Response(['results' => $results], 201);
    }

    /**
     * GET /fsw/v1/settings/<id>/votes
     * Retrieve vote statistics for a setting
     */
    public function get_setting_votes(WP_REST_Request $request) {
        global $wpdb;
        $setting_id = intval($request->get_param('id'));

        // Verify setting exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fsw_settings WHERE id = %d AND status = 'active'",
            $setting_id
        ));
        if (!$exists) {
            return new WP_Error('not_found', 'Setting not found', ['status' => 404]);
        }

        // Get vote counts
        $votes = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_votes,
                SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END) as up_votes,
                SUM(CASE WHEN vote = -1 THEN 1 ELSE 0 END) as down_votes
             FROM {$wpdb->prefix}fsw_setting_votes
             WHERE setting_id = %d",
            $setting_id
        ));

        // Check current user/IP vote
        $user_vote = null;
        $user_id = get_current_user_id();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if ($user_id) {
            $user_vote = $wpdb->get_var($wpdb->prepare(
                "SELECT vote FROM {$wpdb->prefix}fsw_setting_votes WHERE setting_id = %d AND user_id = %d LIMIT 1",
                $setting_id, $user_id
            ));
        } elseif ($ip) {
            $user_vote = $wpdb->get_var($wpdb->prepare(
                "SELECT vote FROM {$wpdb->prefix}fsw_setting_votes WHERE setting_id = %d AND voter_ip = %s LIMIT 1",
                $setting_id, $ip
            ));
        }

        return new WP_REST_Response([
            'setting_id' => $setting_id,
            'total_votes' => (int)($votes->total_votes ?? 0),
            'up_votes' => (int)($votes->up_votes ?? 0),
            'down_votes' => (int)($votes->down_votes ?? 0),
            'user_vote' => $user_vote !== null ? (int)$user_vote : null
        ], 200);
    }

    /**
     * POST/PATCH /fsw/v1/vote
     * Submit or change a vote for a setting
     */
    public function handle_vote(WP_REST_Request $request) {
        global $wpdb;
        
        $setting_id = intval($request->get_param('setting_id'));
        $vote = intval($request->get_param('vote')); // 1 or -1

        // Validate setting exists and is active
        $setting = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id, s.confidence FROM {$wpdb->prefix}fsw_settings WHERE id = %d AND status = 'active'",
            $setting_id
        ));
        if (!$setting) {
            return new WP_Error('not_found', 'Setting not found or inactive', ['status' => 404]);
        }

        // Rate limiting: max 10 votes per IP per hour
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip) {
            $recent_votes = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fsw_setting_votes 
                 WHERE voter_ip = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                $ip
            ));
            if ($recent_votes >= 10) {
                return new WP_Error('rate_limited', 'Too many votes from this IP. Try again later.', ['status' => 429]);
            }
        }

        // Identify voter: logged-in user OR IP
        $user_id = get_current_user_id();
        $existing_vote_id = null;
        $existing_vote = null;

        if ($user_id) {
            $existing_vote_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id, vote FROM {$wpdb->prefix}fsw_setting_votes 
                 WHERE setting_id = %d AND user_id = %d LIMIT 1",
                $setting_id, $user_id
            ));
            if ($existing_vote_id) {
                $existing_vote = $wpdb->get_var($wpdb->prepare(
                    "SELECT vote FROM {$wpdb->prefix}fsw_setting_votes WHERE id = %d",
                    $existing_vote_id
                ));
            }
        } elseif ($ip) {
            $existing_vote_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id, vote FROM {$wpdb->prefix}fsw_setting_votes 
                 WHERE setting_id = %d AND voter_ip = %s LIMIT 1",
                $setting_id, $ip
            ));
            if ($existing_vote_id) {
                $existing_vote = $wpdb->get_var($wpdb->prepare(
                    "SELECT vote FROM {$wpdb->prefix}fsw_setting_votes WHERE id = %d",
                    $existing_vote_id
                ));
            }
        }

        // If same vote, treat as no-op but return current stats
        if ($existing_vote_id && $existing_vote == $vote) {
            // Return current stats without changes
            $stats = $this->calculate_vote_stats($setting_id);
            $new_confidence = $this->recalculate_confidence($setting, $stats['total_votes'], $stats['net_votes']);
            // Update setting confidence immediately
            $wpdb->update(
                $wpdb->prefix . 'fsw_settings',
                ['confidence' => $new_confidence],
                ['id' => $setting_id],
                ['%f'],
                ['%d']
            );
            return new WP_REST_Response([
                'setting_id' => $setting_id,
                'total_votes' => $stats['total_votes'],
                'up_votes' => $stats['up_votes'],
                'down_votes' => $stats['down_votes'],
                'confidence' => $new_confidence,
                'user_vote' => $vote
            ], 200);
        }

        // Begin transaction for atomic upsert
        $wpdb->query('START TRANSACTION');
        try {
            if ($existing_vote_id) {
                // Update existing vote (PATCH)
                $wpdb->update(
                    $wpdb->prefix . 'fsw_setting_votes',
                    ['vote' => $vote],
                    ['id' => $existing_vote_id],
                    ['%d'],
                    ['%d']
                );
            } else {
                // Insert new vote (POST)
                $wpdb->insert(
                    $wpdb->prefix . 'fsw_setting_votes',
                    [
                        'setting_id' => $setting_id,
                        'user_id' => $user_id ?: null,
                        'voter_ip' => $user_id ? null : $ip,
                        'vote' => $vote,
                        'created_at' => current_time('mysql')
                    ],
                    ['%d', '%d', '%s', '%d', '%s']
                );
            }

            // Get fresh vote stats
            $stats = $this->calculate_vote_stats($setting_id);
            
            // Recalculate confidence
            $new_confidence = $this->recalculate_confidence($setting, $stats['total_votes'], $stats['net_votes']);
            
            // Update setting confidence
            $wpdb->update(
                $wpdb->prefix . 'fsw_settings',
                ['confidence' => $new_confidence],
                ['id' => $setting_id],
                ['%f'],
                ['%d']
            );

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Failed to record vote: ' . $e->getMessage(), ['status' => 500]);
        }

        return new WP_REST_Response([
            'setting_id' => $setting_id,
            'total_votes' => $stats['total_votes'],
            'up_votes' => $stats['up_votes'],
            'down_votes' => $stats['down_votes'],
            'confidence' => $new_confidence,
            'user_vote' => $vote
        ], 200);
    }

    /**
     * Update a printer record (admin only)
     */
    public function update_printer(WP_REST_Request $request) {
        global $wpdb;
        $id = intval($request->get_param('id'));
        $body = $request->get_json_params();

        // Allowed fields (must match database column names)
        $allowed = [
            'maker', 'model', 'max_hotend_temp_c', 'max_bed_temp_c',
            'extruder_type', 'hotend_type', 'enclosure', 'build_volume_x_mm', 'build_volume_y_mm', 'build_volume_z_mm',
            'autolevel_type', 'autolevel_points', 'build_surface_type', 'build_surface_removable',
            'frame_type', 'travel_speed_mm_s', 'linear_rail_xyz', 'belt_drive',
            'display_type', 'tft_display', 'wifi_enabled', 'ethernet_enabled', 'usb_media',
            'pressure_advance', 'input_shaping', 'multi_material', 'spool_sensors', 'power_loss_recovery', 'filament_sensor'
        ];

        $data = [];
        $format = [];
        $format_map = [
            'maker' => '%s',
            'model' => '%s',
            'max_hotend_temp_c' => '%d',
            'max_bed_temp_c' => '%d',
            'extruder_type' => '%s',
            'hotend_type' => '%s',
            'enclosure' => '%d',
            'build_volume_x_mm' => '%f',
            'build_volume_y_mm' => '%f',
            'build_volume_z_mm' => '%f',
            'autolevel_type' => '%s',
            'autolevel_points' => '%d',
            'build_surface_type' => '%s',
            'build_surface_removable' => '%d',
            'frame_type' => '%s',
            'travel_speed_mm_s' => '%d',
            'linear_rail_xyz' => '%s',
            'belt_drive' => '%d',
            'display_type' => '%s',
            'tft_display' => '%d',
            'wifi_enabled' => '%d',
            'ethernet_enabled' => '%d',
            'usb_media' => '%d',
            'pressure_advance' => '%d',
            'input_shaping' => '%d',
            'multi_material' => '%d',
            'spool_sensors' => '%d',
            'power_loss_recovery' => '%d',
            'filament_sensor' => '%d'
        ];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                // Simple sanitization
                $value = $body[$field];
                if (is_int($value) || is_float($value)) {
                    $data[$field] = $value;
                } else {
                    $data[$field] = is_string($value) ? sanitize_text_field($value) : $value;
                }
                // Append format
                if (isset($format_map[$field])) {
                    $format[] = $format_map[$field];
                } else {
                    $format[] = '%s';
                }
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No fields to update', ['status' => 400]);
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'fsw_printers',
            $data,
            ['id' => $id],
            $format,
            ['%d']
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update printer', ['status' => 500]);
        }

        return new WP_REST_Response(['success' => true, 'rows_affected' => $result], 200);
    }

    /**
     * Calculate vote statistics for a setting
     */
    private function calculate_vote_stats($setting_id) {
        global $wpdb;
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_votes,
                SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END) as up_votes,
                SUM(CASE WHEN vote = -1 THEN 1 ELSE 0 END) as down_votes
             FROM {$wpdb->prefix}fsw_setting_votes
             WHERE setting_id = %d",
            $setting_id
        ));
        return [
            'total_votes' => (int)($result->total_votes ?? 0),
            'up_votes' => (int)($result->up_votes ?? 0),
            'down_votes' => (int)($result->down_votes ?? 0),
            'net_votes' => (int)($result->up_votes ?? 0) - (int)($result->down_votes ?? 0)
        ];
    }

    /**
     * Recalculate confidence score based on source confidence and community votes
     * Uses Bayesian blend: (source_conf * prior_weight + community_signal) / (prior_weight + total_votes * vote_weight)
     */
    private function recalculate_confidence($setting, $total_votes, $net_votes) {
        $source_conf = (float)($setting->confidence ?? 0.7);
        $prior_weight = 10; // virtual votes representing source credibility
        $vote_weight = 1;   // scaling factor for each actual vote

        // If no community votes, return source confidence
        if ($total_votes == 0) {
            return $source_conf;
        }

        // Community signal: map net votes to [0.5, 1.0] range
        // Positive net votes push toward 1.0, negative toward 0.0
        // We treat each net vote as 0.1 shift from 0.5
        $community_signal = 0.5 + ($net_votes * 0.1);
        $community_signal = max(0.0, min(1.0, $community_signal));

        // Weighted average
        $effective_score = ($source_conf * $prior_weight) + ($community_signal * $total_votes * $vote_weight);
        $total_weight = $prior_weight + ($total_votes * $vote_weight);
        $final_confidence = $effective_score / $total_weight;

        return round($final_confidence, 4);
    }
    
    private function get_or_create_printer($model, $params = []) {
        global $wpdb;
        $model = trim($model);
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fsw_printers WHERE model = %s LIMIT 1",
            $model
        ));
        
        if ($existing) {
            return $existing;
        }
        
        // Use only the original set of columns for maximum compatibility
        $maker = isset($params['maker']) ? sanitize_text_field($params['maker']) : '';
        if (empty($maker) || stripos($maker, 'unknown') !== false || stripos($maker, 'unspecified') !== false) {
            $first_part = strtok($model, " \t\n\r\0\x0B,-/()");
            $first_part = trim($first_part, " .,-");

            if (preg_match('/^[A-Za-z0-9]+$/', $first_part) && !is_numeric($first_part) && strlen($first_part) >= 2) {
                $maker = $first_part;
            } else {
                $maker = 'Unknown';
            }
        }
        $extruder_type = isset($params['extruder_type']) ? sanitize_text_field($params['extruder_type']) : 'bowden';
        // Support both naming conventions; store in correct column: max_hotend_temp_c
        $max_hotend_temp_c = isset($params['max_hotend_temp_c']) ? intval($params['max_hotend_temp_c']) : (isset($params['max_nozzle_temp_c']) ? intval($params['max_nozzle_temp_c']) : 260);
        $max_bed_temp_c = isset($params['max_bed_temp_c']) ? intval($params['max_bed_temp_c']) : 100;
        $enclosure = isset($params['enclosure']) ? intval($params['enclosure']) : 0;
        
        $wpdb->insert(
            $wpdb->prefix . 'fsw_printers',
            [
                'maker' => $maker,
                'model' => $model,
                'extruder_type' => $extruder_type,
                'max_hotend_temp_c' => $max_hotend_temp_c,
                'max_bed_temp_c' => $max_bed_temp_c,
                'enclosure' => $enclosure,
            ],
            ['%s', '%s', '%s', '%d', '%d', '%d']
        );
        
        return $wpdb->insert_id;
    }
    
    private function get_or_create_filament_product($brand, $type, $params = []) {
        global $wpdb;
        $brand = trim($brand);
        $type = trim($type);

        $brand_lower = strtolower($brand);
        $invalid_brands = ['3dput', 'unknown', 'unspecified', 'not specified', 'no brand', ''];
        if (in_array($brand_lower, $invalid_brands, true) || empty($brand)) {
            $this->log("Invalid brand rejected: '$brand'");
            return null;
        }
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fsw_filament_products WHERE brand = %s AND filament_type = %s LIMIT 1",
            $brand, $type
        ));
        
        if ($existing) {
            return $existing;
        }
        
        $product_name = isset($params['product_name']) ? sanitize_text_field($params['product_name']) : "$brand $type";
        $diameter_mm = isset($params['diameter_mm']) ? floatval($params['diameter_mm']) : 1.75;
        $manufacturer_url = isset($params['manufacturer_url']) ? esc_url_raw($params['manufacturer_url']) : '';
        $notes = isset($params['notes']) ? sanitize_text_field($params['notes']) : '';
        
        $wpdb->insert(
            $wpdb->prefix . 'fsw_filament_products',
            [
                'brand' => $brand,
                'product_name' => $product_name,
                'filament_type' => $type,
                'diameter_mm' => $diameter_mm,
                'manufacturer_url' => $manufacturer_url,
                'notes' => $notes
            ],
            ['%s', '%s', '%s', '%f', '%s', '%s']
        );
        
        return $wpdb->insert_id;
    }
    
    private function get_or_create_source($url) {
        if (empty($url)) {
            return null;
        }

        global $wpdb;
        $normalized_url = strtolower(trim($url));
        $home_url = strtolower(home_url());

        if (stripos($normalized_url, $home_url) !== false || stripos($normalized_url, '3dput.com') !== false) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fsw_sources WHERE LOWER(url) LIKE %s",
                '%3dput.com%'
            ));
            if ($existing) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fsw_sources WHERE id = %d", $existing));
                if (!empty($wpdb->last_error)) {
                    $this->log("Error deleting self-source ID {$existing}: {$wpdb->last_error}");
                } else {
                    $this->log("Deleted self-source ID {$existing}");
                }
            }
            $this->log("Rejected self-source URL: $url");
            return null;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fsw_sources WHERE url = %s LIMIT 1",
            $url
        ));
        
        if ($existing) {
            return $existing;
        }
        
        $domain = parse_url($url, PHP_URL_HOST);
        $publisher = $domain ?: 'Unknown';
        
        $wpdb->insert(
            $wpdb->prefix . 'fsw_sources',
            ['publisher' => $publisher, 'url' => $url, 'active' => 1],
            ['%s', '%s', '%d']
        );
        
        return $wpdb->insert_id;
    }

    private function cleanup_self_sources() {
        global $wpdb;
        $table = $wpdb->prefix . 'fsw_sources';
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE LOWER(url) LIKE %s OR LOWER(publisher) LIKE %s",
            '%3dput.com%', '%3dput%'
        ));

        if (!empty($wpdb->last_error)) {
            $this->log("Error cleaning self-sources: {$wpdb->last_error}");
        } else {
            $this->log("Cleaned self-sources: deleted {$deleted}");
        }

        return $deleted;
    }
    
    private function compute_fingerprint($data) {
        $key = [
            'brand' => strtolower(trim($data['brand'] ?? '')),
            'filament_type' => strtolower(trim($data['filament_type'] ?? '')),
            'printer_model' => strtolower(trim($data['printer_model'] ?? '')),
            'nozzle_temp' => intval($data['nozzle_temp'] ?? 0),
            'bed_temp' => intval($data['bed_temp'] ?? 0),
        ];
        $key_str = json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $key_str);
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message\n";
        $log_file = plugin_dir_path(__FILE__) . '../logs/rest-api.log';
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
}
