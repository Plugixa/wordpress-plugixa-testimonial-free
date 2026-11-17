<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Frontend\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaTestimonial\Core\Cache;
use PlugixaTestimonial\Core\Multilingual;
use PlugixaTestimonial\Core\TemplateLoader;
use PlugixaTestimonial\Modules\Frontend\Shortcodes;

/**
 * Frontend REST API Controller - Public testimonials display
 */
class FrontendApi extends RestController {

    /**
     * Columns a public, unauthenticated response is allowed to contain.
     *
     * Never `SELECT t.*` on these routes. The testimonials table also holds
     * `created_by` / `modified_by` / `approved_by` (WordPress user IDs — an
     * enumeration vector), the `status` / `source` workflow fields, `view_count`
     * analytics and the internal `translation_group` UUID. None of that belongs in a
     * response any anonymous visitor can fetch, and `t.*` would ship all of it the
     * moment a column is added.
     *
     * @var string[]
     */
    private const PUBLIC_COLUMNS = [
        'id',
        'language_code',
        'client_name',
        'client_role',
        'client_company',
        'testimonial_text',
        'rating',
        'client_photo_id',
        'video_url',
        'website_url',
        'is_featured',
        'display_order',
        'created_at',
    ];

    /**
     * The public column list, prefixed for a query alias ("t.id, t.client_name, …").
     *
     * Built from a hardcoded allowlist, so it never carries request data.
     */
    private static function public_select(string $alias = 't'): string {
        return $alias . '.' . implode(', ' . $alias . '.', self::PUBLIC_COLUMNS);
    }

    public function register_routes(): void {
        $collection_args = [
            'shortcode_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'sanitize_callback' => 'absint'],
            'page'         => ['type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint'],
            'per_page'     => ['type' => 'integer', 'default' => 8, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint'],
        ];

        // Public by design: this is the data a visitor already sees rendered on the
        // page. It is read-only, returns only APPROVED rows, and only the columns in
        // self::PUBLIC_COLUMNS - no workflow fields, no author user IDs, no submitter
        // email. Requiring auth here would break every cached/AJAX frontend display.
        register_rest_route($this->namespace, '/frontend/testimonials', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_testimonials'],
                'permission_callback' => '__return_true',
                'args'                => $collection_args,
            ],
        ]);

        // Public by design: same data as above, pre-rendered to HTML for AJAX
        // pagination. Read-only, approved rows only.
        register_rest_route($this->namespace, '/frontend/testimonials/page', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_testimonials_page'],
                'permission_callback' => '__return_true',
                'args'                => $collection_args,
            ],
        ]);

        // Public by design: recent approved testimonials for the social-proof popup.
        // Read-only, approved rows only, and the response is narrowed further to the
        // handful of display fields the popup needs.
        register_rest_route($this->namespace, '/frontend/social-proof', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_social_proof_testimonials'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * Get approved testimonials for frontend display
     */
    public function get_testimonials(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $categories_table = $wpdb->prefix . 'plugixatestimonial_categories';

        $shortcode_id = (int) $request->get_param('shortcode_id');
        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $per_page = max(1, min(100, (int) ($request->get_param('per_page') ?? 8)));

        // Load shortcode config if provided
        $config = $this->get_shortcode_config($shortcode_id);

        // Determine ordering strategy FIRST to avoid alias collision
        $cat_ids = !empty($config['categories']) ? array_map('intval', $config['categories']) : [];
        $orderby = sanitize_key($config['orderby'] ?? 'display_order');
        $allowed_orderby = ['display_order', 'created_at', 'rating', 'client_name'];
        $orderby = in_array($orderby, $allowed_orderby, true) ? $orderby : 'display_order';
        $order = strtoupper($config['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $use_pivot_order = ($orderby === 'display_order' && count($cat_ids) === 1);

        // Every optional filter below is a BOUND GATE inside one constant-literal
        // WHERE clause, never an appended SQL fragment: the wp.org review requires
        // the string handed to prepare() to be a literal. A gate bound to 0 disables
        // its test, so one query text serves every combination of filters.
        $multilingual = Multilingual::instance();
        $lang_active  = $multilingual->is_multilingual_active() ? 1 : 0;
        $lang_values  = $lang_active
            ? $multilingual->language_filter_values(
                $table,
                $multilingual->get_current_language(),
                $multilingual->get_default_language()
            )
            : ['', 0, '', $table, ''];

        // The pivot JOIN already restricts to one category when it is in play, so the
        // subquery gate is off in that case. FIND_IN_SET takes a comma-joined string,
        // which keeps the placeholder count fixed however many categories are chosen.
        $cat_gate = (!empty($cat_ids) && !$use_pivot_order) ? 1 : 0;
        $cat_csv  = implode(',', $cat_ids);

        $featured_gate = !empty($config['featured_only']) ? 1 : 0;
        $rating_gate   = !empty($config['min_rating']) ? 1 : 0;
        $min_rating    = (int) ($config['min_rating'] ?? 0);

        // Values for the shared WHERE clause, in placeholder order. prepare() binds
        // positionally, so anything a query places BEFORE the WHERE (a JOIN's %i, the
        // pivot category id) must be spread ahead of these.
        $where_values = array_merge(
            [$lang_active],
            $lang_values,
            [$cat_gate, $pivot_table, $cat_csv, $featured_gate, $rating_gate, $min_rating]
        );

        // Cache key must capture every input that changes the result. It deliberately
        // does NOT embed the SQL string: the key stays stable and descriptive so it
        // survives harmless query-text edits (see includes/class-cache.php).
        $cache_key = 'frontend_list|' . wp_json_encode([
            $where_values, $orderby, $order, $use_pivot_order, $cat_ids, $per_page, $page,
        ]);

        $total = (int) Cache::remember('testimonials', $cache_key . '|count', function () use ($wpdb, $table, $where_values) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i t
                 WHERE t.status = 'approved'
                   AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                   AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                   AND ( %d = 0 OR t.is_featured = 1 )
                   AND ( %d = 0 OR t.rating >= %d )",
                $table,
                ...$where_values
            ));
        });

        // Limit from config
        $limit = (int) ($config['limit'] ?? -1);
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;
        $offset = ($page - 1) * $per_page;

        // Get items. $orderby is allowlisted above and bound as an identifier with %i.
        // A sort DIRECTION cannot be a placeholder, so ASC and DESC are two separate
        // literal queries rather than one string with the direction spliced in.
        $items = Cache::remember('testimonials', $cache_key . '|items', function () use (
            $wpdb, $table, $pivot_table, $order, $orderby,
            $where_values, $cat_ids, $per_page, $offset, $use_pivot_order
        ) {
            if ($use_pivot_order) {
                if ($order === 'DESC') {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
                    return $wpdb->get_results($wpdb->prepare(
                        "SELECT t.id, t.language_code, t.client_name, t.client_role, t.client_company, t.testimonial_text, t.rating, t.client_photo_id, t.video_url, t.website_url, t.is_featured, t.display_order, t.created_at
                         FROM %i t
                         INNER JOIN %i tc ON t.id = tc.testimonial_id AND tc.category_id = %d
                         WHERE t.status = 'approved'
                           AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                           AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                           AND ( %d = 0 OR t.is_featured = 1 )
                           AND ( %d = 0 OR t.rating >= %d )
                         ORDER BY tc.display_order DESC
                         LIMIT %d OFFSET %d",
                        $table,
                        $pivot_table,
                        $cat_ids[0],
                        ...array_merge($where_values, [$per_page, $offset])
                    ), ARRAY_A);
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT t.id, t.language_code, t.client_name, t.client_role, t.client_company, t.testimonial_text, t.rating, t.client_photo_id, t.video_url, t.website_url, t.is_featured, t.display_order, t.created_at
                     FROM %i t
                     INNER JOIN %i tc ON t.id = tc.testimonial_id AND tc.category_id = %d
                     WHERE t.status = 'approved'
                       AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                       AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                       AND ( %d = 0 OR t.is_featured = 1 )
                       AND ( %d = 0 OR t.rating >= %d )
                     ORDER BY tc.display_order ASC
                     LIMIT %d OFFSET %d",
                    $table,
                    $pivot_table,
                    $cat_ids[0],
                    ...array_merge($where_values, [$per_page, $offset])
                ), ARRAY_A);
            }

            if ($order === 'DESC') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT t.id, t.language_code, t.client_name, t.client_role, t.client_company, t.testimonial_text, t.rating, t.client_photo_id, t.video_url, t.website_url, t.is_featured, t.display_order, t.created_at
                     FROM %i t
                     WHERE t.status = 'approved'
                       AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                       AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                       AND ( %d = 0 OR t.is_featured = 1 )
                       AND ( %d = 0 OR t.rating >= %d )
                     ORDER BY t.%i DESC
                     LIMIT %d OFFSET %d",
                    $table,
                    ...array_merge($where_values, [$orderby, $per_page, $offset])
                ), ARRAY_A);
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. The result IS cached - this closure is the Cache::remember() miss handler.
            return $wpdb->get_results($wpdb->prepare(
                "SELECT t.id, t.language_code, t.client_name, t.client_role, t.client_company, t.testimonial_text, t.rating, t.client_photo_id, t.video_url, t.website_url, t.is_featured, t.display_order, t.created_at
                 FROM %i t
                 WHERE t.status = 'approved'
                   AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                   AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                   AND ( %d = 0 OR t.is_featured = 1 )
                   AND ( %d = 0 OR t.rating >= %d )
                 ORDER BY t.%i ASC
                 LIMIT %d OFFSET %d",
                $table,
                ...array_merge($where_values, [$orderby, $per_page, $offset])
            ), ARRAY_A);
        });

        // Attach photo data
        if ($items) {
            $item_ids = array_column($items, 'id');

            foreach ($items as &$item) {
                if (!empty($item['client_photo_id'])) {
                    $photo_url = wp_get_attachment_image_url((int) $item['client_photo_id'], 'thumbnail');
                    $item['client_photo_url'] = $photo_url ?: '';
                } else {
                    $item['client_photo_url'] = '';
                }
            }
            unset($item);

            // Display fields an extension owns (see Shortcodes::get_testimonials()).
            $items = apply_filters('plugixatestimonial_display_testimonials', $items);

            $this->bump_view_counts($item_ids);
        }

        return $this->success([
            'items'        => $items ?: [],
            'total'        => $total,
            'total_pages'  => $total_pages,
            'current_page' => $page,
        ]);
    }

    /**
     * Get a page of testimonials as rendered HTML (for AJAX pagination)
     */
    public function get_testimonials_page(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $shortcode_id = (int) $request->get_param('shortcode_id');
        $page = max(1, (int) $request->get_param('page'));
        $per_page = max(1, min(100, (int) $request->get_param('per_page')));

        // Load shortcode + view config
        $config = $this->get_shortcode_config($shortcode_id);
        $view_config = [];

        $views_table = $wpdb->prefix . 'plugixatestimonial_views';

        // Get view config
        $view_id = (int) ($config['view_id'] ?? 0);
        if ($view_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
            $view_json = $wpdb->get_var($wpdb->prepare(
                "SELECT config FROM %i WHERE id = %d",
                $views_table,
                $view_id
            ));
            if ($view_json) {
                $view_config = json_decode($view_json, true) ?: [];
            }
        }

        if (empty($view_config)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
            $default_json = $wpdb->get_var($wpdb->prepare(
                "SELECT config FROM %i WHERE is_default = 1 LIMIT 1",
                $views_table
            ));
            if ($default_json) {
                $view_config = json_decode($default_json, true) ?: [];
            }
        }

        // Resolve display settings
        $display_defaults = [
            'showPhoto' => true, 'showRating' => true, 'showRole' => true,
            'showCompany' => true, 'showDate' => true, 'showWebsite' => false, 'showQuoteIcon' => true,
            'showCustomFields' => true, 'showVideo' => true,
        ];
        $view_config['display'] = array_merge($display_defaults, $config['display'] ?? $display_defaults);

        // Resolve dark mode for AJAX-rendered cards
        $dark_mode_setting = $config['darkMode'] ?? 'disabled';
        if ($dark_mode_setting === 'enabled') {
            $view_dark_mode = $view_config['darkMode'] ?? [];
            $dark_defaults = [
                'cardBg' => '#1d2327', 'textColor' => '#e0e0e0', 'nameColor' => '#ffffff',
                'roleColor' => '#9ca3af', 'starColor' => '#f59e0b', 'starEmptyColor' => '#4b5563',
                'borderColor' => '#404040', 'quoteColor' => '#4b5563',
            ];
            $dm = array_merge($dark_defaults, $view_dark_mode);
            unset($dm['containerBg'], $dm['enabled']);
            $view_config['colors'] = $dm;
        }

        // Get all testimonials matching filters
        $shortcodes = new \PlugixaTestimonial\Modules\Frontend\Shortcodes();
        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // Determine ordering strategy FIRST to avoid alias collision
        $cat_ids = !empty($config['categories']) ? array_map('intval', $config['categories']) : [];
        $orderby = sanitize_key($config['orderby'] ?? 'display_order');
        $allowed_orderby = ['display_order', 'created_at', 'rating', 'client_name'];
        $orderby = in_array($orderby, $allowed_orderby, true) ? $orderby : 'display_order';
        $order = strtoupper($config['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $use_pivot_order = ($orderby === 'display_order' && count($cat_ids) === 1);

        // Bound gates inside one constant-literal WHERE -- see get_testimonials()
        // above for the rationale. Gate 0 disables its test.
        $multilingual = Multilingual::instance();
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

        // Placeholder order; anything a query places before the WHERE is spread first.
        $where_values = array_merge(
            [$lang_active],
            $lang_values,
            [$cat_gate, $pivot_table, $cat_csv, $featured_gate, $rating_gate, $min_rating]
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i t
             WHERE t.status = 'approved'
               AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
               AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
               AND ( %d = 0 OR t.is_featured = 1 )
               AND ( %d = 0 OR t.rating >= %d )",
            $table,
            ...$where_values
        ));

        $limit = (int) ($config['limit'] ?? -1);
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;
        $offset = ($page - 1) * $per_page;

        // $orderby is allowlisted above and bound as an identifier with %i. A sort
        // DIRECTION cannot be a placeholder, so ASC and DESC are separate literals.
        if ($use_pivot_order && $order === 'DESC') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
            $testimonials = $wpdb->get_results($wpdb->prepare(
                "SELECT t.id, t.language_code, t.client_name, t.client_role, t.client_company, t.testimonial_text, t.rating, t.client_photo_id, t.video_url, t.website_url, t.is_featured, t.display_order, t.created_at
                 FROM %i t
                 INNER JOIN %i tc ON t.id = tc.testimonial_id AND tc.category_id = %d
                 WHERE t.status = 'approved'
                   AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                   AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                   AND ( %d = 0 OR t.is_featured = 1 )
                   AND ( %d = 0 OR t.rating >= %d )
                 ORDER BY tc.display_order DESC
                 LIMIT %d OFFSET %d",
                $table,
                $pivot_table,
                $cat_ids[0],
                ...array_merge($where_values, [$per_page, $offset])
            ), ARRAY_A) ?: [];
        } elseif ($use_pivot_order) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
            $testimonials = $wpdb->get_results($wpdb->prepare(
                "SELECT t.id, t.language_code, t.client_name, t.client_role, t.client_company, t.testimonial_text, t.rating, t.client_photo_id, t.video_url, t.website_url, t.is_featured, t.display_order, t.created_at
                 FROM %i t
                 INNER JOIN %i tc ON t.id = tc.testimonial_id AND tc.category_id = %d
                 WHERE t.status = 'approved'
                   AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                   AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                   AND ( %d = 0 OR t.is_featured = 1 )
                   AND ( %d = 0 OR t.rating >= %d )
                 ORDER BY tc.display_order ASC
                 LIMIT %d OFFSET %d",
                $table,
                $pivot_table,
                $cat_ids[0],
                ...array_merge($where_values, [$per_page, $offset])
            ), ARRAY_A) ?: [];
        } elseif ($order === 'DESC') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
            $testimonials = $wpdb->get_results($wpdb->prepare(
                "SELECT t.id, t.language_code, t.client_name, t.client_role, t.client_company, t.testimonial_text, t.rating, t.client_photo_id, t.video_url, t.website_url, t.is_featured, t.display_order, t.created_at
                 FROM %i t
                 WHERE t.status = 'approved'
                   AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                   AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                   AND ( %d = 0 OR t.is_featured = 1 )
                   AND ( %d = 0 OR t.rating >= %d )
                 ORDER BY t.%i DESC
                 LIMIT %d OFFSET %d",
                $table,
                ...array_merge($where_values, [$orderby, $per_page, $offset])
            ), ARRAY_A) ?: [];
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
            $testimonials = $wpdb->get_results($wpdb->prepare(
                "SELECT t.id, t.language_code, t.client_name, t.client_role, t.client_company, t.testimonial_text, t.rating, t.client_photo_id, t.video_url, t.website_url, t.is_featured, t.display_order, t.created_at
                 FROM %i t
                 WHERE t.status = 'approved'
                   AND ( %d = 0 OR ( t.language_code = %s OR ( %d = 1 AND t.language_code = %s AND t.translation_group NOT IN ( SELECT translation_group FROM %i WHERE language_code = %s AND translation_group IS NOT NULL ) ) OR t.language_code IS NULL ) )
                   AND ( %d = 0 OR t.id IN ( SELECT ptc.testimonial_id FROM %i ptc WHERE FIND_IN_SET(ptc.category_id, %s) ) )
                   AND ( %d = 0 OR t.is_featured = 1 )
                   AND ( %d = 0 OR t.rating >= %d )
                 ORDER BY t.%i ASC
                 LIMIT %d OFFSET %d",
                $table,
                ...array_merge($where_values, [$orderby, $per_page, $offset])
            ), ARRAY_A) ?: [];
        }

        // Display fields an extension owns (see Shortcodes::get_testimonials()).
        if (!empty($testimonials)) {
            $testimonials = apply_filters('plugixatestimonial_display_testimonials', $testimonials);
        }

        // Render items HTML using template
        $layout_style = $view_config['layout']['style'] ?? 'grid';
        // Same lookup as the first page (theme override, extension, then this plugin),
        // so page 2 renders with the template page 1 used.
        $template = in_array($layout_style, Shortcodes::layouts(), true) ? $layout_style : 'grid';
        $template_file = TemplateLoader::locate($template);

        $items_html = '';
        if ($template_file && !empty($testimonials)) {
            ob_start();
            include $template_file;
            $items_html = ob_get_clean();
        }

        // Render pagination HTML
        $pagination_config = $view_config['pagination'] ?? [];
        $pagination_style = $config['paginationStyle'] ?? ($pagination_config['style'] ?? 'numbers');
        $alignment = $pagination_config['alignment'] ?? 'center';
        $load_more_label = $config['loadMoreLabel'] ?? 'Load More';

        $pagination_html = '';
        if ($pagination_style === 'numbers') {
            $pagination_html = $shortcodes->render_pagination_html('numbers', $page, $total_pages, $alignment, $load_more_label, $view_config);
        }

        return $this->success([
            'items_html'     => $items_html,
            'pagination_html'=> $pagination_html,
            'current_page'   => $page,
            'total_pages'    => $total_pages,
            'total_items'    => $total,
        ]);
    }

    /**
     * Read a saved shortcode's config blob.
     *
     * @param int $shortcode_id Shortcode row id, or 0 for none.
     * @return array Decoded config, or [] when absent.
     */
    private function get_shortcode_config(int $shortcode_id): array {
        global $wpdb;

        if ($shortcode_id <= 0) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; WordPress core exposes no API for it; see the Cache notes on this class; admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $config_json = $wpdb->get_var($wpdb->prepare(
            "SELECT config FROM %i WHERE id = %d",
            $wpdb->prefix . 'plugixatestimonial_shortcodes',
            $shortcode_id
        ));

        if (!$config_json) {
            return [];
        }

        $config = json_decode($config_json, true);

        return is_array($config) ? $config : [];
    }

    /**
     * Increment view_count for the rows just returned.
     *
     * One bounded UPDATE per request, touching only ids we already selected — never a
     * per-item loop, which would turn an anonymous GET into an N-write amplifier.
     *
     * The object-cache guard collapses repeat hits for the same id set inside the TTL.
     * On a site with a persistent object cache (Redis/Memcached) that throttles a
     * scripted GET loop to one write per minute; without one, the guard is per-request
     * and the behaviour matches what it was before. It deliberately does NOT write a
     * transient — that would trade one write for another on a public endpoint.
     *
     * @param int[] $ids Testimonial ids to bump.
     */
    private function bump_view_counts(array $ids): void {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return;
        }

        sort($ids);
        $guard_key = 'view_bump:' . md5(implode(',', $ids));

        if (wp_cache_get($guard_key, 'plugixatestimonial_testimonials')) {
            return;
        }
        wp_cache_set($guard_key, 1, 'plugixatestimonial_testimonials', MINUTE_IN_SECONDS);

        // FIND_IN_SET against a comma-joined list of ids keeps the placeholder count
        // fixed at one, so the query text stays a constant literal for any id count.
        // $ids are cast to int by the caller, so the list can contain no commas.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; core has no API for it. This is the write side of the counter, so caching it is not applicable.
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET view_count = view_count + 1 WHERE FIND_IN_SET(id, %s)",
            $wpdb->prefix . 'plugixatestimonial_testimonials',
            implode(',', $ids)
        ));
    }

    /**
     * Get recent approved testimonials for social proof notification popups
     */
    public function get_social_proof_testimonials(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $settings = get_option('plugixatestimonial_settings', []);
        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';

        $min_rating = max(1, min(5, (int) ($settings['sp_min_rating'] ?? 1)));
        $max_popups = max(1, min(20, (int) ($settings['sp_max_popups'] ?? 5)));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, client_name, client_company, client_role, rating, client_photo_id, created_at FROM %i WHERE status = 'approved' AND rating >= %d ORDER BY created_at DESC LIMIT %d",
            $table,
            $min_rating,
            $max_popups
        ), ARRAY_A);

        if ($items) {
            foreach ($items as &$item) {
                if (!empty($item['client_photo_id'])) {
                    $photo_url = wp_get_attachment_image_url((int) $item['client_photo_id'], 'thumbnail');
                    $item['client_photo_url'] = $photo_url ?: '';
                } else {
                    $item['client_photo_url'] = '';
                }
                unset($item['client_photo_id']);
            }
        }

        return $this->success($items ?: []);
    }
}
