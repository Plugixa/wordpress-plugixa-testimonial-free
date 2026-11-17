<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Multilingual\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use PlugixaTestimonial\Core\Multilingual;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Multilingual REST Controller
 *
 * Handles translation management endpoints:
 * - List available languages
 * - Get/create/link/unlink translations for any entity
 */
class MultilingualController extends RestController {

    private Multilingual $multilingual;

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'multilingual';
        $this->multilingual = Multilingual::instance();
    }

    public function register_routes(): void {
        // GET /multilingual/languages
        register_rest_route($this->namespace, '/' . $this->rest_base . '/languages', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_languages'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        // GET /multilingual/status
        register_rest_route($this->namespace, '/' . $this->rest_base . '/status', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_status'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        // GET|POST /multilingual/{entity}/{id}/translations
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z-]+)/(?P<id>[\d]+)/translations', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_translations'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_translation'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'language_code' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        // PUT /multilingual/{entity}/link-translations
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z-]+)/link-translations', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'link_translations'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'source_id' => ['type' => 'integer', 'required' => true],
                    'target_id' => ['type' => 'integer', 'required' => true],
                ],
            ],
        ]);

        // DELETE /multilingual/{entity}/{id}/translations/{lang}
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z-]+)/(?P<id>[\d]+)/translations/(?P<lang>[a-z]{2,5})', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'unlink_translation'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        // GET /multilingual/{entity}/{id}/translation-group
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z-]+)/(?P<id>[\d]+)/translation-group', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_translation_group'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        // POST /multilingual/{entity}/batch-translations
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z-]+)/batch-translations', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'batch_save_translations'],
                'permission_callback' => [$this, 'check_batch_translations_permission'],
            ],
        ]);
    }

    /**
     * Edit-level normally, delete-level when the payload actually deletes.
     *
     * This one route mixes action classes. Its ordinary path is routine translation
     * editing, which every content editor needs, so gating the whole route at delete-level
     * would break normal saving for edit-only users on every request. But its
     * delete_languages branch removes whole entity rows plus their meta and category
     * pivots, which is a strictly stronger act than saving a translation.
     *
     * Escalating inside the permission_callback rather than inside the handler matters:
     * the refusal happens before the handler is entered at all, so a rejected request
     * never opens the transaction it would then have to roll back. It is also the shape a
     * reviewer (and the family's compliance check) looks for - the guard is declared on
     * the route, not buried in the body.
     */
    public function check_batch_translations_permission($request) {
        $edit = $this->check_permission($request);
        if (is_wp_error($edit)) {
            return $edit;
        }

        $delete_languages = $request->get_param('delete_languages');
        if (!empty($delete_languages)) {
            $delete = $this->check_delete_permission($request);
            if (is_wp_error($delete)) {
                return $delete;
            }
        }

        if ($request->get_param('entity') === 'testimonials') {
            return $this->check_batch_moderation($request);
        }

        return true;
    }

    /**
     * The moderation rule (RestController::check_moderation()) for a testimonial batch.
     *
     * `shared.status` is written to every row of the batch, and the handler targets
     * existing rows two ways: by each translation's `id`, and by translation_group +
     * language when no id is sent. Every row reachable either way is checked, so a
     * Contributor cannot publish through `shared.status`, nor overwrite an approved row
     * by naming its id or its group.
     */
    private function check_batch_moderation($request) {
        $shared = $request->get_param('shared');
        $requested = (is_array($shared) && array_key_exists('status', $shared))
            ? sanitize_key((string) $shared['status'])
            : null;

        $group = $request->get_param('translation_group');
        $current = (is_string($group) && $group !== '' && $this->group_has_approved_row($group)) ? 'approved' : null;

        $translations = $request->get_param('translations');
        if ($current === null && is_array($translations)) {
            foreach ($translations as $trans) {
                $id = is_array($trans) && isset($trans['id']) ? (int) $trans['id'] : 0;
                if ($id > 0) {
                    $status = $this->stored_testimonial_status($id);
                    if ($status === 'approved') {
                        $current = 'approved';
                        break;
                    }
                    $current = $current ?? $status;
                }
            }
        }

        return $this->check_moderation($requested, $current);
    }

    /**
     * Get available languages
     */
    public function get_languages(WP_REST_Request $request): WP_REST_Response {
        return $this->success([
            'languages' => $this->multilingual->get_available_languages(),
            'current'   => $this->multilingual->get_current_language(),
            'default'   => $this->multilingual->get_default_language(),
            'is_active' => $this->multilingual->is_multilingual_active(),
            'plugin'    => $this->multilingual->is_wpml_active() ? 'wpml' : ($this->multilingual->is_polylang_active() ? 'polylang' : 'none'),
        ]);
    }

    /**
     * Get multilingual status (lightweight check)
     */
    public function get_status(WP_REST_Request $request): WP_REST_Response {
        return $this->success([
            'is_active' => $this->multilingual->is_multilingual_active(),
            'plugin'    => $this->multilingual->is_wpml_active() ? 'wpml' : ($this->multilingual->is_polylang_active() ? 'polylang' : 'none'),
            'current'   => $this->multilingual->get_current_language(),
            'default'   => $this->multilingual->get_default_language(),
        ]);
    }

    /**
     * Get translations of a specific item
     */
    public function get_translations(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $entity = $request->get_param('entity');
        $id = (int) $request->get_param('id');

        $table = $this->resolve_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-testimonial'), 400);
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT id, language_code, translation_group FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$item) {
            return $this->error(__('Item not found.', 'plugixa-testimonial'), 404);
        }

        $translations = [];
        if (!empty($item['translation_group'])) {
            $translations = $this->multilingual->get_translations($table, $item['translation_group']);
        }

        return $this->success([
            'item'         => $item,
            'translations' => $translations,
            'languages'    => $this->multilingual->get_available_languages(),
        ]);
    }

    /**
     * Create a translation of an item
     */
    public function create_translation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $entity = $request->get_param('entity');
        $id = (int) $request->get_param('id');
        $target_lang = sanitize_text_field($request->get_param('language_code'));

        $table = $this->resolve_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-testimonial'), 400);
        }

        // Get source item
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $source = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$source) {
            return $this->error(__('Source item not found.', 'plugixa-testimonial'), 404);
        }

        // Ensure source has a translation_group
        if (empty($source['translation_group'])) {
            $source['translation_group'] = $this->multilingual->generate_translation_group();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->update($table, ['translation_group' => $source['translation_group']], ['id' => $id]);
        }

        // Check if translation already exists
        $existing = $this->multilingual->get_translation($table, $source['translation_group'], $target_lang);
        if ($existing) {
            return $this->error(
                /* translators: 1: language code, 2: existing translation ID */
                sprintf(__('A translation in "%1$s" already exists (ID: %2$d).', 'plugixa-testimonial'), $target_lang, $existing),
                409
            );
        }

        // Duplicate the item
        $new_data = $source;
        unset($new_data['id']);
        $new_data['language_code'] = $target_lang;
        $new_data['created_at'] = current_time('mysql');
        if (array_key_exists('created_by', $new_data)) {
            $new_data['created_by'] = get_current_user_id();
        }
        if (array_key_exists('modified_at', $new_data)) {
            $new_data['modified_at'] = null;
        }
        if (array_key_exists('modified_by', $new_data)) {
            $new_data['modified_by'] = null;
        }

        // A new translation copies the source's status. For a user who cannot approve,
        // copying an approved or rejected source would make that moderation decision
        // for them, so their copy starts pending (see RestController::check_moderation()).
        if ($entity === 'testimonials' && in_array($new_data['status'] ?? '', ['approved', 'rejected'], true) && !$this->can_approve()) {
            $new_data['status'] = 'pending';
            $new_data['approved_at'] = null;
            $new_data['approved_by'] = null;
        }

        // Make slug unique if the table has a slug column
        if (isset($new_data['slug']) && !empty($new_data['slug'])) {
            $new_data['slug'] = $this->make_unique_slug($table, $new_data['slug'] . '-' . $target_lang);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $new_data);

        if ($result === false) {
            return $this->error(__('Failed to create translation.', 'plugixa-testimonial'), 500);
        }

        $new_id = $wpdb->insert_id;

        // For testimonials: copy pivot table entries and meta
        if ($entity === 'testimonials') {
            $this->copy_testimonial_relations($id, $new_id);
        }

        return $this->success(
            ['id' => $new_id, 'language_code' => $target_lang, 'translation_group' => $source['translation_group']],
            __('Translation created successfully.', 'plugixa-testimonial')
        );
    }

    /**
     * Link two existing items as translations
     */
    public function link_translations(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $entity = $request->get_param('entity');
        $source_id = (int) $request->get_param('source_id');
        $target_id = (int) $request->get_param('target_id');

        $table = $this->resolve_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-testimonial'), 400);
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $source = $wpdb->get_row($wpdb->prepare("SELECT id, language_code FROM %i WHERE id = %d", $table, $source_id), ARRAY_A);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $target = $wpdb->get_row($wpdb->prepare("SELECT id, language_code FROM %i WHERE id = %d", $table, $target_id), ARRAY_A);

        if (!$source || !$target) {
            return $this->error(__('One or both items not found.', 'plugixa-testimonial'), 404);
        }

        if ($source['language_code'] === $target['language_code']) {
            return $this->error(__('Cannot link items with the same language.', 'plugixa-testimonial'), 400);
        }

        $group = $this->multilingual->link_translations($table, $source_id, $target_id);

        return $this->success(
            ['translation_group' => $group],
            __('Items linked as translations.', 'plugixa-testimonial')
        );
    }

    /**
     * Unlink a translation (remove from group)
     */
    public function unlink_translation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $entity = $request->get_param('entity');
        $id = (int) $request->get_param('id');

        $table = $this->resolve_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-testimonial'), 400);
        }

        $this->multilingual->unlink_translation($table, $id);

        return $this->success(null, __('Translation unlinked.', 'plugixa-testimonial'));
    }

    /**
     * Resolve entity name to full table name
     */
    private function resolve_table(string $entity): ?string {
        $map = $this->multilingual->get_entity_table_map();
        return $map[$entity] ?? null;
    }

    /**
     * Ensure a slug is unique in a table
     */
    private function make_unique_slug(string $table, string $slug, ?int $exclude_id = null): string {
        global $wpdb;

        $base = $slug;
        $counter = 1;

        if ($exclude_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            while ($wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE slug = %s AND id != %d", $table, $slug, $exclude_id))) {
                $counter++;
                $slug = $base . '-' . $counter;
            }
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            while ($wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE slug = %s", $table, $slug))) {
                $counter++;
                $slug = $base . '-' . $counter;
            }
        }

        return $slug;
    }

    /**
     * Copy pivot table entries and meta from source testimonial to new translation.
     */
    private function copy_testimonial_relations(int $source_id, int $new_id): void {
        global $wpdb;

        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $pivots = $wpdb->get_results(
            $wpdb->prepare("SELECT category_id, display_order FROM %i WHERE testimonial_id = %d", $pivot_table, $source_id),
            ARRAY_A
        );

        foreach ($pivots as $pivot) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->insert($pivot_table, [
                'testimonial_id' => $new_id,
                'category_id'    => (int) $pivot['category_id'],
                'display_order'  => (int) $pivot['display_order'],
            ]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $metas = $wpdb->get_results(
            $wpdb->prepare("SELECT meta_key, meta_value FROM %i WHERE testimonial_id = %d", $meta_table, $source_id),
            ARRAY_A
        );

        foreach ($metas as $meta) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->insert($meta_table, [
                'testimonial_id' => $new_id,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                'meta_key'       => $meta['meta_key'],
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value itself is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                'meta_value'     => $meta['meta_value'],
            ]);
        }
    }

    /**
     * Get full translation group data for an item.
     * Returns shared + translatable fields split by language.
     */
    public function get_translation_group(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $entity = $request->get_param('entity');
        $id = (int) $request->get_param('id');

        $table = $this->resolve_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-testimonial'), 400);
        }

        $field_map = $this->multilingual->get_field_map($entity);
        if (empty($field_map['translatable'])) {
            return $this->error(__('Entity does not support translations.', 'plugixa-testimonial'), 400);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$item) {
            return $this->error(__('Item not found.', 'plugixa-testimonial'), 404);
        }

        $translation_group = $item['translation_group'] ?? null;

        $all_rows = [];
        if (!empty($translation_group)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $all_rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM %i WHERE translation_group = %s", $table, $translation_group),
                ARRAY_A
            );
        }

        if (empty($all_rows)) {
            $all_rows = [$item];
        }

        // Find default language row for shared fields
        $default_lang = $this->multilingual->get_default_language();
        $default_row = null;
        foreach ($all_rows as $row) {
            if (($row['language_code'] ?? '') === $default_lang) {
                $default_row = $row;
                break;
            }
        }
        if (!$default_row) {
            $default_row = $item;
        }

        // Extract shared fields
        $shared = [];
        foreach ($field_map['shared'] as $field) {
            if (array_key_exists($field, $default_row)) {
                $shared[$field] = $default_row[$field];
            }
        }

        // Add photo data for testimonials
        if ($entity === 'testimonials' && !empty($shared['client_photo_id'])) {
            $shared['client_photo'] = $this->get_image_data((int) $shared['client_photo_id']);
        }

        // For testimonials, add category_ids from pivot table
        if ($entity === 'testimonials') {
            $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
            $default_id = (int) $default_row['id'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $cat_ids = $wpdb->get_col(
                $wpdb->prepare("SELECT category_id FROM %i WHERE testimonial_id = %d", $pivot_table, $default_id)
            );
            $shared['category_ids'] = array_map('intval', $cat_ids);
        }

        // Build translations map
        $translations = [];
        foreach ($all_rows as $row) {
            $lang = $row['language_code'] ?? $default_lang;
            $trans_data = ['id' => (int) $row['id']];

            foreach ($field_map['translatable'] as $field) {
                if (array_key_exists($field, $row)) {
                    $trans_data[$field] = $row[$field];
                }
            }

            /**
             * One language's data in a translation group response. An extension adds
             * the fields it owns (custom-field values).
             *
             * @param array  $trans_data Translatable fields plus id.
             * @param string $entity     REST entity name.
             * @param int    $id         Row id.
             */
            $translations[$lang] = apply_filters('plugixatestimonial_multilingual_translation', $trans_data, $entity, (int) $row['id']);
        }

        return $this->success([
            'translation_group' => $translation_group,
            'shared'            => $shared,
            'translations'      => $translations,
        ]);
    }

    /**
     * Batch save all translations atomically.
     */
    public function batch_save_translations(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $entity = $request->get_param('entity');
        $table = $this->resolve_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-testimonial'), 400);
        }

        $field_map = $this->multilingual->get_field_map($entity);
        if (empty($field_map['translatable'])) {
            return $this->error(__('Entity does not support translations.', 'plugixa-testimonial'), 400);
        }

        $translation_group = $request->get_param('translation_group');
        $shared_input = $request->get_param('shared') ?? [];
        $translations_input = $request->get_param('translations') ?? [];
        $delete_languages = $request->get_param('delete_languages') ?? [];
        $default_lang = $this->multilingual->get_default_language();

        // Default language must be present
        if (empty($translations_input[$default_lang])) {
            return $this->error(
                __('Default language translation is required.', 'plugixa-testimonial'),
                400,
                ['default_language' => $default_lang]
            );
        }

        // Validate default language
        $validation_errors = $this->validate_translatable_fields($entity, $translations_input[$default_lang], true);
        if (!empty($validation_errors)) {
            return $this->error(__('Validation failed for default language.', 'plugixa-testimonial'), 400, $validation_errors);
        }

        if (empty($translation_group)) {
            $translation_group = $this->multilingual->generate_translation_group();
        }

        $shared = $this->sanitize_shared_fields($entity, $shared_input);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. A constant literal transaction-control statement: it carries no identifiers and no values, so there is nothing to bind or prepare. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query('START TRANSACTION');

        try {
            $result_ids = [];
            $now = current_time('mysql');
            $user_id = get_current_user_id();

            foreach ($translations_input as $lang_code => $trans_data) {
                $lang_code = sanitize_text_field($lang_code);

                if ($lang_code !== $default_lang && $this->is_translation_empty($entity, $trans_data)) {
                    continue;
                }

                if ($lang_code !== $default_lang) {
                    $errors = $this->validate_translatable_fields($entity, $trans_data, false);
                    if (!empty($errors)) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. A constant literal transaction-control statement: it carries no identifiers and no values, so there is nothing to bind or prepare. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                        $wpdb->query('ROLLBACK');
                        return $this->error(
                            /* translators: %s: language code */
                            sprintf(__('Validation failed for language: %s', 'plugixa-testimonial'), $lang_code),
                            400,
                            $errors
                        );
                    }
                }

                $sanitized_trans = $this->sanitize_translatable_fields($entity, $trans_data);

                $row_data = array_merge($shared, $sanitized_trans, [
                    'language_code'     => $lang_code,
                    'translation_group' => $translation_group,
                ]);

                $existing_id = isset($trans_data['id']) ? (int) $trans_data['id'] : null;

                // Deduplication: if no ID provided, check if a row already exists for this group+lang
                if (!$existing_id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE translation_group = %s AND language_code = %s", $table,
                        $translation_group,
                        $lang_code
                    )) ?: null;
                }

                // Check which audit columns exist in this table
                $has_created_by = $this->table_has_column($table, 'created_by');
                $has_modified_by = $this->table_has_column($table, 'modified_by');
                $has_modified_at = $this->table_has_column($table, 'modified_at');

                if ($existing_id) {
                    if ($has_modified_at) $row_data['modified_at'] = $now;
                    if ($has_modified_by) $row_data['modified_by'] = $user_id;
                    if (!empty($row_data['slug'])) {
                        $row_data['slug'] = $this->make_unique_slug($table, $row_data['slug'], $existing_id);
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    $wpdb->update($table, $row_data, ['id' => $existing_id]);
                    $result_ids[$lang_code] = $existing_id;
                } else {
                    $row_data['created_at'] = $now;
                    if ($has_created_by) $row_data['created_by'] = $user_id;

                    if (!empty($row_data['slug'])) {
                        $row_data['slug'] = $this->make_unique_slug($table, $row_data['slug']);
                    }

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    $wpdb->insert($table, $row_data);
                    if ($wpdb->insert_id) {
                        $result_ids[$lang_code] = $wpdb->insert_id;
                    } else {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. A constant literal transaction-control statement: it carries no identifiers and no values, so there is nothing to bind or prepare. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                        $wpdb->query('ROLLBACK');
                        return $this->error(
                            /* translators: %s: language code */
                            sprintf(__('Failed to create translation for: %s', 'plugixa-testimonial'), $lang_code),
                            500
                        );
                    }
                }

                /**
                 * One language of a translation group was saved. An extension stores
                 * the fields it owns (custom-field values).
                 *
                 * @param string $entity     REST entity name.
                 * @param int    $id         Row id of this language.
                 * @param array  $trans_data The submitted data for this language.
                 */
                do_action('plugixatestimonial_multilingual_translation_saved', $entity, (int) $result_ids[$lang_code], $trans_data);
            }

            // Save categories for testimonials (shared across all translations)
            if ($entity === 'testimonials' && isset($shared_input['category_ids']) && is_array($shared_input['category_ids'])) {
                $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
                foreach ($result_ids as $tid) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    $wpdb->delete($pivot_table, ['testimonial_id' => $tid]);
                    foreach ($shared_input['category_ids'] as $order => $cat_id) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                        $wpdb->insert($pivot_table, [
                            'testimonial_id' => $tid,
                            'category_id'    => (int) $cat_id,
                            'display_order'  => $order,
                        ]);
                    }
                }
            }

            // Delete translations for removed languages
            foreach ($delete_languages as $del_lang) {
                $del_lang = sanitize_text_field($del_lang);
                if ($del_lang === $default_lang) {
                    continue;
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $del_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE translation_group = %s AND language_code = %s", $table,
                    $translation_group,
                    $del_lang
                ));

                if ($del_id) {
                    if ($entity === 'testimonials') {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                        $wpdb->delete($wpdb->prefix . 'plugixatestimonial_meta', ['testimonial_id' => (int) $del_id]);
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                        $wpdb->delete($wpdb->prefix . 'plugixatestimonial_testimonial_categories', ['testimonial_id' => (int) $del_id]);
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    $wpdb->delete($table, ['id' => (int) $del_id]);
                }
            }

            // Sync shared fields to any records not in our batch
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $all_group_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE translation_group = %s", $table,
                $translation_group
            ));
            $processed_ids = array_values($result_ids);
            foreach ($all_group_ids as $gid) {
                $gid = (int) $gid;
                if (!in_array($gid, $processed_ids)) {
                    $sync_data = $shared;
                    if ($this->table_has_column($table, 'modified_at')) $sync_data['modified_at'] = $now;
                    if ($this->table_has_column($table, 'modified_by')) $sync_data['modified_by'] = $user_id;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    $wpdb->update($table, $sync_data, ['id' => $gid]);
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. A constant literal transaction-control statement: it carries no identifiers and no values, so there is nothing to bind or prepare. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->query('COMMIT');

            $translations_result = [];
            foreach ($result_ids as $lang => $rid) {
                $translations_result[$lang] = ['id' => $rid];
            }

            return $this->success([
                'translation_group' => $translation_group,
                'translations'      => $translations_result,
            ], __('Translations saved successfully.', 'plugixa-testimonial'));

        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. A constant literal transaction-control statement: it carries no identifiers and no values, so there is nothing to bind or prepare. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->query('ROLLBACK');
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Validate translatable fields per entity
     */
    private function validate_translatable_fields(string $entity, array $data, bool $is_required): array {
        $errors = [];

        switch ($entity) {
            case 'testimonials':
                if ($is_required) {
                    if (empty($data['client_name'] ?? '')) {
                        $errors['client_name'] = __('Client name is required', 'plugixa-testimonial');
                    }
                    if (empty($data['testimonial_text'] ?? '')) {
                        $errors['testimonial_text'] = __('Testimonial text is required', 'plugixa-testimonial');
                    }
                }
                break;

            case 'categories':
                if ($is_required) {
                    if (empty($data['name'] ?? '')) {
                        $errors['name'] = __('Category name is required', 'plugixa-testimonial');
                    }
                }
                break;
        }

        /**
         * Validation errors for one translation. An extension validates its entities.
         *
         * @param array  $errors      Field => message.
         * @param string $entity      REST entity name.
         * @param array  $data        Submitted translatable fields.
         * @param bool   $is_required Whether this is the default language.
         */
        return apply_filters('plugixatestimonial_multilingual_validate', $errors, $entity, $data, $is_required);
    }

    /**
     * Check if a translation has no meaningful content
     */
    private function is_translation_empty(string $entity, array $data): bool {
        $field_map = $this->multilingual->get_field_map($entity);

        foreach ($field_map['translatable'] as $field) {
            $value = $data[$field] ?? '';
            if (!empty($value) && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize shared fields per entity
     */
    private function sanitize_shared_fields(string $entity, array $data): array {
        $sanitized = [];

        switch ($entity) {
            case 'testimonials':
                if (array_key_exists('rating', $data)) {
                    $sanitized['rating'] = max(1, min(5, (int) $data['rating']));
                }
                if (array_key_exists('client_photo_id', $data)) {
                    $sanitized['client_photo_id'] = $data['client_photo_id'] ? (int) $data['client_photo_id'] : null;
                }
                if (array_key_exists('website_url', $data)) {
                    $sanitized['website_url'] = esc_url_raw($data['website_url'] ?? '');
                }
                if (array_key_exists('status', $data)) {
                    $status = sanitize_key($data['status']);
                    $sanitized['status'] = in_array($status, ['pending', 'approved', 'rejected', 'draft']) ? $status : 'pending';
                }
                if (array_key_exists('is_featured', $data)) {
                    $sanitized['is_featured'] = $data['is_featured'] ? 1 : 0;
                }
                if (array_key_exists('display_order', $data)) {
                    $sanitized['display_order'] = (int) $data['display_order'];
                }
                break;

            case 'categories':
                if (array_key_exists('display_order', $data)) {
                    $sanitized['display_order'] = (int) $data['display_order'];
                }
                if (array_key_exists('status', $data)) {
                    $status = sanitize_key($data['status']);
                    $sanitized['status'] = in_array($status, ['publish', 'draft', 'trash']) ? $status : 'publish';
                }
                break;
        }

        /**
         * Sanitized shared fields. An extension sanitizes the fields it owns. It is
         * handed the raw $data and must sanitize every value it returns.
         *
         * @param array  $sanitized Sanitized fields so far.
         * @param string $entity    REST entity name.
         * @param array  $data      Submitted shared fields.
         */
        return apply_filters('plugixatestimonial_multilingual_sanitize_shared', $sanitized, $entity, $data);
    }

    /**
     * Sanitize translatable fields per entity
     */
    private function sanitize_translatable_fields(string $entity, array $data): array {
        $sanitized = [];

        switch ($entity) {
            case 'testimonials':
                $sanitized['client_name'] = sanitize_text_field($data['client_name'] ?? '');
                $sanitized['client_role'] = sanitize_text_field($data['client_role'] ?? '');
                $sanitized['client_company'] = sanitize_text_field($data['client_company'] ?? '');
                $sanitized['testimonial_text'] = wp_kses_post($data['testimonial_text'] ?? '');
                break;

            case 'categories':
                $sanitized['name'] = sanitize_text_field($data['name'] ?? '');
                $sanitized['description'] = sanitize_textarea_field($data['description'] ?? '');
                $slug = sanitize_title($data['slug'] ?? '');
                if (empty($slug) && !empty($sanitized['name'])) {
                    $slug = sanitize_title($sanitized['name']);
                }
                $sanitized['slug'] = $slug;
                break;
        }

        /**
         * Sanitized translatable fields. An extension sanitizes its entities. It is
         * handed the raw $data and must sanitize every value it returns.
         *
         * @param array  $sanitized Sanitized fields so far.
         * @param string $entity    REST entity name.
         * @param array  $data      Submitted translatable fields.
         */
        return apply_filters('plugixatestimonial_multilingual_sanitize_translatable', $sanitized, $entity, $data);
    }

    /**
     * Check if a table has a specific column
     */
    private function table_has_column(string $table, string $column): bool {
        global $wpdb;
        static $cache = [];
        $key = $table . '.' . $column;
        if (!isset($cache[$key])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $cache[$key] = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME, $table, $column
            ));
        }
        return $cache[$key];
    }

    /**
     * Get image data from WordPress attachment
     */
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
        ];
    }
}
