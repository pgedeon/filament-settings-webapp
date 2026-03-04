<?php
/**
 * Frontend Comparison Page Template
 * 
 * Displays printer comparison interface with search, filters, and results.
 * Data is passed via wp_localize_script from frontend.php
 * 
 * @package Filament_Settings_Web_App
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="fsw-compare-app" class="fsw-compare">
    
    <!-- Picker Section -->
    <section class="fsw-compare-picker">
        <div class="fsw-search-container">
            <label for="fsw-search-input" class="fsw-sr-only">
                <?php esc_html_e('Search printers by maker or model', 'filament-settings-webapp'); ?>
            </label>
            <input 
                type="search" 
                id="fsw-search-input" 
                class="fsw-search-input" 
                placeholder="<?php esc_attr_e('Search printers (e.g. Ender 3, Prusa)...', 'filament-settings-webapp'); ?>"
                autocomplete="off"
                aria-label="<?php esc_attr_e('Search printers by maker or model', 'filament-settings-webapp'); ?>"
                aria-controls="fsw-printer-list">
            <div class="fsw-printer-list-wrapper">
                <div class="fsw-printer-list-header">
                    <div id="fsw-list-count" class="fsw-list-count">
                        <?php esc_html_e('Showing 0 of 0 printers', 'filament-settings-webapp'); ?>
                    </div>
                    <div class="fsw-list-actions" role="group" aria-label="<?php esc_attr_e('Selection actions', 'filament-settings-webapp'); ?>">
                        <label for="fsw-select-all" class="fsw-select-all">
                            <input type="checkbox" id="fsw-select-all">
                            <span><?php esc_html_e('Select all', 'filament-settings-webapp'); ?></span>
                        </label>
                        <button type="button" id="fsw-clear-selection" class="fsw-clear-selection">
                            <?php esc_html_e('Clear selection', 'filament-settings-webapp'); ?>
                        </button>
                    </div>
                </div>
                <div id="fsw-printer-list" class="fsw-printer-list" role="list" aria-live="polite" aria-label="<?php esc_attr_e('Printer list', 'filament-settings-webapp'); ?>"></div>
            </div>
            <div id="fsw-search-status" class="fsw-sr-only" aria-live="polite" aria-atomic="true"></div>
        </div> <!-- fsw-search-container -->

        <div class="fsw-filters" role="group" aria-label="<?php esc_attr_e('Filter options', 'filament-settings-webapp'); ?>">
            <?php 
            // Define filter values inline for now (can be moved to config)
            $filter_values = [
                'enclosure' => ['1' => __('Enclosed', 'filament-settings-webapp')],
                'frame_type' => ['corexy' => __('CoreXY', 'filament-settings-webapp')],
                'build_volume' => ['large' => __('Large Build (≥300mm)', 'filament-settings-webapp')]
            ];
            foreach ($filter_values as $filter => $values):
                foreach ($values as $value => $label):
            ?>
                    <button type="button" class="fsw-chip" 
                            data-filter="<?php echo esc_attr($filter); ?>" 
                            data-value="<?php echo esc_attr($value); ?>"
                            aria-pressed="false">
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; 
            ?>
            <?php endforeach; ?>
        </div> <!-- fsw-filters -->

        <div class="fsw-selected-tray">
            <div class="fsw-tray-label">
                <?php esc_html_e('Selected printers', 'filament-settings-webapp'); ?> (<span id="fsw-selected-count">0</span>/4)
            </div>
            <div id="fsw-selected-list" class="fsw-selected-list" style="display: none;" role="list">
                <!-- Selected printer cards will be inserted here -->
            </div>
        </div> <!-- fsw-selected-tray -->

        <div id="fsw-picker-actions" class="fsw-compare-actions">
            <button type="button" id="fsw-compare-button" class="fsw-compare-button" disabled aria-describedby="fsw-compare-hint">
                <?php esc_html_e('Compare Selected', 'filament-settings-webapp'); ?>
            </button>
            <p id="fsw-compare-hint" class="fsw-hint">
                <?php esc_html_e('Select at least 2 printers to compare', 'filament-settings-webapp'); ?>
            </p>
        </div> <!-- fsw-compare-actions -->
    </section> <!-- fsw-compare-picker -->

    <!-- Results Section -->
    <section class="fsw-compare-results" style="display: none;" aria-live="polite">
        <div id="fsw-sticky-header" class="fsw-sticky-header" role="region" aria-label="<?php esc_attr_e('Comparison summary', 'filament-settings-webapp'); ?>">
            <div id="fsw-header-cards"></div>
        </div>
    </section> <!-- fsw-compare-results -->

    <!-- Actions Section for comparison results -->
    <section id="fsw-results-actions" class="fsw-compare-actions" style="display: none;">
        <button type="button" id="fsw-diff-toggle" class="fsw-action-btn">
            <?php esc_html_e('Show Differences', 'filament-settings-webapp'); ?>
        </button>
        <button type="button" id="fsw-share-link" class="fsw-action-btn">
            <?php esc_html_e('Share Link', 'filament-settings-webapp'); ?>
        </button>
    </section> <!-- fsw-compare-actions -->

    <!-- Sections Container for grouped specs -->
    <div id="fsw-sections" class="fsw-specs-container"></div>

    <!-- States -->
    <div id="fsw-loading" class="fsw-loading" style="display: none;" role="status" aria-live="polite">
        <?php esc_html_e('Loading comparison data...', 'filament-settings-webapp'); ?>
    </div>

    <div id="fsw-error" class="fsw-error" style="display: none;" role="alert" aria-live="assertive">
        <p><strong><?php esc_html_e('Error:', 'filament-settings-webapp'); ?></strong> <span class="fsw-error-message"></span></p>
        <button type="button" class="fsw-retry-button"><?php esc_html_e('Retry', 'filament-settings-webapp'); ?></button>
    </div>

</div> <!-- fsw-compare-app -->
