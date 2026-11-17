<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Testimonials\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaTestimonial\Core\Multilingual;

/**
 * Testimonials REST Controller - CRUD + status change + bulk actions + pivot table joins
 */
class TestimonialsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'testimonials';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_create_permission'],
                'args'                => $this->get_create_params(),
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'check_update_permission'],
                'args'                => $this->get_update_params(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        // PATCH status
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'change_status'],
                'permission_callback' => [$this, 'check_approve_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder_items'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_items'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-action', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'bulk_action'],
                'permission_callback' => [$this, 'check_approve_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/authors', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_authors'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);
    }

    /**
     * Edit-level, plus the moderation rule: `status` is a field of this payload, so an
     * edit-only user must not be able to publish through it (see check_moderation()).
     * The status is read the way sanitize_testimonial_data() reads it, so the value
     * checked here is the value the handler stores.
     */
    public function check_create_permission($request) {
        $edit = $this->check_permission($request);
        if (is_wp_error($edit)) {
            return $edit;
        }
        return $this->check_moderation(sanitize_key($request->get_param('status') ?? 'pending'), null);
    }

    /** As check_create_permission(), against the stored row and its translation group. */
    public function check_update_permission($request) {
        $edit = $this->check_permission($request);
        if (is_wp_error($edit)) {
            return $edit;
        }
        return $this->check_moderation(
            sanitize_key($request->get_param('status') ?? 'pending'),
            $this->stored_testimonial_status((int) $request->get_param('id'))
        );
    }

    public function get_items($request) {
        global $wpdb;

        $pagination = $this->get_pagination($request);
        $filters = $this->get_filters($request);
        $sort = $this->get_sort($request);

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $categories_table = $wpdb->prefix . 'plugixatestimonial_categories';

        // Every filter below is a BOUND GATE inside one constant-literal WHERE clause,
        // never an appended SQL fragment: the wp.org review requires the string handed
        // to prepare() to be a literal. A gate bound to 0 disables its test.
        //
        // The pivot table is ALWAYS LEFT JOINed, because a join TYPE cannot be bound as
        // a value. The three original shapes are reproduced by gates instead:
        //   no category filter -> both gates 0, the join is inert (DISTINCT already
        //                         collapses the fan-out, as it did before)
        //   "uncategorized"    -> uncat gate 1, matching the old LEFT JOIN + IS NULL
        //   specific categories-> cat gate 1; FIND_IN_SET fails on the NULL side, which
        //                         gives exactly the old INNER JOIN semantics
        $search_gate = !empty($filters['search']) ? 1 : 0;
        $search      = $search_gate ? '%' . $wpdb->esc_like($filters['search']) . '%' : '%';

        $status_gate = !empty($filters['status']) ? 1 : 0;
        $status_csv  = '';
        if ($status_gate) {
            $status_csv = is_array($filters['status'])
                ? implode(',', array_map('sanitize_key', $filters['status']))
                : sanitize_key($filters['status']);
        }

        $uncat_gate = 0;
        $cat_gate   = 0;
        $cat_csv    = '';
        if (!empty($filters['categories']) && is_array($filters['categories'])) {
            $raw_ids = $filters['categories'];
            $has_uncategorized = in_array('0', $raw_ids, true)
                || in_array(0, $raw_ids)
                || in_array('uncategorized', $raw_ids, true);

            if ($has_uncategorized) {
                $uncat_gate = 1;
            } else {
                $cat_gate = 1;
                $cat_csv  = implode(',', array_map('intval', $raw_ids));
            }
        }

        $rmin_gate = (isset($filters['rating_min']) && $filters['rating_min'] !== '' && $filters['rating_min'] !== null) ? 1 : 0;
        $rmin      = $rmin_gate ? (int) $filters['rating_min'] : 0;
        $rmax_gate = (isset($filters['rating_max']) && $filters['rating_max'] !== '' && $filters['rating_max'] !== null) ? 1 : 0;
        $rmax      = $rmax_gate ? (int) $filters['rating_max'] : 0;

        $feat_gate = (isset($filters['is_featured']) && $filters['is_featured'] !== '' && $filters['is_featured'] !== null) ? 1 : 0;
        $feat      = $feat_gate ? (int) $filters['is_featured'] : 0;

        // A disabled DATETIME gate still binds a VALID sentinel: MySQL in strict mode
        // aborts the whole query on '' rather than simply not matching.
        $dfrom_gate = !empty($filters['date_from']) ? 1 : 0;
        $dfrom      = $dfrom_gate ? sanitize_text_field($filters['date_from']) . ' 00:00:00' : '1000-01-01 00:00:00';
        $dto_gate   = !empty($filters['date_to']) ? 1 : 0;
        $dto        = $dto_gate ? sanitize_text_field($filters['date_to']) . ' 23:59:59' : '9999-12-31 23:59:59';

        $author_gate = !empty($filters['author']) ? 1 : 0;
        $author      = $author_gate ? (int) $filters['author'] : 0;

        // Language: the two modes are mutually exclusive, so they get one gate each.
        $multilingual = Multilingual::instance();
        $lang = $request->get_param('lang') ?? '';
        $group_by_translation = (bool) ($request->get_param('group_by_translation') ?? false);

        $group_gate = 0;
        $group_lang = '';
        $lang_gate  = 0;
        if ($multilingual->is_multilingual_active()) {
            if ($group_by_translation) {
                $group_gate = 1;
                $group_lang = $multilingual->get_default_language();
            } elseif (!empty($lang)) {
                $lang_gate = 1;
            }
        }

        // Placeholder order. The two %i identifiers (table, pivot) precede the WHERE in
        // every statement below, so they are always spread ahead of these values.
        $where_values = [
            $search_gate, $search, $search, $search,
            $status_gate, $status_csv,
            $uncat_gate,
            $cat_gate, $cat_csv,
            $rmin_gate, $rmin,
            $rmax_gate, $rmax,
            $feat_gate, $feat,
            $dfrom_gate, $dfrom,
            $dto_gate, $dto,
            $author_gate, $author,
            $group_gate, $group_lang,
            $lang_gate, $lang,
        ];

        // Validate orderby
        $allowed_orderby = ['id', 'client_name', 'status', 'rating', 'display_order', 'view_count', 'is_featured', 'created_at', 'modified_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Get total count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT t.id)
             FROM %i t
             LEFT JOIN %i tc_filter ON t.id = tc_filter.testimonial_id
             WHERE 1=1
               AND ( %d = 0 OR ( t.client_name LIKE %s OR t.testimonial_text LIKE %s OR t.client_company LIKE %s ) )
               AND ( %d = 0 OR FIND_IN_SET(t.status, %s) )
               AND ( %d = 0 OR tc_filter.testimonial_id IS NULL )
               AND ( %d = 0 OR FIND_IN_SET(tc_filter.category_id, %s) )
               AND ( %d = 0 OR t.rating >= %d )
               AND ( %d = 0 OR t.rating <= %d )
               AND ( %d = 0 OR t.is_featured = %d )
               AND ( %d = 0 OR t.created_at >= %s )
               AND ( %d = 0 OR t.created_at <= %s )
               AND ( %d = 0 OR t.created_by = %d )
               AND ( %d = 0 OR ( t.language_code = %s OR t.language_code IS NULL OR t.translation_group IS NULL ) )
               AND ( %d = 0 OR t.language_code = %s )",
            $table,
            $pivot_table,
            ...$where_values
        ));

        // Per-category ordering: use pivot display_order when single category + display_order sort
        $use_pivot_order = false;
        if ($orderby === 'display_order' && !empty($filters['categories']) && is_array($filters['categories'])) {
            $raw_cat_ids = $filters['categories'];
            $has_uncategorized = in_array('0', $raw_cat_ids, true)
                || in_array(0, $raw_cat_ids)
                || in_array('uncategorized', $raw_cat_ids, true);
            if (!$has_uncategorized && count($raw_cat_ids) === 1) {
                $use_pivot_order = true;
            }
        }

        // Get items. $orderby is allowlisted above and bound as an identifier with %i,
        // but a sort DIRECTION cannot be a placeholder -- so each ordering strategy and
        // each direction is its own literal statement rather than a spliced fragment.
        if ($use_pivot_order && $order === 'ASC') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT t.*
                 FROM %i t
                 LEFT JOIN %i tc_filter ON t.id = tc_filter.testimonial_id
                 WHERE 1=1
                   AND ( %d = 0 OR ( t.client_name LIKE %s OR t.testimonial_text LIKE %s OR t.client_company LIKE %s ) )
                   AND ( %d = 0 OR FIND_IN_SET(t.status, %s) )
                   AND ( %d = 0 OR tc_filter.testimonial_id IS NULL )
                   AND ( %d = 0 OR FIND_IN_SET(tc_filter.category_id, %s) )
                   AND ( %d = 0 OR t.rating >= %d )
                   AND ( %d = 0 OR t.rating <= %d )
                   AND ( %d = 0 OR t.is_featured = %d )
                   AND ( %d = 0 OR t.created_at >= %s )
                   AND ( %d = 0 OR t.created_at <= %s )
                   AND ( %d = 0 OR t.created_by = %d )
                   AND ( %d = 0 OR ( t.language_code = %s OR t.language_code IS NULL OR t.translation_group IS NULL ) )
                   AND ( %d = 0 OR t.language_code = %s )
                 ORDER BY tc_filter.display_order ASC
                 LIMIT %d OFFSET %d",
                $table,
                $pivot_table,
                ...array_merge($where_values, [$pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        } elseif ($use_pivot_order) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT t.*
                 FROM %i t
                 LEFT JOIN %i tc_filter ON t.id = tc_filter.testimonial_id
                 WHERE 1=1
                   AND ( %d = 0 OR ( t.client_name LIKE %s OR t.testimonial_text LIKE %s OR t.client_company LIKE %s ) )
                   AND ( %d = 0 OR FIND_IN_SET(t.status, %s) )
                   AND ( %d = 0 OR tc_filter.testimonial_id IS NULL )
                   AND ( %d = 0 OR FIND_IN_SET(tc_filter.category_id, %s) )
                   AND ( %d = 0 OR t.rating >= %d )
                   AND ( %d = 0 OR t.rating <= %d )
                   AND ( %d = 0 OR t.is_featured = %d )
                   AND ( %d = 0 OR t.created_at >= %s )
                   AND ( %d = 0 OR t.created_at <= %s )
                   AND ( %d = 0 OR t.created_by = %d )
                   AND ( %d = 0 OR ( t.language_code = %s OR t.language_code IS NULL OR t.translation_group IS NULL ) )
                   AND ( %d = 0 OR t.language_code = %s )
                 ORDER BY tc_filter.display_order DESC
                 LIMIT %d OFFSET %d",
                $table,
                $pivot_table,
                ...array_merge($where_values, [$pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        } elseif ($order === 'ASC') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT t.*
                 FROM %i t
                 LEFT JOIN %i tc_filter ON t.id = tc_filter.testimonial_id
                 WHERE 1=1
                   AND ( %d = 0 OR ( t.client_name LIKE %s OR t.testimonial_text LIKE %s OR t.client_company LIKE %s ) )
                   AND ( %d = 0 OR FIND_IN_SET(t.status, %s) )
                   AND ( %d = 0 OR tc_filter.testimonial_id IS NULL )
                   AND ( %d = 0 OR FIND_IN_SET(tc_filter.category_id, %s) )
                   AND ( %d = 0 OR t.rating >= %d )
                   AND ( %d = 0 OR t.rating <= %d )
                   AND ( %d = 0 OR t.is_featured = %d )
                   AND ( %d = 0 OR t.created_at >= %s )
                   AND ( %d = 0 OR t.created_at <= %s )
                   AND ( %d = 0 OR t.created_by = %d )
                   AND ( %d = 0 OR ( t.language_code = %s OR t.language_code IS NULL OR t.translation_group IS NULL ) )
                   AND ( %d = 0 OR t.language_code = %s )
                 ORDER BY t.%i ASC
                 LIMIT %d OFFSET %d",
                $table,
                $pivot_table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT t.*
                 FROM %i t
                 LEFT JOIN %i tc_filter ON t.id = tc_filter.testimonial_id
                 WHERE 1=1
                   AND ( %d = 0 OR ( t.client_name LIKE %s OR t.testimonial_text LIKE %s OR t.client_company LIKE %s ) )
                   AND ( %d = 0 OR FIND_IN_SET(t.status, %s) )
                   AND ( %d = 0 OR tc_filter.testimonial_id IS NULL )
                   AND ( %d = 0 OR FIND_IN_SET(tc_filter.category_id, %s) )
                   AND ( %d = 0 OR t.rating >= %d )
                   AND ( %d = 0 OR t.rating <= %d )
                   AND ( %d = 0 OR t.is_featured = %d )
                   AND ( %d = 0 OR t.created_at >= %s )
                   AND ( %d = 0 OR t.created_at <= %s )
                   AND ( %d = 0 OR t.created_by = %d )
                   AND ( %d = 0 OR ( t.language_code = %s OR t.language_code IS NULL OR t.translation_group IS NULL ) )
                   AND ( %d = 0 OR t.language_code = %s )
                 ORDER BY t.%i DESC
                 LIMIT %d OFFSET %d",
                $table,
                $pivot_table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        }

        // Attach categories and photo to each item
        if ($items) {
            $item_ids = array_column($items, 'id');
            $categories_map = $this->get_categories_for_testimonials($item_ids);

            foreach ($items as &$item) {
                $item['categories'] = $categories_map[$item['id']] ?? [];
                $item['client_photo'] = !empty($item['client_photo_id'])
                    ? $this->get_image_data((int) $item['client_photo_id'])
                    : null;
            }
            unset($item);

            /**
             * Testimonial rows on their way to the admin app. An extension adds its own
             * fields here (custom-field values).
             *
             * @param array[] $items Rows, each with categories and client_photo attached.
             */
            $items = apply_filters('plugixatestimonial_admin_testimonials', $items);

            // Attach translation maps when multilingual is active
            if ($multilingual->is_multilingual_active()) {
                foreach ($items as &$item) {
                    $item = $multilingual->append_translations($item, $table);
                }
                unset($item);
            }
        }

        return $this->success([
            'items' => $items ?: [],
            'total' => $total,
            'state' => [
                'filter'  => $filters,
                'limit'   => $pagination['limit'],
                'offset'  => $pagination['offset'],
                'orderby' => $orderby,
                'order'   => $order,
            ],
        ]);
    }

    public function get_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$item) {
            return $this->error(__('Testimonial not found', 'plugixa-testimonial'), 404);
        }

        // Attach categories
        $categories_map = $this->get_categories_for_testimonials([$id]);
        $item['categories'] = $categories_map[$id] ?? [];

        // Attach photo
        $item['client_photo'] = !empty($item['client_photo_id'])
            ? $this->get_image_data((int) $item['client_photo_id'])
            : null;

        // Fields an extension adds (see the list route).
        $item = apply_filters('plugixatestimonial_admin_testimonials', [$item])[0];

        // Author name
        if (!empty($item['created_by'])) {
            $user = get_user_by('id', (int) $item['created_by']);
            $item['author_name'] = $user ? $user->display_name : __('Unknown', 'plugixa-testimonial');
        } else {
            $item['author_name'] = __('Unknown', 'plugixa-testimonial');
        }

        // Translations map
        $multilingual = Multilingual::instance();
        $item = $multilingual->append_translations($item, $table);

        return $this->success($item);
    }

    public function create_item($request) {
        global $wpdb;

        $data = $this->sanitize_testimonial_data($request);
        $errors = $this->validate_testimonial_data($data);

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        $categories = $data['_categories'] ?? [];
        unset($data['_categories']);

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $data['created_at'] = current_time('mysql');
        $data['created_by'] = get_current_user_id();

        // Multilingual: set language_code and translation_group
        $ml = Multilingual::instance();
        if ($ml->is_multilingual_active()) {
            $lang_code = $request->get_param('language_code');
            if (empty($lang_code)) {
                $lang_code = $ml->get_current_language();
            }
            if (empty($lang_code)) {
                $lang_code = $ml->get_default_language();
            }
            $data['language_code'] = sanitize_key($lang_code);
            $translation_of = (int) ($request->get_param('translation_of') ?? 0);
            if ($translation_of > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $group = $wpdb->get_var($wpdb->prepare("SELECT translation_group FROM %i WHERE id = %d", $table,
                    $translation_of
                ));
                if (!empty($group)) {
                    $data['translation_group'] = $group;
                }
            }
        }

        // Auto-approve if settings say so
        $settings = get_option('plugixatestimonial_settings', []);
        if (!empty($settings['auto_approve']) && $data['status'] === 'pending') {
            $data['status'] = 'approved';
            $data['approved_at'] = current_time('mysql');
            $data['approved_by'] = get_current_user_id();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error(__('Failed to create testimonial', 'plugixa-testimonial'), 500);
        }

        $insert_id = $wpdb->insert_id;

        // Assign language + translation_group for new items
        if ($ml->is_multilingual_active() && !empty($data['language_code'])) {
            $ml->assign_language($table, $insert_id, $data['language_code'], $data['translation_group'] ?? null);
        }

        /**
         * A testimonial row was created. Notifications can listen here.
         *
         * @param int    $insert_id Testimonial id.
         * @param array  $data      The stored column values.
         * @param string $context   'admin' (this route) or 'submission' (the public form).
         */
        do_action('plugixatestimonial_testimonial_created', $insert_id, $data, 'admin');

        // Sync categories via pivot table
        $this->sync_categories($insert_id, $categories);

        /**
         * A testimonial was saved through the admin route. An extension stores the
         * request fields it owns (custom-field values) here.
         *
         * @param int             $id      Testimonial id.
         * @param WP_REST_Request $request The create or update request.
         */
        do_action('plugixatestimonial_testimonial_saved', $insert_id, $request);

        return $this->success(
            ['id' => $insert_id],
            __('Testimonial created successfully', 'plugixa-testimonial')
        );
    }

    public function update_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $data = $this->sanitize_testimonial_data($request);
        $errors = $this->validate_testimonial_data($data, $id);

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        $categories = $data['_categories'] ?? [];
        unset($data['_categories']);

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';

        // Track status change for approval timestamp
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $old_item = $wpdb->get_row($wpdb->prepare("SELECT status FROM %i WHERE id = %d", $table, $id), ARRAY_A);
        if ($old_item && $old_item['status'] !== 'approved' && $data['status'] === 'approved') {
            $data['approved_at'] = current_time('mysql');
            $data['approved_by'] = get_current_user_id();
        }

        $data['modified_at'] = current_time('mysql');
        $data['modified_by'] = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->update($table, $data, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to update testimonial', 'plugixa-testimonial'), 500);
        }

        // Sync non-translatable fields across translation group
        $ml = Multilingual::instance();
        if ($ml->is_multilingual_active()) {
            $sync_fields = [];
            if (array_key_exists('rating', $data)) $sync_fields['rating'] = $data['rating'];
            if (array_key_exists('is_featured', $data)) $sync_fields['is_featured'] = $data['is_featured'];
            if (array_key_exists('status', $data)) $sync_fields['status'] = $data['status'];
            if (array_key_exists('client_photo_id', $data)) $sync_fields['client_photo_id'] = $data['client_photo_id'];
            if (!empty($sync_fields)) {
                $ml->sync_translation_group($table, $id, $sync_fields);
            }
        }

        // Sync categories
        if ($request->has_param('categories')) {
            $this->sync_categories($id, $categories);
        }

        /**
         * A testimonial was saved through the admin route. An extension stores the
         * request fields it owns (custom-field values) here.
         *
         * @param int             $id      Testimonial id.
         * @param WP_REST_Request $request The create or update request.
         */
        do_action('plugixatestimonial_testimonial_saved', $id, $request);

        return $this->success(
            ['id' => $id],
            __('Testimonial updated successfully', 'plugixa-testimonial')
        );
    }

    public function delete_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';

        // Optionally delete all translations in the same group
        $delete_all = (bool) $request->get_param('delete_all_translations');
        $ids_to_delete = [$id];

        if ($delete_all) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $group = $wpdb->get_var($wpdb->prepare("SELECT translation_group FROM %i WHERE id = %d", $table,
                $id
            ));
            if ($group) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $group_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE translation_group = %s", $table,
                    $group
                ));
                if ($group_ids) {
                    $ids_to_delete = array_map('intval', $group_ids);
                }
            }
        }

        // FIND_IN_SET against a comma-joined list of ints keeps the placeholder count
        // fixed at one, so each statement below stays a constant literal.
        $ids_csv = implode(',', array_map('intval', $ids_to_delete));

        // Delete pivot entries and meta first
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(testimonial_id, %s)", $pivot_table, $ids_csv));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(testimonial_id, %s)", $meta_table, $ids_csv));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $result = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(id, %s)", $table, $ids_csv));

        if ($result === false) {
            return $this->error(__('Failed to delete testimonial', 'plugixa-testimonial'), 500);
        }

        return $this->success(null, __('Testimonial deleted successfully', 'plugixa-testimonial'));
    }

    public function delete_items($request) {
        global $wpdb;

        $ids = $request->get_param('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->error(__('No IDs provided', 'plugixa-testimonial'), 400);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';
        $ids = array_map('intval', $ids);
        $ids_csv = implode(',', $ids);

        // Delete pivot entries and meta
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(testimonial_id, %s)", $pivot_table, $ids_csv));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(testimonial_id, %s)", $meta_table, $ids_csv));

        // Delete testimonials
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $result = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(id, %s)", $table, $ids_csv));

        if ($result === false) {
            return $this->error(__('Failed to delete testimonials', 'plugixa-testimonial'), 500);
        }

        return $this->success(
            ['deleted' => $result],
            /* translators: %d: number of testimonials deleted */
            sprintf(__('%d testimonials deleted successfully', 'plugixa-testimonial'), $result)
        );
    }

    public function change_status($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $status = sanitize_key($request->get_param('status') ?? '');

        $valid_statuses = ['pending', 'approved', 'rejected', 'draft'];
        if (!in_array($status, $valid_statuses)) {
            return $this->error(__('Invalid status', 'plugixa-testimonial'), 400);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';

        $update_data = [
            'status'      => $status,
            'modified_at' => current_time('mysql'),
            'modified_by' => get_current_user_id(),
        ];

        if ($status === 'approved') {
            $update_data['approved_at'] = current_time('mysql');
            $update_data['approved_by'] = get_current_user_id();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->update($table, $update_data, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to update status', 'plugixa-testimonial'), 500);
        }

        return $this->success(
            ['id' => $id, 'status' => $status],
            __('Status updated successfully', 'plugixa-testimonial')
        );
    }

    public function bulk_action($request) {
        global $wpdb;

        $ids = $request->get_param('ids');
        $action = sanitize_key($request->get_param('action') ?? '');

        if (empty($ids) || !is_array($ids)) {
            return $this->error(__('No IDs provided', 'plugixa-testimonial'), 400);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $ids = array_map('intval', $ids);
        $ids_csv = implode(',', $ids);

        switch ($action) {
            case 'approve':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
                $result = $wpdb->query($wpdb->prepare("UPDATE %i SET status = 'approved', approved_at = %s, approved_by = %d, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)", $table,
                    current_time('mysql'), get_current_user_id(), current_time('mysql'), get_current_user_id(), $ids_csv
                ));
                break;

            case 'reject':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
                $result = $wpdb->query($wpdb->prepare("UPDATE %i SET status = 'rejected', modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)", $table,
                    current_time('mysql'), get_current_user_id(), $ids_csv
                ));
                break;

            case 'feature':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
                $result = $wpdb->query($wpdb->prepare("UPDATE %i SET is_featured = 1, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)", $table,
                    current_time('mysql'), get_current_user_id(), $ids_csv
                ));
                break;

            case 'unfeature':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
                $result = $wpdb->query($wpdb->prepare("UPDATE %i SET is_featured = 0, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)", $table,
                    current_time('mysql'), get_current_user_id(), $ids_csv
                ));
                break;

            default:
                return $this->error(__('Invalid action', 'plugixa-testimonial'), 400);
        }

        if ($result === false) {
            return $this->error(__('Failed to perform bulk action', 'plugixa-testimonial'), 500);
        }

        return $this->success(
            ['affected' => $result],
            /* translators: %d: number of testimonials affected */
            sprintf(__('Bulk action completed on %d testimonials', 'plugixa-testimonial'), $result)
        );
    }

    public function reorder_items($request) {
        global $wpdb;

        $items = $request->get_param('items');
        $category_id = (int) ($request->get_param('category_id') ?? 0);

        if (empty($items) || !is_array($items)) {
            return $this->error(__('No items provided', 'plugixa-testimonial'), 400);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        if ($category_id > 0) {
            // Per-category ordering: update display_order on the pivot table
            foreach ($items as $item) {
                if (!isset($item['id']) || !isset($item['display_order'])) {
                    continue;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->update(
                    $pivot_table,
                    ['display_order' => (int) $item['display_order']],
                    [
                        'testimonial_id' => (int) $item['id'],
                        'category_id'    => $category_id,
                    ]
                );
            }
        } else {
            // Global ordering (uncategorized): update display_order on testimonials table
            foreach ($items as $item) {
                if (!isset($item['id']) || !isset($item['display_order'])) {
                    continue;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->update(
                    $table,
                    ['display_order' => (int) $item['display_order']],
                    ['id' => (int) $item['id']]
                );
            }
        }

        return $this->success(null, __('Order updated successfully', 'plugixa-testimonial'));
    }

    public function get_authors($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $authors = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT u.ID as id, u.display_name as name FROM %i t INNER JOIN %i u ON t.created_by = u.ID WHERE t.created_by IS NOT NULL ORDER BY u.display_name ASC",
            $table,
            $wpdb->users
        ), ARRAY_A);

        return $this->success($authors ?: []);
    }

    /**
     * Get categories for a list of testimonial IDs via pivot table
     */
    private function get_categories_for_testimonials(array $ids): array {
        global $wpdb;

        if (empty($ids)) {
            return [];
        }

        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $categories_table = $wpdb->prefix . 'plugixatestimonial_categories';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT tc.testimonial_id, c.id, c.name, c.slug FROM %i tc INNER JOIN %i c ON tc.category_id = c.id WHERE FIND_IN_SET(tc.testimonial_id, %s) ORDER BY c.name ASC",
            $pivot_table,
            $categories_table,
            implode(',', array_map('intval', $ids))
        ), ARRAY_A);

        $map = [];
        foreach ($results as $row) {
            $tid = $row['testimonial_id'];
            if (!isset($map[$tid])) {
                $map[$tid] = [];
            }
            $map[$tid][] = [
                'id'   => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
            ];
        }

        return $map;
    }

    /**
     * Sync testimonial categories via pivot table (delete-then-insert)
     */
    private function sync_categories(int $testimonial_id, array $category_ids): void {
        global $wpdb;

        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // Read existing display_order values before deleting
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $existing = $wpdb->get_results(
            $wpdb->prepare("SELECT category_id, display_order FROM %i WHERE testimonial_id = %d", $pivot_table,
                $testimonial_id
            ),
            ARRAY_A
        );
        $order_map = [];
        foreach ($existing as $row) {
            $order_map[(int) $row['category_id']] = (int) $row['display_order'];
        }

        // Delete existing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->delete($pivot_table, ['testimonial_id' => $testimonial_id]);

        // Insert new, restoring display_order for categories that still exist
        foreach ($category_ids as $cat_id) {
            $cat_id = (int) $cat_id;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->insert($pivot_table, [
                'testimonial_id' => $testimonial_id,
                'category_id'    => $cat_id,
                'display_order'  => $order_map[$cat_id] ?? 0,
            ]);
        }
    }

    private function get_image_data(int $image_id): ?array {
        $attachment = get_post($image_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            return null;
        }

        $full_url = wp_get_attachment_url($image_id);
        $thumbnail_url = wp_get_attachment_image_url($image_id, 'thumbnail');

        return [
            'id'        => $image_id,
            'url'       => $full_url,
            'thumbnail' => $thumbnail_url ?: $full_url,
            'alt'       => get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: '',
            'title'     => $attachment->post_title ?: '',
        ];
    }

    private function sanitize_testimonial_data($request): array {
        $data = [
            'client_name'      => sanitize_text_field($request->get_param('client_name') ?? ''),
            'testimonial_text' => wp_kses_post($request->get_param('testimonial_text') ?? ''),
            'rating'           => max(1, min(5, (int) ($request->get_param('rating') ?? 5))),
            'status'           => sanitize_key($request->get_param('status') ?? 'pending'),
            'is_featured'      => (int) (bool) $request->get_param('is_featured'),
            'display_order'    => (int) ($request->get_param('display_order') ?? 0),
        ];

        if ($request->has_param('client_role')) {
            $data['client_role'] = sanitize_text_field($request->get_param('client_role') ?? '');
        }
        if ($request->has_param('client_company')) {
            $data['client_company'] = sanitize_text_field($request->get_param('client_company') ?? '');
        }
        if ($request->has_param('website_url')) {
            $data['website_url'] = esc_url_raw($request->get_param('website_url') ?? '');
        }
        if ($request->has_param('source')) {
            $data['source'] = sanitize_text_field($request->get_param('source') ?? '');
        }
        if ($request->has_param('client_photo_id')) {
            $photo_id = $request->get_param('client_photo_id');
            $data['client_photo_id'] = $photo_id ? (int) $photo_id : null;
        }

        // Extract categories for pivot table sync (not stored in testimonials table)
        if ($request->has_param('categories')) {
            $categories = $request->get_param('categories');
            $data['_categories'] = is_array($categories) ? array_map('intval', $categories) : [];
        }

        /**
         * The column values about to be written for a testimonial. An extension adds
         * the columns it owns (video_url).
         *
         * @param array           $data    Column values, plus the internal _categories key.
         * @param WP_REST_Request $request The create or update request.
         */
        return apply_filters('plugixatestimonial_testimonial_data', $data, $request);
    }

    private function validate_testimonial_data(array $data, ?int $id = null): array {
        $errors = [];

        if (empty($data['client_name'])) {
            $errors['client_name'] = __('Client name is required', 'plugixa-testimonial');
        }

        if (empty($data['testimonial_text'])) {
            $errors['testimonial_text'] = __('Testimonial text is required', 'plugixa-testimonial');
        } elseif (strlen(wp_strip_all_tags($data['testimonial_text'])) < 10) {
            $errors['testimonial_text'] = __('Testimonial text must be at least 10 characters', 'plugixa-testimonial');
        }

        $valid_statuses = ['pending', 'approved', 'rejected', 'draft'];
        if (!in_array($data['status'], $valid_statuses)) {
            $errors['status'] = __('Invalid status', 'plugixa-testimonial');
        }

        if ($data['rating'] < 1 || $data['rating'] > 5) {
            $errors['rating'] = __('Rating must be between 1 and 5', 'plugixa-testimonial');
        }

        return $errors;
    }

    public function get_create_params(): array {
        /**
         * Arguments of the testimonial create/update routes. An extension declares the
         * request fields it owns (video_url, custom_fields).
         *
         * @param array $params Route argument schema.
         */
        return apply_filters('plugixatestimonial_testimonial_params', [
            'client_name' => ['type' => 'string', 'required' => true],
            'testimonial_text' => ['type' => 'string', 'required' => true],
            'rating' => ['type' => 'integer', 'default' => 5, 'minimum' => 1, 'maximum' => 5],
            'client_role' => ['type' => ['string', 'null'], 'required' => false],
            'client_company' => ['type' => ['string', 'null'], 'required' => false],
            'client_photo_id' => ['type' => ['integer', 'null'], 'required' => false],
            'website_url' => ['type' => ['string', 'null'], 'required' => false],
            'status' => ['type' => 'string', 'default' => 'pending', 'enum' => ['pending', 'approved', 'rejected', 'draft']],
            'is_featured' => ['type' => 'boolean', 'default' => false],
            'display_order' => ['type' => 'integer', 'default' => 0],
            'source' => ['type' => 'string', 'required' => false],
            'categories' => ['type' => 'array', 'required' => false],
            'language_code' => ['type' => 'string', 'required' => false],
            'translation_of' => ['type' => 'integer', 'required' => false],
        ]);
    }

    public function get_update_params(): array {
        $params = $this->get_create_params();
        foreach ($params as &$param) {
            unset($param['required']);
        }
        return $params;
    }
}
