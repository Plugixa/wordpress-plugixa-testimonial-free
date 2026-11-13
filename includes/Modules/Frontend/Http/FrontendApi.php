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
use PlugixaTestimonial\Modules\Requests\Http\RequestsController;
use PlugixaTestimonial\Core\Mail\EmailNotifier;

/**
 * Frontend REST API Controller - Public testimonials display + form submission
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

        // Public by design: the visitor submission form. An anonymous submitter has no
        // nonce and no capability to check, so the gate is the off-by-default
        // form_enabled setting, optional reCAPTCHA, and a 'pending' default status -
        // nothing submitted here is published without an administrator approving it.
        register_rest_route($this->namespace, '/frontend/submit', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'submit_testimonial'],
                'permission_callback' => '__return_true',
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

        // Attach photo data and custom fields
        if ($items) {
            $item_ids = array_column($items, 'id');
            $meta_map = $this->get_meta_for_testimonials($item_ids);

            foreach ($items as &$item) {
                if (!empty($item['client_photo_id'])) {
                    $photo_url = wp_get_attachment_image_url((int) $item['client_photo_id'], 'thumbnail');
                    $item['client_photo_url'] = $photo_url ?: '';
                } else {
                    $item['client_photo_url'] = '';
                }

                $item['custom_fields'] = $meta_map[$item['id']] ?? (object) [];
            }
            unset($item);

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

        // Custom-field values for the rendered cards. Restricted to published,
        // frontend-visible field definitions - never the raw meta table.
        if (!empty($testimonials)) {
            $meta_map = $this->get_meta_for_testimonials(array_column($testimonials, 'id'));
            foreach ($testimonials as &$pxt_item) {
                $pxt_item['custom_fields'] = $meta_map[(int) $pxt_item['id']] ?? [];
            }
            unset($pxt_item);
        }

        // Render items HTML using template
        $layout_style = $view_config['layout']['style'] ?? 'grid';
        $template = in_array($layout_style, ['grid', 'masonry', 'list']) ? $layout_style : 'grid';
        $template_file = PLUGIXA_TESTIMONIAL_PATH . 'templates/' . $template . '.php';

        $items_html = '';
        if (file_exists($template_file) && !empty($testimonials)) {
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
     * Get custom-field values for a list of testimonial IDs.
     *
     * The meta table is a general key/value store and also holds the submitter's
     * email address (see submit_testimonial()). This runs on a PUBLIC, unauthenticated
     * endpoint, so the result is restricted to keys that correspond to a published,
     * frontend-visible custom-field definition. An allowlist, not a denylist: a new
     * internal meta key added later stays private by default instead of leaking until
     * someone remembers to exclude it.
     *
     * @param int[] $ids Testimonial ids.
     * @return array<int, array<string, string>> Map of testimonial id => field values.
     */
    private function get_meta_for_testimonials(array $ids): array {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
        $public_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT field_key FROM %i WHERE show_in_frontend = 1 AND status = %s",
            $wpdb->prefix . 'plugixatestimonial_custom_fields',
            'publish'
        ));

        if (empty($public_keys)) {
            return [];
        }

        // Two FIND_IN_SET lookups instead of two variable-length IN() lists, so the
        // query text is a constant literal. Ids are ints and $public_keys are
        // sanitised field keys, so neither comma-joined list can contain a comma.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT testimonial_id, meta_key, meta_value FROM %i WHERE FIND_IN_SET(testimonial_id, %s) AND FIND_IN_SET(meta_key, %s)",
            $wpdb->prefix . 'plugixatestimonial_meta',
            implode(',', array_map('intval', $ids)),
            implode(',', $public_keys)
        ), ARRAY_A);

        $map = [];
        foreach ($results as $row) {
            $tid = (int) $row['testimonial_id'];
            if (!isset($map[$tid])) {
                $map[$tid] = [];
            }
            $map[$tid][$row['meta_key']] = $row['meta_value'];
        }

        return $map;
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

    /**
     * Submit a testimonial from the frontend form
     */
    public function submit_testimonial(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $settings = get_option('plugixatestimonial_settings', []);

        if (empty($settings['form_enabled'])) {
            return $this->error(__('Testimonial submissions are currently disabled.', 'plugixa-testimonial'), 403);
        }

        // Every field is read from $request, never from $_POST/$_FILES: the REST server has
        // already loaded a multipart body (unslashed) into the body params and its uploads
        // into the file params, so a FormData and a JSON submission are read the same way.

        // reCAPTCHA verification
        if (!empty($settings['recaptcha_enabled']) && !empty($settings['recaptcha_secret_key'])) {
            $recaptcha_token = sanitize_text_field((string) $request->get_param('recaptcha_token'));
            if (empty($recaptcha_token)) {
                return $this->error(__('reCAPTCHA verification required.', 'plugixa-testimonial'), 400);
            }

            $verify = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
                'body' => [
                    'secret'   => $settings['recaptcha_secret_key'],
                    'response' => $recaptcha_token,
                ],
            ]);

            if (is_wp_error($verify)) {
                return $this->error(__('reCAPTCHA verification failed.', 'plugixa-testimonial'), 400);
            }

            $verify_body = json_decode(wp_remote_retrieve_body($verify), true);
            if (empty($verify_body['success'])) {
                return $this->error(__('reCAPTCHA verification failed.', 'plugixa-testimonial'), 400);
            }

            // v3 score threshold check
            $threshold = (float) ($settings['recaptcha_score_threshold'] ?? 0.5);
            $score = (float) ($verify_body['score'] ?? 0);
            if ($score < $threshold) {
                return $this->error(__('reCAPTCHA score too low. Please try again.', 'plugixa-testimonial'), 403);
            }
        }

        // Sanitize
        $email = sanitize_email((string) $request->get_param('email'));
        $data = [
            'client_name'      => sanitize_text_field((string) $request->get_param('client_name')),
            'client_role'      => sanitize_text_field((string) $request->get_param('client_role')),
            'client_company'   => sanitize_text_field((string) $request->get_param('client_company')),
            'testimonial_text' => sanitize_textarea_field((string) $request->get_param('testimonial_text')),
            'rating'           => max(1, min(5, (int) ($request->get_param('rating') ?? 5))),
            'website_url'      => esc_url_raw((string) $request->get_param('website_url')),
            'source'           => 'frontend_form',
            'status'           => !empty($settings['auto_approve']) ? 'approved' : ($settings['default_status'] ?? 'pending'),
            'created_at'       => current_time('mysql'),
        ];

        // Handle photo upload
        // The upload's tmp_name/size come from PHP; it is checked with is_uploaded_file() and
        // wp_check_filetype_and_ext() below, and its name and type are replaced before the
        // uploader sees them.
        $uploads = $request->get_file_params();
        if (!empty($settings['photo_upload_enabled']) && !empty($uploads['client_photo']) && !empty($uploads['client_photo']['size'])) {
            $file = $uploads['client_photo'];

            // Must be a genuine PHP upload, not an arbitrary path smuggled in.
            if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                return $this->error(__('Invalid file upload.', 'plugixa-testimonial'), 400);
            }

            // Validate file size (max 2MB) before touching the contents.
            $max_size = 2 * 1024 * 1024;
            if ($file['size'] > $max_size) {
                return $this->error(__('File too large. Maximum size is 2MB.', 'plugixa-testimonial'), 400);
            }

            // Validate the type from the file itself. $file['type'] is supplied by the
            // client and must never be trusted; wp_check_filetype_and_ext() sniffs the
            // real contents and confirms they agree with the extension.
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            if (empty($check['type']) || !in_array($check['type'], $allowed_types, true)) {
                return $this->error(__('Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.', 'plugixa-testimonial'), 400);
            }

            // The uploader is handed the name and type decided here, not the browser's:
            // the name sanitized, the type the one just sniffed from the file itself.
            $file['name'] = sanitize_file_name($file['name']);
            $file['type'] = $check['type'];

            // This runs in a REST request, where wp-admin's upload functions are not loaded.
            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $uploaded = wp_handle_upload($file, ['test_form' => false]);
            if (!is_array($uploaded) || isset($uploaded['error']) || empty($uploaded['file'])) {
                return $this->error(__('Failed to upload photo.', 'plugixa-testimonial'), 500);
            }

            $attachment_id = wp_insert_attachment([
                'post_mime_type' => $uploaded['type'],
                'guid'           => $uploaded['url'],
                'post_title'     => sanitize_text_field(pathinfo($uploaded['file'], PATHINFO_FILENAME)),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ], $uploaded['file'], 0, true);
            if (is_wp_error($attachment_id)) {
                wp_delete_file($uploaded['file']);
                return $this->error(__('Failed to upload photo.', 'plugixa-testimonial'), 500);
            }

            // Thumbnail sizes and image dimensions; also a wp-admin file a REST request lacks.
            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $metadata = wp_generate_attachment_metadata($attachment_id, $uploaded['file']);
            wp_update_attachment_metadata($attachment_id, $metadata);

            $data['client_photo_id'] = $attachment_id;
        }

        if (!empty($settings['auto_approve'])) {
            $data['approved_at'] = current_time('mysql');
        }

        // If user is logged in, record user ID
        if (is_user_logged_in()) {
            $data['created_by'] = get_current_user_id();
        }

        // Multilingual: prefer language from frontend form, fallback to auto-detection
        $multilingual = Multilingual::instance();
        $lang_param = sanitize_text_field((string) $request->get_param('language_code'));
        if (!empty($lang_param)) {
            $data['language_code'] = $lang_param;
        } elseif ($multilingual->is_multilingual_active()) {
            $data['language_code'] = $multilingual->get_current_language();
        }

        // Extract custom fields from submission
        // Each key is sanitize_key()'d and each value sanitized in the loop below.
        $submitted_custom_fields = $request->get_param('custom_fields');
        if (!is_array($submitted_custom_fields)) {
            $submitted_custom_fields = [];
        }

        // Validate required custom fields against definitions
        $cf_table = $wpdb->prefix . 'plugixatestimonial_custom_fields';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
        $cf_defs = $wpdb->get_results($wpdb->prepare(
            "SELECT field_key, field_label, field_type, is_required FROM %i WHERE show_in_frontend = 1 AND status = %s",
            $cf_table,
            'publish'
        ), ARRAY_A);
        $cf_errors = [];
        $valid_keys = [];
        $sanitized_custom_fields = [];
        if ($cf_defs) {
            foreach ($cf_defs as $cf_def) {
                $valid_keys[] = $cf_def['field_key'];
                if ($cf_def['is_required'] && empty($submitted_custom_fields[$cf_def['field_key']])) {
                    $cf_errors[$cf_def['field_key']] = sprintf(
                        /* translators: %s: custom field label */
                        __('%s is required', 'plugixa-testimonial'),
                        $cf_def['field_label']
                    );
                }
            }
            // Filter out invalid keys and sanitize values
            foreach ($submitted_custom_fields as $key => $value) {
                $key = sanitize_key($key);
                if (in_array($key, $valid_keys, true) && $value !== '') {
                    $sanitized_custom_fields[$key] = sanitize_text_field($value);
                }
            }
        }

        // Type-specific validation for custom fields
        if ($cf_defs) {
            foreach ($cf_defs as $cf_def) {
                $val = $sanitized_custom_fields[$cf_def['field_key']] ?? '';
                if ($val === '') continue;
                switch ($cf_def['field_type']) {
                    case 'number':
                        if (!is_numeric($val)) {
                            /* translators: %s: custom field label */
                            $cf_errors[$cf_def['field_key']] = sprintf(__('%s must be a valid number', 'plugixa-testimonial'), $cf_def['field_label']);
                        }
                        break;
                    case 'url':
                        if (!filter_var($val, FILTER_VALIDATE_URL)) {
                            /* translators: %s: custom field label */
                            $cf_errors[$cf_def['field_key']] = sprintf(__('%s must be a valid URL', 'plugixa-testimonial'), $cf_def['field_label']);
                        }
                        break;
                    case 'date':
                        if (!strtotime($val)) {
                            /* translators: %s: custom field label */
                            $cf_errors[$cf_def['field_key']] = sprintf(__('%s must be a valid date', 'plugixa-testimonial'), $cf_def['field_label']);
                        }
                        break;
                    case 'color':
                        if (!preg_match('/^#[0-9a-f]{6}$/i', $val)) {
                            /* translators: %s: custom field label */
                            $cf_errors[$cf_def['field_key']] = sprintf(__('%s must be a valid hex color', 'plugixa-testimonial'), $cf_def['field_label']);
                        }
                        break;
                }
            }
        }

        // Validate required fields
        $required = $settings['required_fields'] ?? ['client_name', 'testimonial_text', 'rating'];
        $errors = [];

        foreach ($required as $field) {
            // Email is not in $data (no DB column), check separately
            if ($field === 'email') {
                if (empty($email)) {
                    $errors['email'] = __('Email is required', 'plugixa-testimonial');
                }
                continue;
            }
            if (empty($data[$field])) {
                /* translators: %s: form field name */
                $errors[$field] = sprintf(__('%s is required', 'plugixa-testimonial'), ucfirst(str_replace('_', ' ', $field)));
            }
        }

        // Email format validation
        if (!empty($email) && !is_email($email)) {
            $errors['email'] = __('Please enter a valid email address', 'plugixa-testimonial');
        }

        // URL format validation
        if (!empty($data['website_url']) && !filter_var($data['website_url'], FILTER_VALIDATE_URL)) {
            $errors['website_url'] = __('Please enter a valid URL', 'plugixa-testimonial');
        }

        // Text length validation
        $min_length = $settings['min_text_length'] ?? 10;
        $max_length = $settings['max_text_length'] ?? 2000;

        if (!empty($data['testimonial_text'])) {
            $text_length = strlen($data['testimonial_text']);
            if ($text_length < $min_length) {
                /* translators: %d: minimum number of characters */
                $errors['testimonial_text'] = sprintf(__('Testimonial must be at least %d characters', 'plugixa-testimonial'), $min_length);
            }
            if ($text_length > $max_length) {
                /* translators: %d: maximum number of characters */
                $errors['testimonial_text'] = sprintf(__('Testimonial must not exceed %d characters', 'plugixa-testimonial'), $max_length);
            }
        }

        // Merge custom field validation errors
        if (!empty($cf_errors)) {
            $errors = array_merge($errors, $cf_errors);
        }

        if (!empty($errors)) {
            // The photo was stored before the fields were checked. A refused submission
            // must not leave it behind: this endpoint is public, and each rejected
            // attempt would otherwise add an unattached image to the media library.
            if (!empty($data['client_photo_id'])) {
                wp_delete_attachment((int) $data['client_photo_id'], true);
            }

            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error(__('Failed to submit testimonial', 'plugixa-testimonial'), 500);
        }

        $insert_id = $wpdb->insert_id;

        // Save email as meta (no DB column in testimonials table)
        if (!empty($email)) {
            $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->insert($meta_table, [
                'testimonial_id' => $insert_id,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                'meta_key'       => 'email',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value itself is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                'meta_value'     => $email,
            ]);
        }

        // Save custom field meta values
        if (!empty($sanitized_custom_fields)) {
            $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';
            foreach ($sanitized_custom_fields as $meta_key => $meta_value) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->insert($meta_table, [
                    'testimonial_id' => $insert_id,
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    'meta_key'       => $meta_key,
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value itself is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    'meta_value'     => $meta_value,
                ]);
            }
        }

        // Send notification email
        EmailNotifier::send_new_submission_notification($insert_id, $data);

        // If submitted via request token, mark request as submitted
        $request_token = sanitize_text_field((string) $request->get_param('pxt_request_token'));
        if (!empty($request_token)) {
            RequestsController::mark_submitted($request_token, $insert_id);
        }

        // Assign language for new submissions
        if ($multilingual->is_multilingual_active() && !empty($data['language_code'])) {
            $multilingual->assign_language($table, $insert_id, $data['language_code']);
        }

        $message = $settings['success_message'] ?? __('Thank you for your testimonial!', 'plugixa-testimonial');

        return $this->success(
            ['id' => $insert_id],
            $message
        );
    }
}
