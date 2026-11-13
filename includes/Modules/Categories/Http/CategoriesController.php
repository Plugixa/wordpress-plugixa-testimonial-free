<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Categories\Http;

if (!defined('ABSPATH')) { exit; }

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaTestimonial\Core\Http\RestController;
use PlugixaTestimonial\Core\Multilingual;

/**
 * Categories REST Controller - CRUD + testimonial_count via pivot JOIN
 */
class CategoriesController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'categories';
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
                'permission_callback' => [$this, 'check_permission'],
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
                'permission_callback' => [$this, 'check_permission'],
                'args'                => $this->get_update_params(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder_items'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);
    }

    public function get_items($request) {
        global $wpdb;

        $pagination = $this->get_pagination($request);
        $filters = $this->get_filters($request);
        $sort = $this->get_sort($request);

        $table = $wpdb->prefix . 'plugixatestimonial_categories';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // Bound gates inside one constant-literal WHERE; gate 0 disables its test.
        // The status pair is complementary: an explicit status replaces the default
        // "anything but trash", so the two gates are the same flag read both ways.
        $status = $request->get_param('status') ?? '';
        $status_gate = !empty($status) ? 1 : 0;
        $status_val  = $status_gate ? sanitize_key($status) : '';

        $search_gate = !empty($filters['search']) ? 1 : 0;
        $search      = $search_gate ? '%' . $wpdb->esc_like($filters['search']) . '%' : '%';

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

        $where_values = [
            $status_gate, $status_val,
            $status_gate,
            $search_gate, $search, $search,
            $group_gate, $group_lang,
            $lang_gate, $lang,
        ];

        $allowed_orderby = ['id', 'name', 'slug', 'display_order', 'created_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'display_order';
        $order = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i c
             WHERE 1=1
               AND ( %d = 0 OR c.status = %s )
               AND ( %d = 1 OR c.status != 'trash' )
               AND ( %d = 0 OR ( c.name LIKE %s OR c.description LIKE %s ) )
               AND ( %d = 0 OR ( c.language_code = %s OR c.language_code IS NULL OR c.translation_group IS NULL ) )
               AND ( %d = 0 OR c.language_code = %s )",
            $table,
            ...$where_values
        ));

        // $orderby is allowlisted above and bound as an identifier with %i, but a sort
        // DIRECTION cannot be a placeholder -- so ASC and DESC are separate literals.
        if ($order === 'ASC') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, (SELECT COUNT(*) FROM %i tc WHERE tc.category_id = c.id) as testimonial_count
                 FROM %i c
                 WHERE 1=1
                   AND ( %d = 0 OR c.status = %s )
                   AND ( %d = 1 OR c.status != 'trash' )
                   AND ( %d = 0 OR ( c.name LIKE %s OR c.description LIKE %s ) )
                   AND ( %d = 0 OR ( c.language_code = %s OR c.language_code IS NULL OR c.translation_group IS NULL ) )
                   AND ( %d = 0 OR c.language_code = %s )
                 ORDER BY c.%i ASC
                 LIMIT %d OFFSET %d",
                $pivot_table,
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, (SELECT COUNT(*) FROM %i tc WHERE tc.category_id = c.id) as testimonial_count
                 FROM %i c
                 WHERE 1=1
                   AND ( %d = 0 OR c.status = %s )
                   AND ( %d = 1 OR c.status != 'trash' )
                   AND ( %d = 0 OR ( c.name LIKE %s OR c.description LIKE %s ) )
                   AND ( %d = 0 OR ( c.language_code = %s OR c.language_code IS NULL OR c.translation_group IS NULL ) )
                   AND ( %d = 0 OR c.language_code = %s )
                 ORDER BY c.%i DESC
                 LIMIT %d OFFSET %d",
                $pivot_table,
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        }

        // Attach translation maps when multilingual is active
        if ($items && $multilingual->is_multilingual_active()) {
            foreach ($items as &$item) {
                $item = $multilingual->append_translations($item, $table);
            }
            unset($item);
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
        $table = $wpdb->prefix . 'plugixatestimonial_categories';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, (SELECT COUNT(*) FROM %i tc WHERE tc.category_id = c.id) as testimonial_count FROM %i c WHERE c.id = %d",
            $pivot_table,
            $table,
            $id
        ), ARRAY_A);

        if (!$item) {
            return $this->error(__('Category not found', 'plugixa-testimonial'), 404);
        }

        // Translations map
        $multilingual = Multilingual::instance();
        $item = $multilingual->append_translations($item, $table);

        return $this->success($item);
    }

    public function create_item($request) {
        global $wpdb;

        $status_raw = sanitize_key($request->get_param('status') ?? 'publish');
        $allowed_statuses = ['publish', 'draft', 'trash'];

        $data = [
            'name'          => sanitize_text_field($request->get_param('name') ?? ''),
            'slug'          => sanitize_title($request->get_param('slug') ?? ''),
            'description'   => sanitize_textarea_field($request->get_param('description') ?? ''),
            'display_order' => (int) ($request->get_param('display_order') ?? 0),
            'status'        => in_array($status_raw, $allowed_statuses) ? $status_raw : 'publish',
        ];

        $errors = [];
        if (empty($data['name'])) {
            $errors['name'] = __('Category name is required', 'plugixa-testimonial');
        }

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        if (empty($data['slug'])) {
            $data['slug'] = $this->generate_unique_slug($data['name']);
        } else {
            $data['slug'] = $this->generate_unique_slug($data['slug']);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_categories';
        $data['created_at'] = current_time('mysql');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error(__('Failed to create category', 'plugixa-testimonial'), 500);
        }

        $insert_id = $wpdb->insert_id;

        // Multilingual: assign language and translation_group
        $ml = Multilingual::instance();
        if ($ml->is_multilingual_active()) {
            $lang_code = $request->get_param('language_code');
            if (empty($lang_code)) {
                $lang_code = $ml->get_default_language();
            }
            $lang_code = sanitize_key($lang_code);
            $translation_of = (int) ($request->get_param('translation_of') ?? 0);
            $group = null;
            if ($translation_of > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $group = $wpdb->get_var($wpdb->prepare("SELECT translation_group FROM %i WHERE id = %d", $table, $translation_of
                ));
            }
            $ml->assign_language($table, $insert_id, $lang_code, $group ?: null);
        }

        return $this->success(
            ['id' => $insert_id],
            __('Category created successfully', 'plugixa-testimonial')
        );
    }

    public function update_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $status_raw = sanitize_key($request->get_param('status') ?? 'publish');
        $allowed_statuses = ['publish', 'draft', 'trash'];

        $data = [
            'name'          => sanitize_text_field($request->get_param('name') ?? ''),
            'slug'          => sanitize_title($request->get_param('slug') ?? ''),
            'description'   => sanitize_textarea_field($request->get_param('description') ?? ''),
            'display_order' => (int) ($request->get_param('display_order') ?? 0),
            'status'        => in_array($status_raw, $allowed_statuses) ? $status_raw : 'publish',
        ];

        $errors = [];
        if (empty($data['name'])) {
            $errors['name'] = __('Category name is required', 'plugixa-testimonial');
        }

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        if (!empty($data['slug'])) {
            $data['slug'] = $this->generate_unique_slug($data['slug'], $id);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_categories';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->update($table, $data, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to update category', 'plugixa-testimonial'), 500);
        }

        // Sync shared fields across translation group
        $ml = Multilingual::instance();
        if ($ml->is_multilingual_active()) {
            $sync_fields = [];
            if (array_key_exists('display_order', $data)) {
                $sync_fields['display_order'] = $data['display_order'];
            }
            if (array_key_exists('status', $data)) {
                $sync_fields['status'] = $data['status'];
            }
            if (!empty($sync_fields)) {
                $ml->sync_translation_group($table, $id, $sync_fields);
            }
        }

        return $this->success(
            ['id' => $id],
            __('Category updated successfully', 'plugixa-testimonial')
        );
    }

    public function delete_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_categories';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // Optionally delete all translations in the same group
        $delete_all = (bool) $request->get_param('delete_all_translations');
        $ids_to_delete = [$id];

        if ($delete_all) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $group = $wpdb->get_var($wpdb->prepare("SELECT translation_group FROM %i WHERE id = %d", $table, $id
            ));
            if ($group) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $group_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE translation_group = %s", $table, $group
                ));
                if ($group_ids) {
                    $ids_to_delete = array_map('intval', $group_ids);
                }
            }
        }

        // FIND_IN_SET against a comma-joined list of ints keeps the placeholder count
        // fixed at one, so each statement stays a constant literal.
        $ids_csv = implode(',', array_map('intval', $ids_to_delete));

        // Remove pivot entries
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(category_id, %s)", $pivot_table, $ids_csv));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $result = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(id, %s)", $table, $ids_csv));

        if ($result === false) {
            return $this->error(__('Failed to delete category', 'plugixa-testimonial'), 500);
        }

        return $this->success(null, __('Category deleted successfully', 'plugixa-testimonial'));
    }

    public function reorder_items($request) {
        global $wpdb;

        $items = $request->get_param('items');

        if (empty($items) || !is_array($items)) {
            return $this->error(__('No items provided', 'plugixa-testimonial'), 400);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_categories';

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

        return $this->success(null, __('Order updated successfully', 'plugixa-testimonial'));
    }

    private function generate_unique_slug(string $slug, ?int $exclude_id = null): string {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_categories';
        $slug = sanitize_title($slug);
        $original_slug = $slug;
        $counter = 1;

        // The "exclude this id" filter is a bound gate rather than an interpolated
        // clause, so the query stays one constant literal: gate 0 disables the test.
        $exclude_gate = $exclude_id ? 1 : 0;

        while (true) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE slug = %s AND ( %d = 0 OR id != %d )", $table, $slug, $exclude_gate, (int) $exclude_id));

            if (!$exists) {
                break;
            }

            $slug = $original_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    public function get_create_params(): array {
        return [
            'name' => ['type' => 'string', 'required' => true],
            'slug' => ['type' => 'string', 'required' => false],
            'description' => ['type' => 'string', 'required' => false],
            'display_order' => ['type' => 'integer', 'default' => 0],
            'status' => ['type' => 'string', 'default' => 'publish'],
            'language_code' => ['type' => 'string', 'required' => false],
            'translation_of' => ['type' => 'integer', 'required' => false],
        ];
    }

    public function get_update_params(): array {
        $params = $this->get_create_params();
        foreach ($params as &$param) {
            unset($param['required']);
        }
        return $params;
    }
}
