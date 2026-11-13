<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Views\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Views REST Controller - CRUD + duplicate + set-default + preview + default-config
 */
class ViewsController extends RestController {

    private array $default_config = [
        'layout' => [
            'style' => 'grid',
            'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1],
        ],
        'display' => [
            'showPhoto' => true,
            'showRating' => true,
            'showDate' => true,
            'showCompany' => true,
            'showRole' => true,
            'showWebsite' => false,
            'excerptLength' => 150,
        ],
        'colors' => [
            'cardBg' => '#ffffff',
            'textColor' => '#1d2327',
            'nameColor' => '#1d2327',
            'roleColor' => '#646970',
            'starColor' => '#f59e0b',
            'starEmptyColor' => '#d1d5db',
            'borderColor' => '#e5e7eb',
            'quoteBg' => '#f9fafb',
        ],
        'typography' => [
            'nameFontSize' => 16,
            'nameFontWeight' => 600,
            'textFontSize' => 14,
            'textFontWeight' => 400,
            'roleFontSize' => 13,
        ],
        'spacing' => [
            'cardPadding' => 24,
            'gap' => 24,
            'photoPadding' => 0,
        ],
        'borders' => [
            'radius' => 8,
            'width' => 1,
        ],
        'shadows' => [
            'enabled' => true,
            'value' => '0 1px 3px rgba(0,0,0,0.1)',
        ],
        'categories' => [
            'displayStyle' => 'none',
            'showCategoryDescription' => true,
            'colors' => [
                'backgroundColor' => '#ffffff',
                'textColor' => '#1d2327',
                'activeBackgroundColor' => '#2271b1',
                'activeTextColor' => '#ffffff',
                'borderColor' => '#c3c4c7',
                'descriptionColor' => '#6b7280',
                'descriptionBackgroundColor' => '#f8fafc',
                'descriptionBorderColor' => '#2271b1',
            ],
            'typography' => [
                'fontSize' => 14,
                'fontWeight' => 500,
                'fontFamily' => 'inherit',
            ],
            'spacing' => [
                'padding' => 12,
                'gap' => 8,
            ],
            'border' => [
                'width' => 1,
                'radius' => 4,
            ],
        ],
        'photo' => [
            'size' => 60,
            'sizeUnit' => 'px',
            'borderRadius' => 50,
            'fallbackMode' => 'initials',
            'fallbackColorMode' => 'single',
            'fallbackBgColor' => '#e5e7eb',
            'fallbackTextColor' => '#6b7280',
            'borderWidth' => 0,
            'borderColor' => '#e5e7eb',
            'borderStyle' => 'none',
            'position' => 'inline',
            'grayscale' => false,
            'hoverScale' => 1.0,
            'opacity' => 1.0,
            'shadowEnabled' => false,
            'shadowValue' => '0 2px 8px rgba(0,0,0,0.15)',
        ],
    ];

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'views';
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/set-default', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'set_default'],
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/default-config', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_default_config'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/preview', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_preview'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);
    }

    public function get_items($request) {
        global $wpdb;

        $pagination = $this->get_pagination($request);
        $filters = $this->get_filters($request);
        $sort = $this->get_sort($request);

        $table = $wpdb->prefix . 'plugixatestimonial_views';

        // Bound gates inside one constant-literal WHERE; gate 0 disables its test.
        $search_gate = !empty($filters['search']) ? 1 : 0;
        $search      = $search_gate ? '%' . $wpdb->esc_like($filters['search']) . '%' : '%';

        $default_gate = (isset($filters['is_default']) && $filters['is_default'] !== '') ? 1 : 0;
        $default_val  = ($default_gate && $filters['is_default'] === 'yes') ? 1 : 0;

        // Layout styles are slugs ('grid', 'slider', ...), so a comma-joined list is
        // unambiguous and FIND_IN_SET keeps the placeholder count fixed at one.
        $style_gate = !empty($filters['layout_style']) ? 1 : 0;
        $style_csv  = '';
        if ($style_gate) {
            $style = $filters['layout_style'];
            $style_csv = is_array($style)
                ? implode(',', array_map('sanitize_text_field', $style))
                : sanitize_text_field($style);
        }

        $where_values = [
            $search_gate, $search, $search, $search,
            $default_gate, $default_val,
            $style_gate, $style_csv,
        ];

        $allowed_orderby = ['id', 'name', 'slug', 'is_default', 'created_at', 'modified_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE 1=1
               AND ( %d = 0 OR ( name LIKE %s OR slug LIKE %s OR description LIKE %s ) )
               AND ( %d = 0 OR is_default = %d )
               AND ( %d = 0 OR FIND_IN_SET(JSON_UNQUOTE(JSON_EXTRACT(config, '$.layout.style')), %s) )",
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
                   AND ( %d = 0 OR is_default = %d )
                   AND ( %d = 0 OR FIND_IN_SET(JSON_UNQUOTE(JSON_EXTRACT(config, '$.layout.style')), %s) )
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
                   AND ( %d = 0 OR is_default = %d )
                   AND ( %d = 0 OR FIND_IN_SET(JSON_UNQUOTE(JSON_EXTRACT(config, '$.layout.style')), %s) )
                 ORDER BY %i DESC
                 LIMIT %d OFFSET %d",
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A);
        }

        if ($items) {
            foreach ($items as &$item) {
                $item['config'] = json_decode($item['config'], true) ?: $this->default_config;
                $item['is_default'] = (int) $item['is_default'];
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
        $table = $wpdb->prefix . 'plugixatestimonial_views';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$item) {
            return $this->error(__('View not found', 'plugixa-testimonial'), 404);
        }

        $item['config'] = json_decode($item['config'], true) ?: $this->default_config;
        $item['is_default'] = (int) $item['is_default'];

        return $this->success($item);
    }

    public function create_item($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_views';

        // No quota. Saved display configurations are core functionality, so the number
        // a site may create is not capped in any build.
        $data = $this->sanitize_view_data($request);
        $errors = $this->validate_view_data($data);

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        if (empty($data['slug'])) {
            $data['slug'] = $this->generate_unique_slug($data['name']);
        } else {
            $data['slug'] = $this->ensure_unique_slug($data['slug']);
        }

        $config = $request->get_param('config');
        $merged_config = is_array($config)
            ? array_replace_recursive($this->default_config, $config)
            : $this->default_config;
        $merged_config = \PlugixaTestimonial\Core\ViewStyle::normalize($merged_config);
        $data['config'] = wp_json_encode($merged_config);

        $data['created_at'] = current_time('mysql');
        $data['created_by'] = get_current_user_id();

        if (!empty($data['is_default'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->update($table, ['is_default' => 0], ['is_default' => 1]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error(__('Failed to create view', 'plugixa-testimonial'), 500);
        }

        // Self-host any Google fonts this view uses (admin-initiated download).
        \PlugixaTestimonial\Modules\GoogleFonts\FontManager::schedule_config($merged_config);

        return $this->success(
            ['id' => $wpdb->insert_id],
            __('View created successfully', 'plugixa-testimonial')
        );
    }

    public function update_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $data = $this->sanitize_view_data($request);
        $errors = $this->validate_view_data($data, $id);

        if (!empty($errors)) {
            return $this->error(__('Validation failed', 'plugixa-testimonial'), 400, $errors);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_views';

        if (!empty($data['slug'])) {
            $data['slug'] = $this->ensure_unique_slug($data['slug'], $id);
        }

        $merged_config = null;
        if ($request->has_param('config')) {
            $config = $request->get_param('config');
            $merged_config = is_array($config)
                ? array_replace_recursive($this->default_config, $config)
                : $this->default_config;
            $merged_config = \PlugixaTestimonial\Core\ViewStyle::normalize($merged_config);
            $data['config'] = wp_json_encode($merged_config);
        }

        $data['modified_at'] = current_time('mysql');
        $data['modified_by'] = get_current_user_id();

        if (!empty($data['is_default'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->update($table, ['is_default' => 0], ['is_default' => 1]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->update($table, $data, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to update view', 'plugixa-testimonial'), 500);
        }

        // Self-host any Google fonts this view uses (admin-initiated download).
        if ($merged_config !== null) {
            \PlugixaTestimonial\Modules\GoogleFonts\FontManager::schedule_config($merged_config);
        }

        return $this->success(
            ['id' => $id],
            __('View updated successfully', 'plugixa-testimonial')
        );
    }

    public function delete_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_views';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $is_default = $wpdb->get_var(
            $wpdb->prepare("SELECT is_default FROM %i WHERE id = %d", $table, $id)
        );

        if ($is_default) {
            return $this->error(__('Default view cannot be deleted.', 'plugixa-testimonial'), 403);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->delete($table, ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to delete view', 'plugixa-testimonial'), 500);
        }

        return $this->success(null, __('View deleted successfully', 'plugixa-testimonial'));
    }

    public function delete_items($request) {
        global $wpdb;

        $ids = $request->get_param('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->error(__('No IDs provided', 'plugixa-testimonial'), 400);
        }

        $table = $wpdb->prefix . 'plugixatestimonial_views';
        $ids = array_map('intval', $ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $default_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE is_default = 1", $table));

        $ids_to_delete = array_filter($ids, function($id) use ($default_id) {
            return (int) $id !== (int) $default_id;
        });

        if (empty($ids_to_delete)) {
            return $this->error(__('Cannot delete the default view.', 'plugixa-testimonial'), 403);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE FIND_IN_SET(id, %s)",
                $table,
                implode(',', array_map('intval', array_values($ids_to_delete)))
            )
        );

        return $this->success(
            ['deleted' => $result],
            /* translators: %d: number of views deleted */
            sprintf(__('%d views deleted successfully', 'plugixa-testimonial'), $result)
        );
    }

    public function duplicate_item($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_views';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $original = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$original) {
            return $this->error(__('View not found', 'plugixa-testimonial'), 404);
        }

        $new_name = $original['name'] . ' (Copy)';
        $new_slug = $this->generate_unique_slug($new_name);

        $data = [
            'name'        => $new_name,
            'slug'        => $new_slug,
            'description' => $original['description'],
            'config'      => $original['config'],
            'is_default'  => 0,
            'created_at'  => current_time('mysql'),
            'created_by'  => get_current_user_id(),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error(__('Failed to duplicate view', 'plugixa-testimonial'), 500);
        }

        return $this->success(
            ['id' => $wpdb->insert_id],
            __('View duplicated successfully', 'plugixa-testimonial')
        );
    }

    public function set_default($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'plugixatestimonial_views';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->update($table, ['is_default' => 0], ['is_default' => 1]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->update($table, ['is_default' => 1], ['id' => $id]);

        if ($result === false) {
            return $this->error(__('Failed to set default view', 'plugixa-testimonial'), 500);
        }

        return $this->success(
            ['id' => $id],
            __('Default view updated successfully', 'plugixa-testimonial')
        );
    }

    public function get_default_config($request) {
        return $this->success(['config' => $this->default_config]);
    }

    public function get_preview($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE status = 'approved' ORDER BY display_order ASC, created_at DESC LIMIT 6",
            $table
        ), ARRAY_A);

        return $this->success(['items' => $items ?: []]);
    }

    private function sanitize_view_data($request): array {
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
        if ($request->has_param('is_default')) {
            $data['is_default'] = $request->get_param('is_default') ? 1 : 0;
        }

        return $data;
    }

    private function validate_view_data(array $data, ?int $id = null): array {
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
        $table = $wpdb->prefix . 'plugixatestimonial_views';
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
            'is_default' => ['type' => 'boolean', 'default' => false],
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
