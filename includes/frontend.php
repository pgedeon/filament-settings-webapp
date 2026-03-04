<?php
/**
 * Frontend web app for Filament Settings
 * Cache bust: 2026-02-13 15:01 PST
 */

if (!defined('ABSPATH')) {
    exit;
}

class FSW_Frontend {
    public static $compare_used = false;

    public function __construct() {
        add_shortcode('filament_settings_webapp', [self::class, 'render_app']);
        add_shortcode('printer_comparison', [self::class, 'render_comparison_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_comparison_assets']);
        add_action('wp_head', [$this, 'output_faq_jsonld']);
    }
    
    public function enqueue_assets() {
        clearstatcache();
        wp_enqueue_script(
            'fsw-app',
            FSW_PLUGIN_URL . 'assets/app-v3.js',
            ['jquery'],
            filemtime(FSW_PLUGIN_DIR . 'assets/app-v3.js'),
            true
        );
        
        wp_enqueue_style(
            'fsw-app',
            FSW_PLUGIN_URL . 'assets/app-v2.css',
            [],
            filemtime(FSW_PLUGIN_DIR . 'assets/app-v2.css')
        );
        
        // Localize script with API endpoint and nonce
        wp_localize_script('fsw-app', 'fswData', [
            'ajaxUrl' => rest_url('fsw/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'strings' => [
                'select_printer' => 'Select a printer',
                'select_filament' => 'Select filament type',
                'no_results' => 'No settings found. Try different filters.',
                'loading' => 'Loading...'
            ]
        ]);
    }

    public function enqueue_comparison_assets() {
        $should_enqueue = false;

        if (self::$compare_used) {
            $should_enqueue = true;
        } else {
            global $post;
            if ($post && has_shortcode($post->post_content, 'printer_comparison')) {
                $should_enqueue = true;
            }
        }

        if (!$should_enqueue) {
            return;
        }

        wp_enqueue_script(
            'fsw-compare',
            FSW_PLUGIN_URL . 'assets/compare.js',
            [],
            filemtime(FSW_PLUGIN_DIR . 'assets/compare.js'),
            true
        );

        wp_enqueue_style(
            'fsw-compare',
            FSW_PLUGIN_URL . 'assets/compare.css',
            [],
            filemtime(FSW_PLUGIN_DIR . 'assets/compare.css')
        );

        wp_localize_script('fsw-compare', 'fswCompareData', [
            'ajaxUrl' => rest_url('fsw/v1'),
            'nonce' => wp_create_nonce('wp_rest')
        ]);
    }

    public function output_faq_jsonld() {
        // Only output on the specific filament settings page (ID 45748)
        if (!is_page(45748)) {
            return;
        }

        $post = get_post();
        if (empty($post)) {
            return;
        }

        $content = $post->post_content;

        // Use DOMDocument to parse HTML and extract FAQ questions/answers
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        // Find the h2 heading with id="faq"
        $faqHeading = $xpath->query('//h2[@id="faq"]')->item(0);
        if (!$faqHeading) {
            return;
        }

        // Find all h3 elements that are immediate or subsequent siblings after the FAQ heading
        $h3s = $xpath->query('following-sibling::h3', $faqHeading);
        $questions = [];

        foreach ($h3s as $h3) {
            $question = trim($h3->textContent);
            // Only include if it looks like a question (ends with ?)
            if (substr($question, -1) !== '?') {
                // Stop at first non-question h3 (e.g., "Further reading")
                break;
            }

            // Collect all sibling nodes after this h3 until the next heading (h2-h6)
            $answerParts = [];
            $sibling = $h3->nextSibling;
            while ($sibling) {
                if ($sibling->nodeType === XML_ELEMENT_NODE && in_array($sibling->nodeName, ['h2','h3','h4','h5','h6'])) {
                    break;
                }
                if ($sibling->nodeType === XML_ELEMENT_NODE) {
                    $text = trim($sibling->textContent);
                    if ($text) {
                        $answerParts[] = $text;
                    }
                } elseif ($sibling->nodeType === XML_TEXT_NODE) {
                    $text = trim($sibling->textContent);
                    if ($text) {
                        $answerParts[] = $text;
                    }
                }
                $sibling = $sibling->nextSibling;
            }

            if (empty($answerParts)) {
                continue;
            }

            $answerText = implode(' ', $answerParts);

            $questions[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answerText
                ]
            ];
        }

        if (empty($questions)) {
            return;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $questions
        ];

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . '</script>' . "\n";
    }
    
    public static function render_app() {
        ob_start();
        include FSW_PLUGIN_DIR . 'templates/frontend/app.php';
        return ob_get_clean();
    }

    public static function render_comparison_page() {
        self::$compare_used = true;
        ob_start();
        include FSW_PLUGIN_DIR . 'templates/frontend/compare.php';
        return ob_get_clean();
    }
}
