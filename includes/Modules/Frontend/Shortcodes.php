<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Frontend;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Cache;
use PlugixaTestimonial\Core\Kses;

/**
 * Frontend Shortcodes - [plugixa_testimonial] and [plugixa_testimonial_form]
 */
class Shortcodes {

    /**
     * Script/style handle for the bundled Swiper library.
     *
     * Must stay prefixed: Elementor Pro, WPBakery and many themes register a bare
     * 'swiper' handle, and WordPress silently keeps whichever was registered first -
     * so an unprefixed handle can leave us running against someone else's Swiper
     * build (or theirs against ours).
     */
    const SWIPER_HANDLE = 'plugixa-testimonial-swiper';

    const SWIPER_VERSION = '11.2.6';

    /** Handle for the main frontend script. */
    const FRONTEND_HANDLE = 'plugixa-testimonial-frontend';

    public function register(): void {
        add_shortcode('plugixa_testimonial', [$this, 'render_testimonials']);
        add_shortcode('plugixa_testimonial_form', [$this, 'render_form']);

        // Deprecated plural alias. The tag shipped as [plugixa_testimonials] during
        // pre-release; every other plugin in the family addresses its primary display
        // shortcode in the singular ([plugixa_faq], [plugixa_team], [plugixa_recipe]),
        // and the plural also read oddly beside [plugixa_testimonial_form]. Kept
        // registered so content written against the old tag keeps rendering.
        add_shortcode('plugixa_testimonials', [$this, 'render_testimonials']);
    }

    /**
     * Render testimonials display shortcode
     * Usage: [plugixa_testimonial id="1"] or with direct attributes
     */
    public function render_testimonials($atts): string {
        $atts = shortcode_atts([
            'id'               => 0,
            'view_id'          => 0,
            'view'             => '',
            'category'         => '',
            'limit'            => 0,
            'orderby'          => '',
            'order'            => '',
            'featured_only'    => '',
            'min_rating'       => 0,
            'show_photo'       => '',
            'show_rating'      => '',
            'show_role'        => '',
            'show_company'     => '',
            'show_date'        => '',
            'show_website'     => '',
            'show_quote_icon'  => '',
            'dark_mode'        => '',
            'pagination'       => '',
            'pagination_style' => '',
            'per_page'         => 0,
            'schema'           => '',
        ], $atts, 'plugixa_testimonial');

        $shortcode_id = (int) $atts['id'];

        // Load shortcode config
        $config = [];
        $view_config = [];

        if ($shortcode_id > 0) {
            global $wpdb;
            $sc_table = $wpdb->prefix . 'plugixatestimonial_shortcodes';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $sc_row = $wpdb->get_row(
                $wpdb->prepare("SELECT config FROM %i WHERE id = %d", $sc_table, $shortcode_id),
                ARRAY_A
            );
            if ($sc_row) {
                $config = json_decode($sc_row['config'], true) ?: [];
            }
        }

        // Merge directly-passed attributes as overrides
        if (!empty($atts['category'])) {
            $cat_parts = array_map('trim', explode(',', $atts['category']));
            $all_numeric = array_reduce($cat_parts, fn($carry, $v) => $carry && is_numeric($v), true);
            if ($all_numeric) {
                $config['categories'] = array_map('intval', $cat_parts);
            } else {
                // Lookup category IDs by name
                global $wpdb;
                $cat_table = $wpdb->prefix . 'plugixatestimonial_categories';
                // FIND_IN_SET keeps the placeholder count fixed at one, so the query
                // text is a constant literal for any number of names. The attribute was
                // itself comma-split above, so a name containing a comma is unreachable
                // through this path either way -- the two forms are equivalent here.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $cat_ids = $wpdb->get_col(
                    $wpdb->prepare("SELECT id FROM %i WHERE FIND_IN_SET(name, %s)", $cat_table, implode(',', $cat_parts))
                );
                $config['categories'] = array_map('intval', $cat_ids);
            }
        }
        if ((int) $atts['limit'] > 0) {
            $config['limit'] = (int) $atts['limit'];
        }
        if (!empty($atts['orderby'])) {
            $config['orderby'] = sanitize_key($atts['orderby']);
        }
        if (!empty($atts['order'])) {
            $config['order'] = strtoupper($atts['order']);
        }
        if ($atts['featured_only'] !== '' && $atts['featured_only'] !== false) {
            $config['featured_only'] = filter_var($atts['featured_only'], FILTER_VALIDATE_BOOLEAN);
        }
        if ((int) $atts['min_rating'] > 0) {
            $config['min_rating'] = (int) $atts['min_rating'];
        }
        if ($atts['dark_mode'] !== '') {
            $config['darkMode'] = sanitize_text_field($atts['dark_mode']);
        }
        if ($atts['pagination'] !== '') {
            $config['paginationEnabled'] = filter_var($atts['pagination'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($atts['pagination_style'] !== '') {
            $config['paginationStyle'] = sanitize_key($atts['pagination_style']);
        }
        if ((int) $atts['per_page'] > 0) {
            $config['perPage'] = (int) $atts['per_page'];
        }
        if ($atts['schema'] !== '') {
            $config['schema'] = filter_var($atts['schema'], FILTER_VALIDATE_BOOLEAN);
        }

        // Determine view_id: direct attribute overrides saved config
        $view_id = (int) $atts['view_id'] > 0 ? (int) $atts['view_id'] : ((int) ($config['view_id'] ?? 0));

        // Slug-based view lookup (e.g. view="grid")
        if ($view_id === 0 && !empty($atts['view'])) {
            global $wpdb;
            $views_table = $wpdb->prefix . 'plugixatestimonial_views';
            $view_slug = sanitize_title($atts['view']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $view_id = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM %i WHERE slug = %s", $views_table, $view_slug)
            );
        }

        if ($view_id > 0) {
            global $wpdb;
            $views_table = $wpdb->prefix . 'plugixatestimonial_views';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $view_row = $wpdb->get_row(
                $wpdb->prepare("SELECT config FROM %i WHERE id = %d", $views_table, $view_id),
                ARRAY_A
            );
            if ($view_row) {
                $view_config = json_decode($view_row['config'], true) ?: [];
            }
        }

        // If no view config specified, load default view
        if (empty($view_config)) {
            global $wpdb;
            $views_table = $wpdb->prefix . 'plugixatestimonial_views';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); this single indexed lookup runs once per shortcode render and is not separately cached.
            $default_view = $wpdb->get_row(
                $wpdb->prepare("SELECT config FROM %i WHERE is_default = 1 LIMIT 1", $views_table),
                ARRAY_A
            );
            if ($default_view) {
                $view_config = json_decode($default_view['config'], true) ?: [];
            }
        }

        // Every colour, size, shadow and font below is printed into CSS. Validate them here
        // as well as on save, so a row stored before that check existed is covered too.
        $view_config = \PlugixaTestimonial\Core\ViewStyle::normalize($view_config);

        // Resolve display settings: shortcode config overrides view config
        $display_defaults = [
            'showPhoto'        => true,
            'showRating'       => true,
            'showRole'         => true,
            'showCompany'      => true,
            'showDate'         => true,
            'showWebsite'      => false,
            'showQuoteIcon'    => true,
            'showCustomFields' => true,
            'showVideo'        => true,
        ];
        $shortcode_display = $config['display'] ?? $display_defaults;
        $view_config['display'] = array_merge($display_defaults, $shortcode_display);

        // Allow direct shortcode attribute overrides (show_* attributes)
        $display_attr_map = [
            'show_photo'      => 'showPhoto',
            'show_rating'     => 'showRating',
            'show_role'       => 'showRole',
            'show_company'    => 'showCompany',
            'show_date'       => 'showDate',
            'show_website'    => 'showWebsite',
            'show_quote_icon' => 'showQuoteIcon',
        ];
        foreach ($display_attr_map as $attr_key => $config_key) {
            if ($atts[$attr_key] !== '') {
                $view_config['display'][$config_key] = filter_var($atts[$attr_key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Resolve dark mode: shortcode overrides view
        $dark_mode_setting = $config['darkMode'] ?? 'disabled';
        $view_dark_mode = $view_config['darkMode'] ?? [];

        // Default dark colors (used when view has no dark mode colors saved)
        $dark_color_defaults = [
            'containerBg'   => '#1a1a2e',
            'cardBg'        => '#1d2327',
            'textColor'     => '#e0e0e0',
            'nameColor'     => '#ffffff',
            'roleColor'     => '#9ca3af',
            'starColor'     => '#f59e0b',
            'starEmptyColor'=> '#4b5563',
            'borderColor'   => '#404040',
            'quoteColor'    => '#4b5563',
        ];

        $dark_mode_container_bg = '';

        // Dark mode category color defaults
        $dark_cat_defaults = [
            'backgroundColor'            => '#374151',
            'textColor'                  => '#e5e7eb',
            'activeBackgroundColor'      => '#3b82f6',
            'activeTextColor'            => '#ffffff',
            'borderColor'                => '#4b5563',
            'descriptionColor'           => '#9ca3af',
            'descriptionBackgroundColor' => '#1f2937',
            'descriptionBorderColor'     => '#3b82f6',
        ];

        // For 'enabled', swap view colors to dark mode colors
        if ($dark_mode_setting === 'enabled') {
            $dm = array_merge($dark_color_defaults, $view_dark_mode);
            $dark_mode_container_bg = $dm['containerBg'];
            $view_config['colors'] = [
                'cardBg'         => $dm['cardBg'],
                'textColor'      => $dm['textColor'],
                'nameColor'      => $dm['nameColor'],
                'roleColor'      => $dm['roleColor'],
                'starColor'      => $dm['starColor'],
                'starEmptyColor' => $dm['starEmptyColor'],
                'borderColor'    => $dm['borderColor'],
                'quoteColor'     => $dm['quoteColor'],
            ];
            // Override category colors with dark mode values
            $dm_cats = array_merge($dark_cat_defaults, $view_dark_mode['categories'] ?? []);
            $view_config['categories']['colors'] = $dm_cats;
        }

        $layout_style = $view_config['layout']['style'] ?? 'grid';

        // Enqueue frontend assets (Swiper only for slider layout)
        self::enqueue_base_assets();
        if ($layout_style === 'slider') {
            self::enqueue_slider_assets();
        }

        // Resolve pagination config
        $pagination_view = $view_config['pagination'] ?? [];
        $pagination_enabled = false;
        $pagination_style = $pagination_view['style'] ?? 'numbers';
        $per_page = (int) ($pagination_view['perPage'] ?? 8);
        $pagination_alignment = $pagination_view['alignment'] ?? 'center';
        $load_more_label = __('Load More', 'plugixa-testimonial');

        // Shortcode config can override pagination
        if (isset($config['paginationEnabled'])) {
            $pagination_enabled = (bool) $config['paginationEnabled'];
        } else {
            $pagination_enabled = !empty($pagination_view['enabled']);
        }

        if (!empty($config['paginationStyle'])) {
            $pagination_style = $config['paginationStyle'];
        }
        if (!empty($config['perPage'])) {
            $per_page = (int) $config['perPage'];
        }
        if (!empty($config['loadMoreLabel'])) {
            $load_more_label = $config['loadMoreLabel'];
        }

        // Slider layout does not use pagination
        if ($layout_style === 'slider') {
            $pagination_enabled = false;
        }

        $per_page = max(1, min(100, $per_page));

        // Get testimonials
        $testimonials = $this->get_testimonials($config);
        $all_testimonials = $testimonials;

        if (empty($testimonials)) {
            return '<div class="pxt-custom-testimonial-root"><div class="pxt-custom-empty"><p>' . esc_html__('No testimonials found.', 'plugixa-testimonial') . '</p></div></div>';
        }

        // Calculate pagination
        $total_items = count($testimonials);
        $total_pages = $pagination_enabled && $per_page > 0 ? (int) ceil($total_items / $per_page) : 1;
        $current_page = 1;

        if ($pagination_enabled) {
            // Slice for first page only (JS handles subsequent pages via AJAX)
            $testimonials = array_slice($testimonials, 0, $per_page);
        }

        static $instance_counter = 0;
        $instance_counter++;
        $instance_id = 'pxt-' . uniqid();
        $css_vars = $this->build_css_vars($view_config);
        $this->enqueue_google_fonts($view_config);

        // Build output
        // Build slim carousel config for slider JS (layout props only)
        $carousel_attr = '';
        if ($layout_style === 'slider') {
            $carousel_config = $view_config['layout'] ?? [];
            if (empty($carousel_config['spaceBetween']) && !empty($view_config['spacing']['gap'])) {
                $carousel_config['spaceBetween'] = $view_config['spacing']['gap'];
            }
            $carousel_attr = ' data-pxt-carousel-config="' . esc_attr(wp_json_encode($carousel_config)) . '"';
        }

        // Build pagination data attribute
        $pagination_attr = '';
        if ($pagination_enabled) {
            $pagination_data = [
                'enabled'        => true,
                'style'          => $pagination_style,
                'perPage'        => $per_page,
                'currentPage'    => $current_page,
                'totalPages'     => $total_pages,
                'totalItems'     => $total_items,
                'alignment'      => $pagination_alignment,
                'loadMoreLabel'  => $load_more_label,
                'shortcodeId'    => $shortcode_id,
                'restUrl'        => esc_url_raw(rest_url('plugixatestimonial/v1/frontend/testimonials/page')),
                'nonce'          => self::public_rest_nonce(),
            ];
            $pagination_attr = ' data-pxt-pagination="' . esc_attr(wp_json_encode($pagination_data)) . '"';
        }

        // Build dark mode inline style additions
        $dark_mode_style = '';
        if ($dark_mode_setting === 'enabled' && $dark_mode_container_bg) {
            // Through a variable: wp_kses() drops an rgba() given to background-color directly.
            $dark_mode_style = '--pxt-dark-container-bg:' . $dark_mode_container_bg . ';background-color:var(--pxt-dark-container-bg);';
        }

        $output = '<div id="' . esc_attr($instance_id) . '" class="pxt-custom-testimonial-root"'
            . ' style="' . esc_attr($dark_mode_style . $css_vars) . '"'
            . $carousel_attr
            . $pagination_attr
            . '>';

        $template = 'grid';
        if (in_array($layout_style, ['grid', 'slider', 'masonry', 'list'])) {
            $template = $layout_style;
        }

        // Build category navigation HTML
        $cat_config = $view_config['categories'] ?? [];
        $cat_display_style = $cat_config['displayStyle'] ?? 'none';
        $cat_show_desc = !empty($cat_config['showCategoryDescription']);
        $category_filter_html = '';

        // Slider layout does not use category nav
        if ($cat_display_style !== 'none' && $layout_style !== 'slider') {
            $nav_categories = $this->get_categories_for_testimonials($all_testimonials);
            if (!empty($nav_categories)) {
                $category_filter_html = $this->build_category_nav_html($cat_display_style, $nav_categories, $cat_show_desc);
            }
        }

        // Build rating filter navigation HTML
        $rating_filter_config = $view_config['ratingFilter'] ?? [];
        $rating_filter_style = $rating_filter_config['displayStyle'] ?? 'none';
        $rating_filter_behavior = $rating_filter_config['behavior'] ?? 'min';
        $rating_filter_html = '';

        if ($rating_filter_style !== 'none' && $layout_style !== 'slider') {
            $unique_ratings = $this->get_ratings_for_testimonials($all_testimonials);
            if (!empty($unique_ratings)) {
                $rating_filter_html = $this->build_rating_filter_nav_html($rating_filter_style, $unique_ratings, $rating_filter_behavior);
            }
        }

        // Build items + pagination content
        $items_html = '<div class="pxt-items-container">';
        $template_file = \PlugixaTestimonial\Core\TemplateLoader::locate($template);
        if ($template_file) {
            ob_start();
            include $template_file;
            $items_html .= ob_get_clean();
        } else {
            foreach ($testimonials as $testimonial) {
                $items_html .= $this->render_testimonial_card($testimonial, $view_config);
            }
        }
        $items_html .= '</div>';

        $pagination_html = '';
        if ($pagination_enabled && $total_pages > 1) {
            $pagination_html = $this->render_pagination_html($pagination_style, $current_page, $total_pages, $pagination_alignment, $load_more_label, $view_config);
        }

        // Assemble output based on category display style
        if ($cat_display_style === 'sidebar' && !empty($category_filter_html)) {
            $output .= '<div class="pxt-category-layout-sidebar">';
            $output .= $category_filter_html;
            $output .= '<div class="pxt-category-main">';
            $output .= $rating_filter_html;
            $output .= $items_html;
            $output .= $pagination_html;
            $output .= '</div>'; // .pxt-category-main
            $output .= '</div>'; // .pxt-category-layout-sidebar
        } else {
            $output .= $category_filter_html;
            $output .= $rating_filter_html;
            $output .= $items_html;
            $output .= $pagination_html;
        }

        $output .= '</div>';

        // For 'auto' mode, the @media (prefers-color-scheme: dark) overrides go through
        // wp_add_inline_style() rather than a <style> block concatenated into the
        // shortcode return value: markup returned from a shortcode is late-escaped with
        // wp_kses() below, and the allowlist deliberately carries no <style> element.
        if ($dark_mode_setting === 'auto') {
            $dm = array_merge($dark_color_defaults, $view_dark_mode);
            $dm_cats = array_merge($dark_cat_defaults, $view_dark_mode['categories'] ?? []);

            $dark_declarations = [
                'background-color'          => $dm['containerBg'],
                '--pxt-card-bg'             => $dm['cardBg'],
                '--pxt-text-color'          => $dm['textColor'],
                '--pxt-name-color'          => $dm['nameColor'],
                '--pxt-role-color'          => $dm['roleColor'],
                '--pxt-star-color'          => $dm['starColor'],
                '--pxt-star-empty-color'    => $dm['starEmptyColor'],
                '--pxt-border-color'        => $dm['borderColor'],
                '--pxt-quote-color'         => $dm['quoteColor'],
                '--pxt-cat-bg'              => $dm_cats['backgroundColor'],
                '--pxt-cat-text'            => $dm_cats['textColor'],
                '--pxt-cat-active-bg'       => $dm_cats['activeBackgroundColor'],
                '--pxt-cat-active-text'     => $dm_cats['activeTextColor'],
                '--pxt-cat-border'          => $dm_cats['borderColor'],
                '--pxt-cat-desc-color'      => $dm_cats['descriptionColor'],
                '--pxt-cat-desc-bg'         => $dm_cats['descriptionBackgroundColor'],
                '--pxt-cat-desc-border'     => $dm_cats['descriptionBorderColor'],
            ];

            $dark_css = '';
            foreach ($dark_declarations as $prop => $value) {
                $dark_css .= $prop . ':' . self::sanitize_css_value((string) $value) . ';';
            }

            self::enqueue_base_assets();
            wp_add_inline_style(
                self::FRONTEND_HANDLE,
                '@media(prefers-color-scheme:dark){#' . self::sanitize_css_value($instance_id) . '{' . $dark_css . '}}'
            );
        }

        $settings = get_option('plugixatestimonial_settings', []);

        // Schema (JSON-LD). The payload is queued and printed on wp_footer through
        // wp_print_inline_script_tag() - never hand-concatenated into the markup, which
        // is what the "use wp_enqueue commands" review flag is about.
        $schema_enabled_global = !empty($settings['enable_schema']);
        $schema_enabled_shortcode = $config['schema'] ?? true;
        if ($schema_enabled_global && $schema_enabled_shortcode) {
            $schema = new Schema();
            self::queue_schema_json($schema->get_testimonial_schema_json($all_testimonials));
        }

        // Late escape: the assembled fragment is passed through wp_kses() with the
        // plugin's allowlist at the single point where it leaves this method.
        return wp_kses($output, \PlugixaTestimonial\Core\Kses::allowed_html());
    }

    /**
     * The REST nonce for the public front-end calls (submit, paging, social proof).
     *
     * Empty for a signed-out visitor. Those endpoints are public and need no nonce,
     * and a page cache keeps a signed-out copy far longer than a nonce lives: core
     * answers an expired X-WP-Nonce with 403 even when nobody is signed in, so a
     * cached page would break every form and "load more" a day after it was cached.
     * A signed-in user still gets one, so a submission is attributed to them.
     */
    public static function public_rest_nonce(): string {
        return is_user_logged_in() ? wp_create_nonce('wp_rest') : '';
    }

    /**
     * Strip anything that could break out of a CSS declaration.
     *
     * Values reach here from the view builder (admin-authored colours, lengths and
     * font stacks), but they are still untrusted input as far as the stylesheet is
     * concerned: a stray `;` or `}` would let one value inject an unrelated rule.
     *
     * @param string $value Raw declaration value.
     * @return string Value safe to interpolate into a stylesheet.
     */
    private static function sanitize_css_value(string $value): string {
        $value = wp_strip_all_tags($value);
        // Drop declaration/rule terminators, at-rule and function escapes.
        $value = preg_replace('/[;{}<>"\'\\\\]/', '', $value);
        $value = preg_replace('/(url|expression|javascript|behavior|@import)\s*[:(]/i', '', $value);
        return trim((string) $value);
    }

    /**
     * Collect a JSON-LD payload and print it on wp_footer.
     *
     * Each shortcode instance queues its own graph; they are emitted as separate
     * application/ld+json blocks by wp_print_inline_script_tag(), which is the core
     * API for inline script output (it applies the script filters and honours CSP
     * nonce handling that a hand-written <script> tag bypasses).
     *
     * @param string $json Encoded JSON-LD, or '' to skip.
     */
    private static function queue_schema_json(string $json): void {
        if ($json === '') {
            return;
        }

        static $queued = [];
        static $hooked = false;

        $queued[] = $json;

        if (!$hooked) {
            $hooked = true;
            add_action('wp_footer', static function () use (&$queued) {
                foreach ($queued as $payload) {
                    wp_print_inline_script_tag($payload, ['type' => 'application/ld+json']);
                }
                $queued = [];
            }, 20);
        }
    }

    /**
     * Build CSS custom properties string from view config for inline style
     */
    private function build_css_vars(array $view_config): string {
        $colors = $view_config['colors'] ?? [];
        $typography = $view_config['typography'] ?? [];
        $spacing = $view_config['spacing'] ?? [];
        $borders = $view_config['borders'] ?? [];
        $shadows = $view_config['shadows'] ?? [];
        $photo = $view_config['photo'] ?? [];
        $layout = $view_config['layout'] ?? [];

        $shadow_value = !empty($shadows['enabled']) ? ($shadows['value'] ?? '0 1px 3px rgba(0,0,0,0.1)') : 'none';

        $photo_border_width = (int) ($photo['borderWidth'] ?? 0);
        $photo_border_color = $photo['borderColor'] ?? '#e5e7eb';
        $photo_border_style = $photo['borderStyle'] ?? 'none';
        $photo_border = $photo_border_style !== 'none' ? "{$photo_border_width}px {$photo_border_style} {$photo_border_color}" : 'none';
        $photo_filter = !empty($photo['grayscale']) ? 'grayscale(100%)' : 'none';
        $photo_shadow = !empty($photo['shadowEnabled']) ? ($photo['shadowValue'] ?? '0 2px 8px rgba(0,0,0,0.15)') : 'none';

        $photo_size_unit = $photo['sizeUnit'] ?? 'px';
        $photo_size = ($photo['size'] ?? 60) . $photo_size_unit;

        // Columns for grid/masonry
        $columns = $layout['columns'] ?? ['desktop' => 3, 'tablet' => 2, 'mobile' => 1];
        // Slides for slider
        $slides = $layout['slidesPerView'] ?? ['desktop' => 3, 'tablet' => 2, 'mobile' => 1];

        $vars = [
            '--pxt-card-bg'           => $colors['cardBg'] ?? '#ffffff',
            '--pxt-text-color'        => $colors['textColor'] ?? '#1d2327',
            '--pxt-name-color'        => $colors['nameColor'] ?? '#1d2327',
            '--pxt-role-color'        => $colors['roleColor'] ?? '#646970',
            '--pxt-star-color'        => $colors['starColor'] ?? '#f59e0b',
            '--pxt-star-empty-color'  => $colors['starEmptyColor'] ?? '#d1d5db',
            '--pxt-border-color'      => $colors['borderColor'] ?? '#e5e7eb',
            '--pxt-quote-color'       => $colors['quoteColor'] ?? '#e5e7eb',
            '--pxt-text-font-size'    => ($typography['textFontSize'] ?? 14) . 'px',
            '--pxt-name-font-size'    => ($typography['nameFontSize'] ?? 16) . 'px',
            '--pxt-name-font-weight'  => (string) ($typography['nameFontWeight'] ?? 600),
            '--pxt-role-font-size'    => ($typography['roleFontSize'] ?? 13) . 'px',
            '--pxt-text-font-family'  => $this->sanitize_font_family($typography['textFontFamily'] ?? 'inherit'),
            '--pxt-name-font-family'  => $this->sanitize_font_family($typography['nameFontFamily'] ?? 'inherit'),
            '--pxt-role-font-family'  => $this->sanitize_font_family($typography['roleFontFamily'] ?? 'inherit'),
            '--pxt-gap'               => ($spacing['gap'] ?? 24) . 'px',
            '--pxt-card-padding'      => ($spacing['cardPadding'] ?? 24) . 'px',
            '--pxt-border-radius'     => ($borders['radius'] ?? 8) . 'px',
            '--pxt-border-width'      => ($borders['width'] ?? 1) . 'px',
            '--pxt-shadow'            => $shadow_value,
            '--pxt-columns-desktop'   => (string) (int) ($columns['desktop'] ?? 3),
            '--pxt-columns-tablet'    => (string) (int) ($columns['tablet'] ?? 2),
            '--pxt-columns-mobile'    => (string) (int) ($columns['mobile'] ?? 1),
            '--pxt-slides-desktop'    => (string) (int) ($slides['desktop'] ?? 3),
            '--pxt-slides-tablet'     => (string) (int) ($slides['tablet'] ?? 2),
            '--pxt-slides-mobile'     => (string) (int) ($slides['mobile'] ?? 1),
            '--pxt-photo-size'        => $photo_size,
            '--pxt-photo-radius'      => ($photo['borderRadius'] ?? 50) . '%',
            '--pxt-photo-border'      => $photo_border,
            '--pxt-photo-opacity'     => (string) ((float) ($photo['opacity'] ?? 1.0)),
            '--pxt-photo-filter'      => $photo_filter,
            '--pxt-photo-shadow'      => $photo_shadow,
            '--pxt-photo-hover-scale' => (string) ((float) ($photo['hoverScale'] ?? 1.0)),
            '--pxt-photo-fallback-bg'   => $photo['fallbackBgColor'] ?? '#e5e7eb',
            '--pxt-photo-fallback-text' => $photo['fallbackTextColor'] ?? '#6b7280',
        ];

        // Category navigation CSS vars
        $categories = $view_config['categories'] ?? [];
        $cat_colors = $categories['colors'] ?? [];
        $cat_typo = $categories['typography'] ?? [];
        $cat_spacing = $categories['spacing'] ?? [];
        $cat_border = $categories['border'] ?? [];

        $vars['--pxt-cat-bg']              = $cat_colors['backgroundColor'] ?? '#ffffff';
        $vars['--pxt-cat-text']            = $cat_colors['textColor'] ?? '#1d2327';
        $vars['--pxt-cat-active-bg']       = $cat_colors['activeBackgroundColor'] ?? '#2271b1';
        $vars['--pxt-cat-active-text']     = $cat_colors['activeTextColor'] ?? '#ffffff';
        $vars['--pxt-cat-border']          = $cat_colors['borderColor'] ?? '#c3c4c7';
        $vars['--pxt-cat-desc-color']      = $cat_colors['descriptionColor'] ?? '#6b7280';
        $vars['--pxt-cat-desc-bg']         = $cat_colors['descriptionBackgroundColor'] ?? '#f8fafc';
        $vars['--pxt-cat-desc-border']     = $cat_colors['descriptionBorderColor'] ?? '#2271b1';
        $vars['--pxt-cat-font-size']       = ($cat_typo['fontSize'] ?? 14) . 'px';
        $vars['--pxt-cat-font-weight']     = (string) ($cat_typo['fontWeight'] ?? 500);
        $vars['--pxt-cat-font-family']     = $cat_typo['fontFamily'] ?? 'inherit';
        $vars['--pxt-cat-padding']         = ($cat_spacing['padding'] ?? 12) . 'px';
        $vars['--pxt-cat-gap']             = ($cat_spacing['gap'] ?? 8) . 'px';
        $vars['--pxt-cat-border-width']    = ($cat_border['width'] ?? 1) . 'px';
        $vars['--pxt-cat-border-radius']   = ($cat_border['radius'] ?? 4) . 'px';

        // Pagination CSS vars
        $pagination = $view_config['pagination'] ?? [];
        if (!empty($pagination)) {
            $numbers = $pagination['numbers'] ?? [];
            $load_more = $pagination['loadMore'] ?? [];
            $infinite = $pagination['infiniteScroll'] ?? [];

            $vars['--pxt-pagination-bg']             = $numbers['bg'] ?? '#ffffff';
            $vars['--pxt-pagination-text']           = $numbers['text'] ?? '#1d2327';
            $vars['--pxt-pagination-border']         = $numbers['border'] ?? '#c3c4c7';
            $vars['--pxt-pagination-active-bg']      = $numbers['activeBg'] ?? '#2271b1';
            $vars['--pxt-pagination-active-text']    = $numbers['activeText'] ?? '#ffffff';
            $vars['--pxt-pagination-active-border']  = $numbers['activeBorder'] ?? '#2271b1';
            $vars['--pxt-pagination-loadmore-bg']    = $load_more['bg'] ?? '#2271b1';
            $vars['--pxt-pagination-loadmore-text']  = $load_more['text'] ?? '#ffffff';
            $vars['--pxt-pagination-loadmore-border']= $load_more['border'] ?? '#2271b1';
            $vars['--pxt-pagination-loadmore-hover-bg']    = $load_more['hoverBg'] ?? '#135e96';
            $vars['--pxt-pagination-loadmore-hover-text']  = $load_more['hoverText'] ?? '#ffffff';
            $vars['--pxt-pagination-loadmore-hover-border']= $load_more['hoverBorder'] ?? '#135e96';
            $vars['--pxt-pagination-spinner-color']  = $infinite['spinnerColor'] ?? '#2271b1';
            $vars['--pxt-pagination-scroll-text']    = $infinite['textColor'] ?? '#646970';
        }

        $parts = [];
        foreach ($vars as $prop => $value) {
            $parts[] = $prop . ':' . $value;
        }

        return implode(';', $parts);
    }

    /**
     * Sanitize a font family name for use in CSS.
     * Wraps multi-word names in quotes, appends a generic fallback.
     */
    private function sanitize_font_family(string $family): string {
        $family = trim($family);

        if ($family === 'inherit' || $family === '') {
            return 'inherit';
        }

        // If it already contains quotes or commas, return as-is
        if (strpos($family, '"') !== false || strpos($family, ',') !== false) {
            return $family;
        }

        // Multi-word font names need quotes
        if (strpos($family, ' ') !== false) {
            return '"' . $family . '", sans-serif';
        }

        return $family . ', sans-serif';
    }

    /**
     * Enqueue self-hosted font CSS for non-inherit font families.
     *
     * Fonts are downloaded from Google and cached locally when a view is saved
     * (see PlugixaTestimonial\Modules\GoogleFonts\FontManager). The frontend only ever serves
     * the cached local copy — it never contacts Google, so no visitor data is
     * sent to a third party. If a family was not cached (e.g. a view saved
     * before this feature, a system font, or a failed download), nothing is
     * enqueued and the CSS font-family fallback applies.
     */
    private function enqueue_google_fonts(array $view_config): void {
        $families = \PlugixaTestimonial\Modules\GoogleFonts\FontManager::get_font_families_from_config($view_config);

        if (empty($families)) {
            return;
        }

        foreach ($families as $family) {
            $css_url = \PlugixaTestimonial\Modules\GoogleFonts\FontManager::get_local_css_url($family);
            if ($css_url === null) {
                // Not self-hosted — skip; never load fonts from a third-party CDN here.
                continue;
            }

            $handle = 'plugixatestimonial-font-' . \PlugixaTestimonial\Modules\GoogleFonts\FontManager::slug($family);
            if (!wp_style_is($handle, 'enqueued')) {
                wp_enqueue_style(
                    $handle,
                    $css_url,
                    [],
                    defined('PLUGIXA_TESTIMONIAL_VERSION') ? PLUGIXA_TESTIMONIAL_VERSION : null
                );
            }
        }
    }

    /**
     * Render submission form shortcode
     * Usage: [plugixa_testimonial_form]
     */
    public function render_form($atts): string {
        $settings = get_option('plugixatestimonial_settings', []);

        if (empty($settings['form_enabled'])) {
            return '';
        }

        self::enqueue_base_assets();

        $instance_id = 'pxt-form-' . wp_unique_id();
        $form_style = $settings['form_style'] ?? 'inline';
        $photo_upload_enabled = !empty($settings['photo_upload_enabled']);

        // Query custom field definitions for frontend form
        global $wpdb;
        $cf_table = $wpdb->prefix . 'plugixatestimonial_custom_fields';

        // The language filter is a bound gate inside one constant-literal WHERE, so
        // the query text does not change when multilingual is off. Gate 0 disables it.
        $multilingual = \PlugixaTestimonial\Core\Multilingual::instance();
        $current_lang = $multilingual->get_current_language();
        $lang_active  = $multilingual->is_multilingual_active() ? 1 : 0;
        $lang_values  = $lang_active
            ? $multilingual->language_filter_values($cf_table, $current_lang, $multilingual->get_default_language())
            : ['', 0, '', $cf_table, ''];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $custom_fields = $wpdb->get_results($wpdb->prepare(
            "SELECT cf.field_key, cf.field_label, cf.field_type, cf.field_options, cf.placeholder, cf.is_required
             FROM %i cf
             WHERE cf.show_in_frontend = 1
               AND cf.status = 'publish'
               AND ( %d = 0 OR ( cf.language_code = %s OR ( %d = 1 AND cf.language_code = %s AND cf.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR cf.language_code IS NULL ) )
             ORDER BY cf.menu_order ASC",
            $cf_table,
            $lang_active,
            ...$lang_values
        ), ARRAY_A);

        if ($custom_fields) {
            foreach ($custom_fields as &$cf) {
                $cf['is_required'] = (bool) $cf['is_required'];
                if (!empty($cf['field_options'])) {
                    $cf['field_options'] = json_decode($cf['field_options'], true) ?: [];
                } else {
                    $cf['field_options'] = [];
                }
            }
            unset($cf);
        } else {
            $custom_fields = [];
        }

        $template_file = \PlugixaTestimonial\Core\TemplateLoader::locate('submission-form');
        if ($template_file) {
            ob_start();
            include $template_file;
            // Late escape at the single point the fragment leaves this method.
            return wp_kses(ob_get_clean(), \PlugixaTestimonial\Core\Kses::allowed_html());
        }

        // Fallback inline form
        $required_fields = $settings['required_fields'] ?? ['client_name', 'testimonial_text', 'rating'];
        $nonce = self::public_rest_nonce();
        $api_url = esc_url_raw(rest_url('plugixatestimonial/v1/frontend/submit'));
        $success_message = esc_attr($settings['success_message'] ?? 'Thank you for your testimonial!');

        $output = '<div class="pxt-custom-form-wrapper" data-api-url="' . esc_attr($api_url) . '" data-nonce="' . esc_attr($nonce) . '" data-success-message="' . $success_message . '">';
        $output .= '<form class="plugixa-testimonial-form pxt:flex pxt:flex-col pxt:gap-4" method="post">';

        $output .= '<div><label>' . esc_html__('Your Name', 'plugixa-testimonial') . (in_array('client_name', $required_fields) ? ' <span class="pxt-custom-required">*</span>' : '') . '</label>';
        $output .= '<input type="text" name="client_name" ' . (in_array('client_name', $required_fields) ? 'required' : '') . ' /></div>';

        $output .= '<div><label>' . esc_html__('Your Role/Title', 'plugixa-testimonial') . '</label>';
        $output .= '<input type="text" name="client_role" /></div>';

        $output .= '<div><label>' . esc_html__('Company', 'plugixa-testimonial') . '</label>';
        $output .= '<input type="text" name="client_company" /></div>';

        $output .= '<div><label>' . esc_html__('Rating', 'plugixa-testimonial') . ' <span class="pxt-custom-required">*</span></label>';
        $output .= '<div class="pxt-custom-star-input">';
        for ($i = 1; $i <= 5; $i++) {
            $output .= '<span class="pxt-custom-star-btn active" data-value="' . $i . '">&#9733;</span>';
        }
        $output .= '<input type="hidden" name="rating" value="5" /></div></div>';

        $output .= '<div><label>' . esc_html__('Your Testimonial', 'plugixa-testimonial') . (in_array('testimonial_text', $required_fields) ? ' <span class="pxt-custom-required">*</span>' : '') . '</label>';
        $output .= '<textarea name="testimonial_text" rows="5" ' . (in_array('testimonial_text', $required_fields) ? 'required' : '') . '></textarea></div>';

        $output .= '<div><label>' . esc_html__('Website URL', 'plugixa-testimonial') . '</label>';
        $output .= '<input type="url" name="website_url" /></div>';

        $output .= '<div><button type="submit" class="pxt-custom-submit-btn">' . esc_html__('Submit Testimonial', 'plugixa-testimonial') . '</button></div>';
        $output .= '<div class="pxt-custom-form-message" style="display:none;"></div>';
        $output .= '</form></div>';

        // Late escape at the single point the fragment leaves this method.
        return wp_kses($output, \PlugixaTestimonial\Core\Kses::allowed_html());
    }

    /**
     * Build category navigation HTML based on display style.
     *
     * @param string $display_style tabs|pills|dropdown|sidebar
     * @param array  $categories    Array of category rows with id, name, description
     * @param bool   $show_desc     Whether to show category descriptions
     * @return string HTML string
     */
    private function build_category_nav_html(string $display_style, array $categories, bool $show_desc): string {
        if (empty($categories) || $display_style === 'none') {
            return '';
        }

        $all_label = esc_html__('All', 'plugixa-testimonial');
        $all_categories_label = esc_html__('All Categories', 'plugixa-testimonial');

        if ($display_style === 'dropdown') {
            $html = '<div class="pxt-category-nav pxt-category-nav--dropdown">';
            $html .= '<select class="pxt-category-select">';
            $html .= '<option value="all">' . $all_categories_label . '</option>';
            foreach ($categories as $cat) {
                $desc_attr = $show_desc && !empty($cat['description'])
                    ? ' data-description="' . esc_attr($cat['description']) . '"'
                    : '';
                $html .= '<option value="' . esc_attr($cat['id']) . '"' . $desc_attr . '>'
                    . esc_html($cat['name']) . '</option>';
            }
            $html .= '</select></div>';
            if ($show_desc) {
                $html .= '<div class="pxt-category-description"></div>';
            }
            return $html;
        }

        // tabs, pills, sidebar — all use buttons
        $modifier = $display_style; // tabs|pills|sidebar
        $html = '<div class="pxt-category-nav pxt-category-nav--' . esc_attr($modifier) . '">';
        $html .= '<button type="button" class="pxt-category-item pxt-category-item--active" data-category="all">'
            . $all_label . '</button>';

        foreach ($categories as $cat) {
            $desc_attr = $show_desc && !empty($cat['description'])
                ? ' data-description="' . esc_attr($cat['description']) . '"'
                : '';
            $html .= '<button type="button" class="pxt-category-item" data-category="'
                . esc_attr($cat['id']) . '"' . $desc_attr . '>'
                . esc_html($cat['name']) . '</button>';
        }
        $html .= '</div>';

        if ($show_desc) {
            $html .= '<div class="pxt-category-description"></div>';
        }

        return $html;
    }

    /**
     * Query categories that have testimonials in the given set.
     *
     * @param array $testimonials Array of testimonials (must have category_ids)
     * @return array Array of category rows [id, name, slug, description]
     */
    private function get_categories_for_testimonials(array $testimonials): array {
        $all_cat_ids = [];
        foreach ($testimonials as $t) {
            foreach (($t['category_ids'] ?? []) as $cid) {
                $all_cat_ids[$cid] = true;
            }
        }

        if (empty($all_cat_ids)) {
            return [];
        }

        global $wpdb;
        $cat_table = $wpdb->prefix . 'plugixatestimonial_categories';
        $ids = array_keys($all_cat_ids);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, slug, description FROM %i WHERE FIND_IN_SET(id, %s) ORDER BY display_order ASC, name ASC",
                $cat_table,
                implode(',', array_map('intval', $ids))
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Extract unique ratings present in the testimonial set, sorted descending.
     */
    private function get_ratings_for_testimonials(array $testimonials): array {
        $ratings = [];
        foreach ($testimonials as $t) {
            $r = (int) ($t['rating'] ?? 0);
            if ($r >= 1 && $r <= 5) {
                $ratings[$r] = true;
            }
        }
        $result = array_keys($ratings);
        rsort($result);
        return $result;
    }

    /**
     * Build rating filter navigation HTML.
     */
    private function build_rating_filter_nav_html(string $display_style, array $ratings, string $behavior): string {
        if (empty($ratings) || $display_style === 'none') {
            return '';
        }

        $all_label = esc_html__('All', 'plugixa-testimonial');
        $star = "\xe2\x98\x85"; // ★ UTF-8

        $make_label = function (int $rating) use ($behavior, $star): string {
            $stars = str_repeat($star, $rating);
            if ($behavior === 'min') {
                return $stars . '+';
            }
            return $stars;
        };

        if ($display_style === 'dropdown') {
            $html = '<div class="pxt-rating-filter-nav pxt-rating-filter-nav--dropdown" data-pxt-rating-behavior="' . esc_attr($behavior) . '">';
            $html .= '<select class="pxt-rating-filter-select">';
            $html .= '<option value="all">' . $all_label . '</option>';
            foreach ($ratings as $r) {
                $html .= '<option value="' . esc_attr($r) . '">' . esc_html($make_label($r)) . '</option>';
            }
            $html .= '</select></div>';
            return $html;
        }

        // tabs, pills — use buttons
        $modifier = $display_style; // tabs|pills
        $html = '<div class="pxt-rating-filter-nav pxt-rating-filter-nav--' . esc_attr($modifier) . '" data-pxt-rating-behavior="' . esc_attr($behavior) . '">';
        $html .= '<button type="button" class="pxt-rating-filter-item pxt-rating-filter-item--active" data-rating="all">'
            . $all_label . '</button>';

        foreach ($ratings as $r) {
            $html .= '<button type="button" class="pxt-rating-filter-item" data-rating="'
                . esc_attr($r) . '">'
                . esc_html($make_label($r)) . '</button>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a single testimonial card (fallback)
     */
    private function render_testimonial_card(array $testimonial, array $view_config): string {
        $photo_url = '';
        if (!empty($testimonial['client_photo_id'])) {
            $photo_url = wp_get_attachment_image_url((int) $testimonial['client_photo_id'], 'thumbnail') ?: '';
        }

        $output = '<div class="pxt-custom-card" data-pxt-rating="' . esc_attr((int) $testimonial['rating']) . '">';

        if ($photo_url) {
            $output .= '<div class="pxt-custom-photo"><img src="' . esc_url($photo_url) . '" alt="' . esc_attr($testimonial['client_name']) . '" /></div>';
        }

        // Stars
        $output .= '<div class="pxt-custom-rating">';
        for ($i = 1; $i <= 5; $i++) {
            $output .= '<span class="pxt-custom-star ' . ($i <= $testimonial['rating'] ? 'pxt-custom-star--filled' : 'pxt-custom-star--empty') . '">&#9733;</span>';
        }
        $output .= '</div>';

        $output .= '<div class="pxt-custom-text">' . wp_kses_post($testimonial['testimonial_text']) . '</div>';
        $output .= '<div class="pxt-custom-author">';
        $output .= '<strong class="pxt-custom-name">' . esc_html($testimonial['client_name']) . '</strong>';

        $meta = [];
        if (!empty($testimonial['client_role'])) {
            $meta[] = esc_html($testimonial['client_role']);
        }
        if (!empty($testimonial['client_company'])) {
            $meta[] = esc_html($testimonial['client_company']);
        }
        if (!empty($meta)) {
            $output .= '<span class="pxt-custom-meta">' . implode(', ', $meta) . '</span>';
        }

        $output .= '</div></div>';

        return $output;
    }

    /**
     * Get testimonials based on shortcode config
     */
    private function get_testimonials(array $config): array {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // Determine ordering strategy FIRST to avoid alias collision
        $cat_ids = !empty($config['categories']) ? array_map('intval', $config['categories']) : [];
        $orderby = sanitize_key($config['orderby'] ?? 'display_order');
        $allowed = ['display_order', 'created_at', 'rating', 'client_name'];
        $orderby = in_array($orderby, $allowed) ? $orderby : 'display_order';
        $order = strtoupper($config['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $use_pivot_order = ($orderby === 'display_order' && count($cat_ids) === 1);

        // Every optional filter is a BOUND GATE inside one constant-literal WHERE
        // rather than an appended fragment -- see Modules\Frontend\Http\FrontendApi::get_testimonials()
        // for the rationale. A gate bound to 0 disables its test.
        $multilingual = \PlugixaTestimonial\Core\Multilingual::instance();
        $lang_active  = $multilingual->is_multilingual_active() ? 1 : 0;
        $lang_values  = $lang_active
            ? $multilingual->language_filter_values(
                $table,
                $multilingual->get_current_language(),
                $multilingual->get_default_language()
            )
            : ['', 0, '', $table, ''];

        $cat_gate      = (!empty($cat_ids) && !$use_pivot_order) ? 1 : 0;
        $cat_csv       = implode(',', $cat_ids);
        $featured_gate = !empty($config['featured_only']) ? 1 : 0;
        $rating_gate   = !empty($config['min_rating']) ? 1 : 0;
        $min_rating    = (int) ($config['min_rating'] ?? 0);

        // LIMIT is always present and always bound. "No limit" binds a sentinel rather
        // than dropping the clause, so the query text never varies.
        $limit       = (int) ($config['limit'] ?? -1);
        $limit_value = $limit > 0 ? $limit : PHP_INT_MAX;

        $where_values = array_merge(
            [$lang_active],
            $lang_values,
            [$cat_gate, $pivot_table, $cat_csv, $featured_gate, $rating_gate, $min_rating]
        );

        // The public frontend list is cached: this is the query behind every rendered
        // shortcode. The key captures every input that changes the result and does NOT
        // embed the SQL text (see includes/class-cache.php).
        $cache_key = 'shortcode_list|' . wp_json_encode([
            $where_values, $orderby, $order, $use_pivot_order, $cat_ids, $limit,
        ]);

        $testimonials = Cache::remember('testimonials', $cache_key, function () use (
            $wpdb, $table, $pivot_table, $order, $orderby,
            $where_values, $cat_ids, $use_pivot_order, $limit_value
        ) {
            if ($use_pivot_order && $order === 'DESC') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT t.* FROM %i t
                     INNER JOIN %i tc ON t.id = tc.testimonial_id AND tc.category_id = %d
                     WHERE t.status = 'approved'
                       AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                       AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                       AND ( %d = 0 OR t.is_featured = 1 )
                       AND ( %d = 0 OR t.rating >= %d )
                     ORDER BY tc.display_order DESC
                     LIMIT %d",
                    $table,
                    $pivot_table,
                    $cat_ids[0],
                    ...array_merge($where_values, [$limit_value])
                ), ARRAY_A) ?: [];
            }

            if ($use_pivot_order) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT t.* FROM %i t
                     INNER JOIN %i tc ON t.id = tc.testimonial_id AND tc.category_id = %d
                     WHERE t.status = 'approved'
                       AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                       AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                       AND ( %d = 0 OR t.is_featured = 1 )
                       AND ( %d = 0 OR t.rating >= %d )
                     ORDER BY tc.display_order ASC
                     LIMIT %d",
                    $table,
                    $pivot_table,
                    $cat_ids[0],
                    ...array_merge($where_values, [$limit_value])
                ), ARRAY_A) ?: [];
            }

            if ($order === 'DESC') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT t.* FROM %i t
                     WHERE t.status = 'approved'
                       AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                       AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                       AND ( %d = 0 OR t.is_featured = 1 )
                       AND ( %d = 0 OR t.rating >= %d )
                     ORDER BY t.%i DESC
                     LIMIT %d",
                    $table,
                    ...array_merge($where_values, [$orderby, $limit_value])
                ), ARRAY_A) ?: [];
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
            return $wpdb->get_results($wpdb->prepare(
                "SELECT t.* FROM %i t
                 WHERE t.status = 'approved'
                   AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                   AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                   AND ( %d = 0 OR t.is_featured = 1 )
                   AND ( %d = 0 OR t.rating >= %d )
                 ORDER BY t.%i ASC
                 LIMIT %d",
                $table,
                ...array_merge($where_values, [$orderby, $limit_value])
            ), ARRAY_A) ?: [];
        });

        // Batch-query category IDs + per-category display_order for all testimonials
        if (!empty($testimonials)) {
            $t_ids = array_column($testimonials, 'id');
            $t_ids_csv = implode(',', array_map('intval', $t_ids));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $cat_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT testimonial_id, category_id, display_order FROM %i WHERE FIND_IN_SET(testimonial_id, %s)",
                    $pivot_table,
                    $t_ids_csv
                ),
                ARRAY_A
            );

            $cat_map = [];
            $order_map = [];
            foreach ($cat_rows as $row) {
                $tid = (int) $row['testimonial_id'];
                $cid = (int) $row['category_id'];
                $cat_map[$tid][] = $cid;
                $order_map[$tid][$cid] = (int) $row['display_order'];
            }

            // Batch-load custom field meta values
            $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $meta_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT testimonial_id, meta_key, meta_value FROM %i WHERE FIND_IN_SET(testimonial_id, %s)",
                    $meta_table,
                    $t_ids_csv
                ),
                ARRAY_A
            );

            $meta_map = [];
            foreach ($meta_rows as $row) {
                $tid = (int) $row['testimonial_id'];
                $meta_map[$tid][$row['meta_key']] = $row['meta_value'];
            }

            foreach ($testimonials as $idx => &$t) {
                $tid = (int) $t['id'];
                $t['category_ids'] = $cat_map[$tid] ?? [];
                $t['category_orders'] = $order_map[$tid] ?? [];
                $t['global_order'] = $idx;
                $t['custom_fields'] = $meta_map[$tid] ?? [];
            }
            unset($t);
        }

        return $testimonials;
    }

    /**
     * Render custom field values for a testimonial in the frontend
     */
    public function render_custom_fields(array $testimonial): string {
        global $wpdb;

        $custom_fields_data = $testimonial['custom_fields'] ?? [];
        if (empty($custom_fields_data)) {
            return '';
        }

        $cf_table = $wpdb->prefix . 'plugixatestimonial_custom_fields';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); this single indexed lookup runs once per shortcode render and is not separately cached.
        $definitions = $wpdb->get_results(
            $wpdb->prepare("SELECT field_key, field_label, field_type FROM %i WHERE show_in_frontend = 1 ORDER BY menu_order ASC", $cf_table),
            ARRAY_A
        );

        if (empty($definitions)) {
            return '';
        }

        $html = '';
        foreach ($definitions as $def) {
            $key = $def['field_key'];
            $value = $custom_fields_data[$key] ?? '';

            if ($value === '' || $value === null) {
                continue;
            }

            $label = esc_html($def['field_label']);
            $type = $def['field_type'];

            switch ($type) {
                case 'url':
                    $formatted = '<a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html($value) . '</a>';
                    break;
                case 'checkbox':
                    $formatted = esc_html($value === '1' || $value === 'true' ? __('Yes', 'plugixa-testimonial') : __('No', 'plugixa-testimonial'));
                    break;
                case 'color':
                    // A visitor can submit this value, so it is validated as a colour before
                    // it reaches a style attribute; anything else is shown as text only.
                    // It travels in a custom property because wp_kses() (safecss_filter_attr)
                    // drops rgb()/rgba() from an ordinary property but keeps it in a variable.
                    $color = \PlugixaTestimonial\Core\ViewStyle::color((string) $value);
                    $formatted = ($color !== ''
                        ? '<span class="pxt-color-swatch" style="--pxt-swatch:' . esc_attr($color) . ';background-color:var(--pxt-swatch)"></span> '
                        : '') . esc_html($value);
                    break;
                default:
                    $formatted = esc_html($value);
                    break;
            }

            $html .= '<div class="pxt-custom-field pxt-custom-field--' . esc_attr($type) . '">';
            $html .= '<span class="pxt-custom-field-label">' . $label . ':</span> ';
            $html .= '<span class="pxt-custom-field-value">' . $formatted . '</span>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render video embed for a testimonial
     */
    public function render_video(array $testimonial): string {
        $video_url = $testimonial['video_url'] ?? '';
        if (empty($video_url)) {
            return '';
        }

        $video_type = self::detect_video_type($video_url);

        if ($video_type === 'youtube') {
            $video_id = self::extract_youtube_id($video_url);
            if (!$video_id) return '';
            return '<div class="pxt-video-wrapper">'
                . '<div class="pxt-video-thumb" data-pxt-video-type="youtube" data-pxt-video-id="' . esc_attr($video_id) . '">'
                . '<img src="https://img.youtube.com/vi/' . esc_attr($video_id) . '/hqdefault.jpg" alt="" loading="lazy" />'
                . '<button class="pxt-video-play" aria-label="Play video"><svg viewBox="0 0 68 48" width="68"><path d="M66.5 7.7s-.7-4.7-2.8-6.8C60.7-2 57.2-2 55.6-2.2 46.5-3 34-3 34-3s-12.5 0-21.6 .8C10.8-2 7.3-2 4.3 .9 2.2 3 1.5 7.7 1.5 7.7S.8 13.2.8 18.7v5.1c0 5.5.7 11 .7 11s.7 4.7 2.8 6.8c3 3.1 6.9 3 8.7 3.3 6.3.6 26.8.9 26.8.9s12.5 0 21.6-.8c1.6-.2 5.1-.2 8.1-3.1 2.1-2.1 2.8-6.8 2.8-6.8s.7-5.5.7-11v-5.1c0-5.5-.7-11-.7-11z" fill="#212121" fill-opacity=".8"/><path d="M45 24L27 14v20" fill="#fff"/></svg></button>'
                . '</div></div>';
        }

        if ($video_type === 'vimeo') {
            $video_id = self::extract_vimeo_id($video_url);
            if (!$video_id) return '';
            return '<div class="pxt-video-wrapper">'
                . '<div class="pxt-video-thumb" data-pxt-video-type="vimeo" data-pxt-video-id="' . esc_attr($video_id) . '">'
                . '<div class="pxt-video-placeholder">Vimeo</div>'
                . '<button class="pxt-video-play" aria-label="Play video"><svg viewBox="0 0 68 48" width="68"><path d="M66.5 7.7s-.7-4.7-2.8-6.8C60.7-2 57.2-2 55.6-2.2 46.5-3 34-3 34-3s-12.5 0-21.6 .8C10.8-2 7.3-2 4.3 .9 2.2 3 1.5 7.7 1.5 7.7S.8 13.2.8 18.7v5.1c0 5.5.7 11 .7 11s.7 4.7 2.8 6.8c3 3.1 6.9 3 8.7 3.3 6.3.6 26.8.9 26.8.9s12.5 0 21.6-.8c1.6-.2 5.1-.2 8.1-3.1 2.1-2.1 2.8-6.8 2.8-6.8s.7-5.5.7-11v-5.1c0-5.5-.7-11-.7-11z" fill="#1ab7ea" fill-opacity=".9"/><path d="M45 24L27 14v20" fill="#fff"/></svg></button>'
                . '</div></div>';
        }

        if ($video_type === 'self-hosted') {
            return '<div class="pxt-video-wrapper">'
                . '<video class="pxt-video-player" controls preload="metadata">'
                . '<source src="' . esc_url($video_url) . '" type="video/mp4" />'
                . '</video></div>';
        }

        return '';
    }

    public static function detect_video_type(string $url): string {
        if (preg_match('/youtube\.com|youtu\.be/i', $url)) return 'youtube';
        if (preg_match('/vimeo\.com/i', $url)) return 'vimeo';
        if (preg_match('/\.(mp4|webm|ogg)(\?|$)/i', $url)) return 'self-hosted';
        return '';
    }

    public static function extract_youtube_id(string $url): string {
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    public static function extract_vimeo_id(string $url): string {
        if (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Render pagination HTML based on style
     */
    public function render_pagination_html(string $style, int $current_page, int $total_pages, string $alignment, string $load_more_label, array $view_config): string {
        $align_class = 'pxt-pagination--' . $alignment;

        if ($style === 'numbers') {
            return $this->render_pagination_numbers($current_page, $total_pages, $align_class);
        }

        if ($style === 'load_more') {
            return $this->render_load_more_button($load_more_label, $align_class);
        }

        // infinite_scroll
        return '<div class="pxt-pagination pxt-infinite-scroll-indicator ' . esc_attr($align_class) . '">'
            . '<div class="pxt-infinite-scroll-sentinel"></div>'
            . '<div class="pxt-spinner"></div>'
            . '<span class="pxt-infinite-scroll-text">' . esc_html__('Loading more...', 'plugixa-testimonial') . '</span>'
            . '</div>';
    }

    /**
     * Render numbered pagination buttons
     */
    private function render_pagination_numbers(int $current_page, int $total_pages, string $align_class): string {
        $output = '<nav class="pxt-pagination pxt-pagination-numbers ' . esc_attr($align_class) . '" aria-label="' . esc_attr__('Testimonials pagination', 'plugixa-testimonial') . '">';

        // Previous
        $prev_disabled = $current_page <= 1 ? ' disabled' : '';
        $output .= '<button type="button" class="pxt-page-link pxt-page-prev"' . $prev_disabled . ' data-page="' . ($current_page - 1) . '">&lsaquo; ' . esc_html__('Prev', 'plugixa-testimonial') . '</button>';

        // Page numbers with ellipsis
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i === 1 || $i === $total_pages || ($i >= $current_page - 1 && $i <= $current_page + 1)) {
                $active_class = $i === $current_page ? ' pxt-page-current' : '';
                $output .= '<button type="button" class="pxt-page-link' . $active_class . '" data-page="' . $i . '">' . $i . '</button>';
            } elseif ($i === $current_page - 2 || $i === $current_page + 2) {
                $output .= '<span class="pxt-page-ellipsis">&hellip;</span>';
            }
        }

        // Next
        $next_disabled = $current_page >= $total_pages ? ' disabled' : '';
        $output .= '<button type="button" class="pxt-page-link pxt-page-next"' . $next_disabled . ' data-page="' . ($current_page + 1) . '">' . esc_html__('Next', 'plugixa-testimonial') . ' &rsaquo;</button>';

        $output .= '</nav>';
        return $output;
    }

    /**
     * Render Load More button
     */
    private function render_load_more_button(string $label, string $align_class): string {
        return '<div class="pxt-pagination pxt-pagination-loadmore ' . esc_attr($align_class) . '">'
            . '<button type="button" class="pxt-load-more-btn">' . esc_html($label) . '</button>'
            . '</div>';
    }

    /**
     * Enqueue base frontend CSS and JS (no Swiper)
     */
    public static function enqueue_base_assets(): void {
        static $enqueued = false;
        if ($enqueued) {
            return;
        }
        $enqueued = true;

        $css_file = PLUGIXA_TESTIMONIAL_PATH . 'assets/frontend/build/css/frontend.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'plugixa-testimonial-frontend',
                PLUGIXA_TESTIMONIAL_URL . 'assets/frontend/build/css/frontend.css',
                [],
                PLUGIXA_TESTIMONIAL_VERSION . '.' . filemtime($css_file)
            );
        }

        $js_file = PLUGIXA_TESTIMONIAL_PATH . 'assets/frontend/js/testimonials.js';
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'plugixa-testimonial-frontend',
                PLUGIXA_TESTIMONIAL_URL . 'assets/frontend/js/testimonials.js',
                [],
                PLUGIXA_TESTIMONIAL_VERSION . '.' . filemtime($js_file),
                true
            );
            $settings = get_option('plugixatestimonial_settings', []);
            $plugixa_testimonial_vars = [
                'restUrl' => esc_url_raw(rest_url('plugixatestimonial/v1/')),
                'nonce'   => self::public_rest_nonce(),
            ];

            if (!empty($settings['recaptcha_enabled']) && !empty($settings['recaptcha_site_key'])) {
                $plugixa_testimonial_vars['recaptchaSiteKey'] = $settings['recaptcha_site_key'];
                wp_enqueue_script(
                    'google-recaptcha-v3',
                    'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($settings['recaptcha_site_key']),
                    [],
                    // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Intentional; see the surrounding code.
                    null,
                    true
                );
            }

            wp_localize_script('plugixa-testimonial-frontend', 'plugixaTestimonialVars', $plugixa_testimonial_vars);
        }
    }

    /**
     * Enqueue Swiper vendor CSS and JS (only for slider layouts)
     */
    public static function enqueue_slider_assets(): void {
        static $enqueued = false;
        if ($enqueued) {
            return;
        }
        $enqueued = true;

        wp_enqueue_style(
            self::SWIPER_HANDLE,
            PLUGIXA_TESTIMONIAL_URL . 'assets/frontend/vendor/swiper/swiper-bundle.min.css',
            [],
            self::SWIPER_VERSION
        );

        wp_enqueue_script(
            self::SWIPER_HANDLE,
            PLUGIXA_TESTIMONIAL_URL . 'assets/frontend/vendor/swiper/swiper-bundle.min.js',
            [],
            self::SWIPER_VERSION,
            true
        );

        // Swiper is only enqueued for slider layouts, so it can't be a registration-time
        // dependency of the frontend script (that would pull it onto every page). Add it
        // after the fact so our script still runs after Swiper has defined itself.
        $scripts = wp_scripts();
        $frontend = $scripts->registered[self::FRONTEND_HANDLE] ?? null;
        if ($frontend && !in_array(self::SWIPER_HANDLE, $frontend->deps, true)) {
            $frontend->deps[] = self::SWIPER_HANDLE;
        }
    }

    /**
     * Enqueue all frontend assets including Swiper.
     * Used by integrations (Elementor, Gutenberg) that need all assets regardless of layout.
     */
    public static function enqueue_frontend_assets(): void {
        self::enqueue_base_assets();
        self::enqueue_slider_assets();
    }
}
