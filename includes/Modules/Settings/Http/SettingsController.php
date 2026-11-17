<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Settings\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Settings REST Controller - Settings + Dashboard stats (rating distribution, status breakdown)
 */
class SettingsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'settings';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/dashboard', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_dashboard_stats'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);
    }

    public function get_settings($request) {
        /**
         * Read-only facts an extension adds to the settings response, such as whether a
         * plugin it integrates with is active. Never stored.
         *
         * @param array $settings The stored settings merged over their defaults.
         */
        $settings = apply_filters('plugixatestimonial_settings_response', $this->get_all_settings());
        return $this->success($settings);
    }

    public function update_settings($request) {
        $current = $this->get_all_settings();
        $input = $request->get_json_params();

        $settings = $this->sanitize_settings($input, $current);

        update_option('plugixatestimonial_settings', $settings);

        return $this->success(
            $settings,
            __('Settings saved successfully', 'plugixa-testimonial')
        );
    }

    public function get_dashboard_stats($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $categories_table = $wpdb->prefix . 'plugixatestimonial_categories';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // Total
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $table));

        // By status
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $approved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = %s", $table, 'approved'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $pending  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = %s", $table, 'pending'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $rejected = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = %s", $table, 'rejected'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $draft    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = %s", $table, 'draft'));

        // Featured count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $featured = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE is_featured = 1", $table));

        // Average rating
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $avg_rating = (float) $wpdb->get_var($wpdb->prepare("SELECT AVG(rating) FROM %i", $table));

        // Total views
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total_views = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(view_count) FROM %i", $table));

        // Total categories
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total_categories = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $categories_table));

        // Rating distribution (1-5)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $rating_distribution = $wpdb->get_results($wpdb->prepare(
            "SELECT rating, COUNT(*) as count FROM %i GROUP BY rating ORDER BY rating ASC",
            $table
        ), ARRAY_A);

        // Recent testimonials (last 5)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT id, client_name, rating, status, created_at FROM %i ORDER BY created_at DESC LIMIT 5",
            $table
        ), ARRAY_A);

        // Top rated (5 stars, most recent)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $top_rated = $wpdb->get_results($wpdb->prepare(
            "SELECT id, client_name, client_company, rating, testimonial_text FROM %i WHERE status = 'approved' AND rating = 5 ORDER BY created_at DESC LIMIT 5",
            $table
        ), ARRAY_A);

        // Testimonials by category
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $by_category = $wpdb->get_results($wpdb->prepare(
            "SELECT c.name, COUNT(tc.testimonial_id) as count FROM %i c LEFT JOIN %i tc ON c.id = tc.category_id GROUP BY c.id ORDER BY count DESC",
            $categories_table,
            $pivot_table
        ), ARRAY_A);

        // Transform rating_distribution from array of objects to key-value map
        $rating_dist_map = [];
        foreach ($rating_distribution as $row) {
            $rating_dist_map[(string) $row['rating']] = (int) $row['count'];
        }

        return $this->success([
            'total'              => $total,
            'approved'           => $approved,
            'pending'            => $pending,
            'rejected'           => $rejected,
            'draft'              => $draft,
            'featured'           => $featured,
            'avg_rating'         => round($avg_rating, 1),
            'total_views'        => $total_views ?: 0,
            'total_categories'   => $total_categories,
            'rating_distribution'    => $rating_dist_map,
            'recent_testimonials'    => $recent ?: [],
            'top_rated'              => $top_rated ?: [],
            'testimonials_by_category' => $by_category ?: [],
        ]);
    }

    private function get_all_settings(): array {
        $defaults = [
            'items_per_page'       => 20,
            'default_status'       => 'pending',
            'auto_approve'         => false,
            'default_rating'       => 5,
            'show_empty_stars'     => true,
            'date_format'          => 'M j, Y',
            'excerpt_length'       => 150,
            'google_fonts_api_key'         => '',
            'enable_schema'               => true,
            'schema_type'                 => 'LocalBusiness',
            'schema_name'                 => '',
            'sp_enabled'                   => false,
            'sp_position'                  => 'bottom-left',
            'sp_display_duration'          => 5,
            'sp_delay_between'             => 10,
            'sp_initial_delay'             => 5,
            'sp_max_popups'                => 5,
            'sp_show_photo'                => true,
            'sp_show_rating'               => true,
            'sp_show_time_ago'             => true,
            'sp_min_rating'                => 1,
            'sp_bg_color'                  => '#ffffff',
            'sp_text_color'                => '#1d2327',
            'sp_border_color'              => '#e0e0e0',
            'sp_hide_on_mobile'            => false,
        ];

        /**
         * Settings-defaults seam.
         *
         * The premium overlay registers the defaults for its own settings (AI provider
         * and keys, Google Places key) through this filter, so the free build ships
         * neither those keys nor their values. Presence of the overlay is the only gate.
         *
         * @param array $defaults Default settings map.
         */
        $defaults = apply_filters('plugixatestimonial_settings_defaults', $defaults);

        $saved = get_option('plugixatestimonial_settings', []);

        return array_merge($defaults, (array) $saved);
    }

    private function sanitize_settings(array $input, array $current): array {
        $settings = [];

        // General
        $settings['items_per_page'] = isset($input['items_per_page'])
            ? max(1, min(100, absint($input['items_per_page'])))
            : $current['items_per_page'];

        $settings['default_status'] = isset($input['default_status'])
            ? sanitize_key($input['default_status'])
            : $current['default_status'];

        $settings['auto_approve'] = isset($input['auto_approve'])
            ? (bool) $input['auto_approve']
            : $current['auto_approve'];

        // Display
        $settings['default_rating'] = isset($input['default_rating'])
            ? max(1, min(5, absint($input['default_rating'])))
            : $current['default_rating'];

        $settings['show_empty_stars'] = isset($input['show_empty_stars'])
            ? (bool) $input['show_empty_stars']
            : $current['show_empty_stars'];

        $settings['date_format'] = isset($input['date_format'])
            ? sanitize_text_field($input['date_format'])
            : $current['date_format'];

        $settings['excerpt_length'] = isset($input['excerpt_length'])
            ? max(50, min(1000, absint($input['excerpt_length'])))
            : $current['excerpt_length'];

        // Google Fonts
        $settings['google_fonts_api_key'] = isset($input['google_fonts_api_key'])
            ? sanitize_text_field($input['google_fonts_api_key'])
            : $current['google_fonts_api_key'];

        // Schema
        $settings['enable_schema'] = isset($input['enable_schema'])
            ? (bool) $input['enable_schema']
            : $current['enable_schema'];

        $allowed_schema_types = ['LocalBusiness', 'Product', 'Organization', 'Service', 'Store', 'Restaurant', 'MedicalBusiness', 'LegalService', 'FinancialService', 'EducationalOrganization'];
        $settings['schema_type'] = isset($input['schema_type']) && in_array($input['schema_type'], $allowed_schema_types)
            ? sanitize_text_field($input['schema_type'])
            : $current['schema_type'];

        $settings['schema_name'] = isset($input['schema_name'])
            ? sanitize_text_field($input['schema_name'])
            : $current['schema_name'];

        // Social Proof Settings
        $settings['sp_enabled'] = isset($input['sp_enabled'])
            ? (bool) $input['sp_enabled']
            : ($current['sp_enabled'] ?? false);

        $settings['sp_position'] = isset($input['sp_position']) && in_array($input['sp_position'], ['bottom-left', 'bottom-right'])
            ? $input['sp_position']
            : ($current['sp_position'] ?? 'bottom-left');

        $settings['sp_display_duration'] = isset($input['sp_display_duration'])
            ? max(1, min(30, absint($input['sp_display_duration'])))
            : ($current['sp_display_duration'] ?? 5);

        $settings['sp_delay_between'] = isset($input['sp_delay_between'])
            ? max(1, min(60, absint($input['sp_delay_between'])))
            : ($current['sp_delay_between'] ?? 10);

        $settings['sp_initial_delay'] = isset($input['sp_initial_delay'])
            ? max(0, min(60, absint($input['sp_initial_delay'])))
            : ($current['sp_initial_delay'] ?? 5);

        $settings['sp_max_popups'] = isset($input['sp_max_popups'])
            ? max(1, min(20, absint($input['sp_max_popups'])))
            : ($current['sp_max_popups'] ?? 5);

        $settings['sp_show_photo'] = isset($input['sp_show_photo'])
            ? (bool) $input['sp_show_photo']
            : ($current['sp_show_photo'] ?? true);

        $settings['sp_show_rating'] = isset($input['sp_show_rating'])
            ? (bool) $input['sp_show_rating']
            : ($current['sp_show_rating'] ?? true);

        $settings['sp_show_time_ago'] = isset($input['sp_show_time_ago'])
            ? (bool) $input['sp_show_time_ago']
            : ($current['sp_show_time_ago'] ?? true);

        $settings['sp_min_rating'] = isset($input['sp_min_rating'])
            ? max(1, min(5, absint($input['sp_min_rating'])))
            : ($current['sp_min_rating'] ?? 1);

        $settings['sp_bg_color'] = isset($input['sp_bg_color'])
            ? sanitize_hex_color($input['sp_bg_color']) ?: '#ffffff'
            : ($current['sp_bg_color'] ?? '#ffffff');

        $settings['sp_text_color'] = isset($input['sp_text_color'])
            ? sanitize_hex_color($input['sp_text_color']) ?: '#1d2327'
            : ($current['sp_text_color'] ?? '#1d2327');

        $settings['sp_border_color'] = isset($input['sp_border_color'])
            ? sanitize_hex_color($input['sp_border_color']) ?: '#e0e0e0'
            : ($current['sp_border_color'] ?? '#e0e0e0');

        $settings['sp_hide_on_mobile'] = isset($input['sp_hide_on_mobile'])
            ? (bool) $input['sp_hide_on_mobile']
            : ($current['sp_hide_on_mobile'] ?? false);

        /**
         * Settings-sanitize seam.
         *
         * The premium overlay names a sanitizer for each settings key it owns. Only that
         * map of callables passes through the hook - the request never does - and each
         * value is sanitized here, as it is read. A key nobody registered is simply
         * dropped, so a free build cannot store a credential it has no code to use.
         *
         * @param array<string, callable> $sanitizers Settings key => sanitizer callable.
         */
        $sanitizers = apply_filters('plugixatestimonial_settings_sanitizers', []);

        foreach ((array) $sanitizers as $key => $sanitizer) {
            if (!is_string($key) || !is_callable($sanitizer)) {
                continue;
            }

            $settings[$key] = isset($input[$key])
                ? call_user_func($sanitizer, $input[$key])
                : ($current[$key] ?? '');
        }

        return $settings;
    }
}
