<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\CustomFields\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaTestimonial\Core\Multilingual;

/**
 * Custom Fields REST Controller - CRUD + reorder for field definitions
 */
class CustomFieldsController extends RestController {

    private const ALLOWED_TYPES = ['text', 'textarea', 'url', 'number', 'date', 'select', 'checkbox', 'color'];

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'custom-fields';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder_items'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);
    }

    public function get_items($request) {
        global $wpdb;

        $pagination = $this->get_pagination($request);
        $filters = $this->get_filters($request);
        $sort = $this->get_sort($request);

        $table = $wpdb->prefix . 'plugixatestimonial_custom_fields';
        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';

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

        $allowed_orderby = ['id', 'field_label', 'field_key', 'field_type', 'menu_order', 'created_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'menu_order';
        $order = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i cf
             WHERE 1=1
               AND ( %d = 0 OR cf.status = %s )
               AND ( %d = 1 OR cf.status != 'trash' )
               AND ( %d = 0 OR ( cf.field_label LIKE %s OR cf.field_key LIKE %s ) )
               AND ( %d = 0 OR ( cf.language_code = %s OR cf.language_code IS NULL OR cf.translation_group IS NULL ) )
               AND ( %d = 0 OR cf.language_code = %s )",
            $table,
            ...$where_values
        ));

        // $orderby is allowlisted above and bound as an identifier with %i, but a sort
        // DIRECTION cannot be a placeholder -- so ASC and DESC are separate literals.
        if ($order === 'ASC') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT cf.*, (SELECT COUNT(*) FROM %i m WHERE m.meta_key = cf.field_key) as value_count
                 FROM %i cf
                 WHERE 1=1
                   AND ( %d = 0 OR cf.status = %s )
                   AND ( %d = 1 OR cf.status != 'trash' )
                   AND ( %d = 0 OR ( cf.field_label LIKE %s OR cf.field_key LIKE %s ) )
                   AND ( %d = 0 OR ( cf.language_code = %s OR cf.language_code IS NULL OR cf.translation_group IS NULL ) )
                   AND ( %d = 0 OR cf.language_code = %s )
                 ORDER BY cf.%i ASC
                 LIMIT %d OFFSET %d",
                $meta_table,
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT cf.*, (SELECT COUNT(*) FROM %i m WHERE m.meta_key = cf.field_key) as value_count
                 FROM %i cf
                 WHERE 1=1
                   AND ( %d = 0 OR cf.status = %s )
                   AND ( %d = 1 OR cf.status != 'trash' )
                   AND ( %d = 0 OR ( cf.field_label LIKE %s OR cf.field_key LIKE %s ) )
                   AND ( %d = 0 OR ( cf.language_code = %s OR cf.language_code IS NULL OR cf.translation_group IS NULL ) )
                   AND ( %d = 0 OR cf.language_code = %s )
                 ORDER BY cf.%i DESC
                 LIMIT %d OFFSET %d",
                $meta_table,
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        }

        // Decode field_options JSON for each item
        if ($items) {
            foreach ($items as &$item) {
                $item['field_options'] = !empty($item['field_options']) ? json_decode($item['field_options'], true) : null;
                $item['is_required'] = (bool) $item['is_required'];
                $item['show_in_frontend'] = (bool) $item['show_in_frontend'];
            }
            unset($item);

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
        $table = $wpdb->prefix . 'plugixatestimonial_custom_fields';
        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT cf.*, (SELECT COUNT(*) FROM %i m WHERE m.meta_key = cf.field_key) as value_count FROM %i cf WHERE cf.id = %d",
            $meta_table,
            $table,
            $id
        ), ARRAY_A);

        if (!$item) {
            return $this->error(__('Custom field not found', 'plugixa-testimonial'), 404);
        }

        $item['field_options'] = !empty($item['field_options']) ? json_decode($item['field_options'], true) : null;
        $item['is_required'] = (bool) $item['is_required'];
        $item['show_in_frontend'] = (bool) $item['show_in_frontend'];

        // Translations map
        $multilingual = Multilingual::instance();
        $item = $multilingual->append_translations($item, $table);

        return $this->success($item);
    }

    public function create_item($request) {
        global $wpdb;

        $data = $this->sanitize_field_data($request);
        $errors = $this->validate_field_data($data);

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        // Auto-generate field_key from label if not provided
        if (empty($data['field_key'])) {
            $data['field_key'] = $this->generate_field_key($data['field_label']);
        }

        // Ensure unique field_key
        $table = $wpdb->prefix . 'plugixatestimonial_custom_fields';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE field_key = %s", $table, $data['field_key'])
        );

        if ($exists) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, [
                'field_key' => __('This field key already exists', 'plugixa-testimonial'),
            ]);
        }

        // Encode field_options as JSON
        if (!empty($data['field_options']) && is_array($data['field_options'])) {
            $data['field_options'] = wp_json_encode($data['field_options']);
        } else {
            $data['field_options'] = null;
        }

        $now = current_time('mysql');
        $data['created_at'] = $now;
        $data['modified_at'] = $now;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error(__('Failed to create custom field', 'plugixa-testimonial'), 500);
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
            __('Custom field created successfully', 'plugixa-testimonial')
        );
    }

    public function update_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_custom_fields';

        // Verify field exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$existing) {
            return $this->error(__('Custom field not found', 'plugixa-testimonial'), 404);
        }

        $data = $this->sanitize_field_data($request);

        // field_key is immutable — always keep the original
        $data['field_key'] = $existing['field_key'];

        $errors = $this->validate_field_data($data, $id);

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        // Encode field_options as JSON
        if (!empty($data['field_options']) && is_array($data['field_options'])) {
            $data['field_options'] = wp_json_encode($data['field_options']);
        } else {
            $data['field_options'] = null;
        }

        $data['modified_at'] = current_time('mysql');

        // Remove created_at from update data
        unset($data['created_at']);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->update($table, $data, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to update custom field', 'plugixa-testimonial'), 500);
        }

        // Sync shared fields across translation group
        $ml = Multilingual::instance();
        if ($ml->is_multilingual_active()) {
            $sync_fields = [];
            if (array_key_exists('status', $data)) {
                $sync_fields['status'] = $data['status'];
            }
            if (!empty($sync_fields)) {
                $ml->sync_translation_group($table, $id, $sync_fields);
            }
        }

        return $this->success(
            ['id' => $id],
            __('Custom field updated successfully', 'plugixa-testimonial')
        );
    }

    public function delete_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_custom_fields';
        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';

        // Get field_key before deleting
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $field_key = $wpdb->get_var(
            $wpdb->prepare("SELECT field_key FROM %i WHERE id = %d", $table, $id)
        );

        if (!$field_key) {
            return $this->error(__('Custom field not found', 'plugixa-testimonial'), 404);
        }

        // Cascade delete all meta rows with this field_key
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->delete($meta_table, ['meta_key' => $field_key]);

        // Delete the field definition
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->delete($table, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to delete custom field', 'plugixa-testimonial'), 500);
        }

        return $this->success(null, __('Custom field deleted successfully', 'plugixa-testimonial'));
    }

    public function reorder_items($request) {
        global $wpdb;

        $items = $request->get_param('items');

        if (empty($items) || !is_array($items)) {
            return $this->error(__('No items provided', 'plugixa-testimonial'), 400);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_custom_fields';

        foreach ($items as $item) {
            if (!isset($item['id']) || !isset($item['menu_order'])) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->update(
                $table,
                ['menu_order' => (int) $item['menu_order']],
                ['id' => (int) $item['id']]
            );
        }

        return $this->success(null, __('Order updated successfully', 'plugixa-testimonial'));
    }

    private function sanitize_field_data($request): array {
        $status_raw = sanitize_key($request->get_param('status') ?? 'publish');
        $allowed_statuses = ['publish', 'draft', 'trash'];

        $data = [
            'field_label'      => sanitize_text_field($request->get_param('field_label') ?? ''),
            'field_key'        => sanitize_key($request->get_param('field_key') ?? ''),
            'field_type'       => sanitize_key($request->get_param('field_type') ?? 'text'),
            'placeholder'      => sanitize_text_field($request->get_param('placeholder') ?? ''),
            'is_required'      => (int) (bool) $request->get_param('is_required'),
            'show_in_frontend' => (int) (bool) ($request->get_param('show_in_frontend') ?? true),
            'menu_order'       => (int) ($request->get_param('menu_order') ?? 0),
            'status'           => in_array($status_raw, $allowed_statuses) ? $status_raw : 'publish',
        ];

        // Handle field_options for select type
        $field_options = $request->get_param('field_options');
        if (is_array($field_options)) {
            $data['field_options'] = array_map('sanitize_text_field', $field_options);
        } elseif (is_string($field_options)) {
            $decoded = json_decode($field_options, true);
            $data['field_options'] = is_array($decoded) ? array_map('sanitize_text_field', $decoded) : null;
        } else {
            $data['field_options'] = null;
        }

        return $data;
    }

    private function validate_field_data(array $data, ?int $id = null): array {
        $errors = [];

        if (empty($data['field_label'])) {
            $errors['field_label'] = __('Field label is required', 'plugixa-testimonial');
        }

        if (!empty($data['field_key']) && !preg_match('/^[a-z0-9_]+$/', $data['field_key'])) {
            $errors['field_key'] = __('Field key must only contain lowercase letters, numbers, and underscores', 'plugixa-testimonial');
        }

        if (!in_array($data['field_type'], self::ALLOWED_TYPES)) {
            $errors['field_type'] = __('Invalid field type', 'plugixa-testimonial');
        }

        if ($data['field_type'] === 'select' && (empty($data['field_options']) || !is_array($data['field_options']))) {
            $errors['field_options'] = __('Select fields require at least one option', 'plugixa-testimonial');
        }

        return $errors;
    }

    private function generate_field_key(string $label): string {
        $key = sanitize_title($label);
        $key = str_replace('-', '_', $key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        $key = preg_replace('/_+/', '_', $key);
        $key = trim($key, '_');

        if (empty($key)) {
            $key = 'field_' . time();
        }

        return substr($key, 0, 100);
    }
}
