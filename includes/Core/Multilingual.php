<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core;

if (!defined('ABSPATH')) { exit; }

/**
 * Multilingual Helper
 *
 * Provides WPML/Polylang abstraction for language detection,
 * translation group linking, and SQL query filtering.
 *
 * Uses the "Translation Group" pattern: items sharing the same
 * translation_group UUID are translations of each other.
 */
class Multilingual {

    private static ?Multilingual $instance = null;

    /**
     * Tables that support multilingual columns
     */
    public const MULTILINGUAL_TABLES = [
        'plugixatestimonial_testimonials',
        'plugixatestimonial_categories',
        'plugixatestimonial_custom_fields',
    ];

    public static function instance(): Multilingual {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Check if a multilingual plugin (WPML or Polylang) is active
     */
    public function is_multilingual_active(): bool {
        $settings = get_option('plugixatestimonial_settings', []);
        $enabled = $settings['multilingual_enabled'] ?? 'auto';

        if ($enabled === 'off') {
            return false;
        }

        return $this->is_wpml_active() || $this->is_polylang_active();
    }

    /**
     * Check if WPML is active
     */
    public function is_wpml_active(): bool {
        return defined('ICL_SITEPRESS_VERSION') && class_exists('SitePress');
    }

    /**
     * Check if Polylang is active
     */
    public function is_polylang_active(): bool {
        return function_exists('pll_current_language');
    }

    /**
     * Get current language code
     */
    public function get_current_language(): string {
        if ($this->is_wpml_active() && defined('ICL_LANGUAGE_CODE')) {
            return ICL_LANGUAGE_CODE;
        }

        if ($this->is_polylang_active()) {
            $lang = pll_current_language('slug');
            if ($lang) {
                return $lang;
            }
        }

        return $this->get_default_language();
    }

    /**
     * Get default/primary language code
     */
    public function get_default_language(): string {
        if ($this->is_wpml_active()) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, consumed not created; the name is fixed by WPML.
            $default = apply_filters('wpml_default_language', null);
            if ($default) {
                return $default;
            }
        }

        if ($this->is_polylang_active() && function_exists('pll_default_language')) {
            $default = pll_default_language('slug');
            if ($default) {
                return $default;
            }
        }

        $locale = get_locale();
        return substr($locale, 0, 2);
    }

    /**
     * Get all available languages
     *
     * @return array Array of language info: [{code, name, native_name, flag_url, is_default}]
     */
    public function get_available_languages(): array {
        $languages = [];
        $default_lang = $this->get_default_language();

        if ($this->is_wpml_active()) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, consumed not created; the name is fixed by WPML.
            $wpml_languages = apply_filters('wpml_active_languages', null, [
                'skip_missing' => 0,
                'orderby'      => 'custom_order',
            ]);

            if (is_array($wpml_languages)) {
                foreach ($wpml_languages as $lang) {
                    $languages[] = [
                        'code'        => $lang['language_code'],
                        'name'        => $lang['translated_name'] ?? $lang['native_name'],
                        'native_name' => $lang['native_name'] ?? '',
                        'flag_url'    => $lang['country_flag_url'] ?? '',
                        'is_default'  => $lang['language_code'] === $default_lang,
                    ];
                }
            }

            return $languages;
        }

        if ($this->is_polylang_active() && function_exists('pll_languages_list')) {
            $pll_languages = pll_languages_list(['fields' => '']);

            foreach ($pll_languages as $lang) {
                $languages[] = [
                    'code'        => $lang->slug,
                    'name'        => $lang->name,
                    'native_name' => $lang->name,
                    'flag_url'    => $lang->flag_url ?? '',
                    'is_default'  => $lang->slug === $default_lang,
                ];
            }

            return $languages;
        }

        $locale = get_locale();
        $languages[] = [
            'code'        => substr($locale, 0, 2),
            'name'        => $locale,
            'native_name' => $locale,
            'flag_url'    => '',
            'is_default'  => true,
        ];

        return $languages;
    }

    /**
     * Values for the language-filter clause, in placeholder order, at fixed arity.
     *
     * This helper deliberately returns VALUES ONLY and never a SQL fragment: a
     * fragment spliced into a caller's query would stop that query being the
     * constant literal the wp.org review requires. Each caller instead writes the
     * clause out inline with its own table alias substituted for `<a>`:
     *
     *     ( <a>.language_code = %s
     *       OR ( %d = 1 AND <a>.language_code = %s AND <a>.translation_group NOT IN (
     *              SELECT translation_group FROM %i
     *              WHERE language_code = %s AND translation_group IS NOT NULL ) )
     *       OR <a>.language_code IS NULL )
     *
     * and spreads these five values into prepare() at the position where the
     * clause appears -- prepare() binds positionally, so a clause that sits after
     * a JOIN's %i must have that %i's value spread ahead of these.
     *
     * The first value is a gate. Gate 0 collapses the clause to "this language or
     * untranslated", which is the behaviour both when the requested language IS
     * the default and when the fallback setting is 'hide'. Gate 1 additionally
     * admits default-language rows that have no translation in the current
     * language. Gating rather than branching keeps one literal for both cases.
     *
     * Returned order matches the clause exactly, left to right:
     * [current, gate, default, table, current].
     *
     * @return array{0:string,1:int,2:string,3:string,4:string}
     */
    public function language_filter_values(string $table, string $current, string $default): array {
        $settings = get_option('plugixatestimonial_settings', []);
        $fallback = $settings['multilingual_fallback'] ?? 'default_language';

        $use_fallback = ($current !== $default && $fallback !== 'hide');

        return [$current, $use_fallback ? 1 : 0, $default, $table, $current];
    }

    /**
     * Get all translations for an item
     */
    public function get_translations(string $table, string $translation_group): array {
        global $wpdb;

        if (empty($translation_group)) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        return $wpdb->get_results(
            $wpdb->prepare("SELECT id, language_code FROM %i WHERE translation_group = %s ORDER BY language_code ASC", $table,
                $translation_group
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get a specific translation of an item
     */
    public function get_translation(string $table, string $translation_group, string $language_code): ?int {
        global $wpdb;

        if (empty($translation_group)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM %i WHERE translation_group = %s AND language_code = %s", $table,
                $translation_group,
                $language_code
            )
        );

        return $id ? (int) $id : null;
    }

    /**
     * Link two items as translations of each other
     */
    public function link_translations(string $table, int $source_id, int $target_id): string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $source_group = $wpdb->get_var(
            $wpdb->prepare("SELECT translation_group FROM %i WHERE id = %d", $table, $source_id)
        );

        if (empty($source_group)) {
            $source_group = $this->generate_translation_group();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->update($table, ['translation_group' => $source_group], ['id' => $source_id]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->update($table, ['translation_group' => $source_group], ['id' => $target_id]);

        return $source_group;
    }

    /**
     * Unlink a translation (remove from group, give it a new one)
     */
    public function unlink_translation(string $table, int $item_id): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->update($table, [
            'translation_group' => $this->generate_translation_group(),
        ], ['id' => $item_id]);
    }

    /**
     * Generate a unique translation group UUID
     */
    public function generate_translation_group(): string {
        return wp_generate_uuid4();
    }

    /**
     * Assign language and translation group to a new item
     */
    public function assign_language(string $table, int $item_id, string $language_code, ?string $translation_group = null): string {
        global $wpdb;

        if (empty($translation_group)) {
            $translation_group = $this->generate_translation_group();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->update($table, [
            'language_code'     => $language_code,
            'translation_group' => $translation_group,
        ], ['id' => $item_id]);

        return $translation_group;
    }

    /**
     * Get field map defining which fields are translatable vs shared per entity
     */
    public function get_field_map(string $entity): array {
        $maps = [
            'testimonials' => [
                'translatable' => ['client_name', 'client_role', 'client_company', 'testimonial_text'],
                'shared'       => ['rating', 'client_photo_id', 'video_url', 'website_url', 'status', 'is_featured', 'display_order', 'source', 'external_id'],
            ],
            'categories' => [
                'translatable' => ['name', 'description', 'slug'],
                'shared'       => ['display_order', 'status'],
            ],
            'custom-fields' => [
                'translatable' => ['field_label', 'placeholder'],
                'shared'       => ['field_key', 'field_type', 'field_options', 'is_required', 'show_in_frontend', 'menu_order'],
            ],
        ];

        return $maps[$entity] ?? ['translatable' => [], 'shared' => []];
    }

    /**
     * Get the list of tables that support multilingual columns
     */
    public function get_multilingual_tables(): array {
        global $wpdb;
        return array_map(fn($t) => $wpdb->prefix . $t, self::MULTILINGUAL_TABLES);
    }

    /**
     * Get table name map for entity identifiers used in the REST API
     */
    public function get_entity_table_map(): array {
        global $wpdb;
        return [
            'testimonials'  => $wpdb->prefix . 'plugixatestimonial_testimonials',
            'categories'    => $wpdb->prefix . 'plugixatestimonial_categories',
            'custom-fields' => $wpdb->prefix . 'plugixatestimonial_custom_fields',
        ];
    }

    /**
     * Append translation data to an item's response
     */
    public function append_translations(array $item, string $table): array {
        if (!$this->is_multilingual_active()) {
            return $item;
        }

        $group = $item['translation_group'] ?? null;
        $item['translations'] = $group ? $this->get_translations($table, $group) : [];

        return $item;
    }

    /**
     * Sync non-translatable fields across a translation group
     */
    public function sync_translation_group(string $table, int $item_id, array $fields): void {
        global $wpdb;

        if (empty($fields)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $group = $wpdb->get_var(
            $wpdb->prepare("SELECT translation_group FROM %i WHERE id = %d", $table, $item_id)
        );

        if (empty($group)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->update($table, $fields, ['translation_group' => $group]);
    }
}
