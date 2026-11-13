<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Shortcodes\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Shortcodes REST Controller - CRUD + duplicate
 */
class ShortcodesController extends RestController {

    private array $default_config = [
        'view_id' => null,
        'categories' => [],
        'limit' => -1,
        'orderby' => 'display_order',
        'order' => 'ASC',
        'featured_only' => false,
        'min_rating' => 0,
        'display' => [
            'showPhoto' => true,
            'showRating' => true,
            'showRole' => true,
            'showCompany' => true,
            'showDate' => true,
            'showWebsite' => false,
            'showQuoteIcon' => true,
            'showCustomFields' => true,
        ],
        'schema' => true,
    ];

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'shortcodes';
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'duplicate_item'],
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
    }

    public function get_items($request) {
        global $wpdb;

        $pagination = $this->get_pagination($request);
        $filters = $this->get_filters($request);
        $sort = $this->get_sort($request);

        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';

        // The search filter is a bound gate inside one constant-literal WHERE rather
        // than an appended fragment, so the query text never varies. Gate 0 disables it.
        $search_gate = !empty($filters['search']) ? 1 : 0;
        $search      = $search_gate ? '%' . $wpdb->esc_like($filters['search']) . '%' : '%';
        $where_values = [$search_gate, $search, $search, $search];

        $allowed_orderby = ['id', 'name', 'slug', 'created_at', 'modified_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE 1=1
               AND ( %d = 0 OR ( name LIKE %s OR slug LIKE %s OR description LIKE %s ) )",
            $table,
            ...$where_values
        ));

        // $orderby is allowlisted above and bound as an identifier with %i, but a sort
        // DIRECTION cannot be a placeholder -- so ASC and DESC are separate literals.
        if ($order === 'ASC') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i
                 WHERE 1=1
                   AND ( %d = 0 OR ( name LIKE %s OR slug LIKE %s OR description LIKE %s ) )
                 ORDER BY %i ASC
                 LIMIT %d OFFSET %d",
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i
                 WHERE 1=1
                   AND ( %d = 0 OR ( name LIKE %s OR slug LIKE %s OR description LIKE %s ) )
                 ORDER BY %i DESC
                 LIMIT %d OFFSET %d",
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        }

        if ($items) {
            foreach ($items as &$item) {
                $item['config'] = json_decode($item['config'], true) ?: $this->default_config;
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
        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$item) {
            return $this->error(__('Shortcode not found', 'plugixa-testimonial'), 404);
        }

        $item['config'] = json_decode($item['config'], true) ?: $this->default_config;

        return $this->success($item);
    }

    public function create_item($request) {
        global $wpdb;

        $data = $this->sanitize_shortcode_data($request);
        $errors = $this->validate_shortcode_data($data);

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';

        if (empty($data['slug'])) {
            $data['slug'] = $this->generate_unique_slug($data['name']);
        } else {
            $data['slug'] = $this->ensure_unique_slug($data['slug']);
        }

        $config = $request->get_param('config');
        $data['config'] = wp_json_encode(
            is_array($config) ? array_replace_recursive($this->default_config, $config) : $this->default_config
        );

        $data['created_at'] = current_time('mysql');
        $data['created_by'] = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error(__('Failed to create shortcode', 'plugixa-testimonial'), 500);
        }

        return $this->success(
            ['id' => $wpdb->insert_id],
            __('Shortcode created successfully', 'plugixa-testimonial')
        );
    }

    public function update_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $data = $this->sanitize_shortcode_data($request);
        $errors = $this->validate_shortcode_data($data, $id);

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';

        if (!empty($data['slug'])) {
            $data['slug'] = $this->ensure_unique_slug($data['slug'], $id);
        }

        if ($request->has_param('config')) {
            $config = $request->get_param('config');
            $data['config'] = wp_json_encode(
                is_array($config) ? array_replace_recursive($this->default_config, $config) : $this->default_config
            );
        }

        $data['modified_at'] = current_time('mysql');
        $data['modified_by'] = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->update($table, $data, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to update shortcode', 'plugixa-testimonial'), 500);
        }

        return $this->success(
            ['id' => $id],
            __('Shortcode updated successfully', 'plugixa-testimonial')
        );
    }

    public function delete_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->delete($table, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to delete shortcode', 'plugixa-testimonial'), 500);
        }

        return $this->success(null, __('Shortcode deleted successfully', 'plugixa-testimonial'));
    }

    public function delete_items($request) {
        global $wpdb;

        $ids = $request->get_param('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->error(__('No IDs provided', 'plugixa-testimonial'), 400);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';
        $ids = array_map('intval', $ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $result = $wpdb->query(
            $wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(id, %s)", $table, implode(',', $ids))
        );

        return $this->success(
            ['deleted' => $result],
            /* translators: %d: number of shortcodes deleted */
            sprintf(__('%d shortcodes deleted successfully', 'plugixa-testimonial'), $result)
        );
    }

    public function duplicate_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $original = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$original) {
            return $this->error(__('Shortcode not found', 'plugixa-testimonial'), 404);
        }

        $new_name = $original['name'] . ' (Copy)';
        $new_slug = $this->generate_unique_slug($new_name);

        $data = [
            'name'        => $new_name,
            'slug'        => $new_slug,
            'description' => $original['description'],
            'config'      => $original['config'],
            'created_at'  => current_time('mysql'),
            'created_by'  => get_current_user_id(),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error(__('Failed to duplicate shortcode', 'plugixa-testimonial'), 500);
        }

        return $this->success(
            ['id' => $wpdb->insert_id],
            __('Shortcode duplicated successfully', 'plugixa-testimonial')
        );
    }

    private function sanitize_shortcode_data($request): array {
        $data = [];

        if ($request->has_param('name')) {
            $data['name'] = sanitize_text_field($request->get_param('name'));
        }
        if ($request->has_param('slug')) {
            $data['slug'] = sanitize_title($request->get_param('slug'));
        }
        if ($request->has_param('description')) {
            $data['description'] = sanitize_textarea_field($request->get_param('description'));
        }

        return $data;
    }

    private function validate_shortcode_data(array $data, ?int $id = null): array {
        $errors = [];

        if (isset($data['name']) && empty($data['name'])) {
            $errors['name'] = __('Name is required', 'plugixa-testimonial');
        }

        return $errors;
    }

    private function generate_unique_slug(string $name): string {
        return $this->ensure_unique_slug(sanitize_title($name));
    }

    private function ensure_unique_slug(string $slug, ?int $exclude_id = null): string {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';
        $original_slug = $slug;
        $counter = 1;

        while (true) {
            // Two literal statements rather than one assembled string, so the SQL is
            // inline in the prepare() call it belongs to.
            if ($exclude_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM %i WHERE slug = %s AND id != %d",
                    $table,
                    $slug,
                    $exclude_id
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM %i WHERE slug = %s",
                    $table,
                    $slug
                ));
            }

            if (!$existing) {
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
            'config' => ['type' => 'object', 'required' => false],
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
