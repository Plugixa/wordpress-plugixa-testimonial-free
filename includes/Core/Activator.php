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
 * Plugin Activator - Creates database tables, capabilities, default options, seed views
 */
class Activator {

    public static function activate(): void {
        self::create_tables();
        self::maybe_migrate_pivot_display_order();
        self::maybe_migrate_multilingual();
        self::maybe_upgrade_multilingual_v2();
        self::maybe_migrate_show_website();
        self::maybe_purge_custom_css();
        self::maybe_backfill_null_language_codes();
        self::maybe_add_status_columns();
        self::maybe_migrate_external_id();
        self::add_capabilities();
        self::set_default_options();
        self::seed_default_views();

        // Schema and seed data just changed what every cached read would return. With a
        // persistent object cache the old entries would otherwise outlive activation.
        Cache::flush_all();
    }

    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        // Testimonials table
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. CREATE TABLE is the one statement that can bind neither the table name nor get_charset_collate() as a placeholder, so the name is interpolated from $wpdb->prefix plus a hard-coded literal - no user input reaches this string. This is the plugin's only sanctioned %i exemption. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}plugixatestimonial_testimonials (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            language_code varchar(10) DEFAULT NULL,
            translation_group varchar(36) DEFAULT NULL,
            client_name varchar(255) NOT NULL,
            client_role varchar(255) DEFAULT NULL,
            client_company varchar(255) DEFAULT NULL,
            testimonial_text text NOT NULL,
            rating tinyint unsigned DEFAULT 5,
            client_photo_id bigint(20) unsigned DEFAULT NULL,
            video_url varchar(255) DEFAULT NULL,
            website_url varchar(255) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            display_order int(11) NOT NULL DEFAULT 0,
            source varchar(50) NOT NULL DEFAULT 'manual',
            external_id varchar(191) DEFAULT NULL,
            view_count bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            modified_at datetime DEFAULT NULL,
            modified_by bigint(20) unsigned DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            approved_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY rating (rating),
            KEY is_featured (is_featured),
            KEY display_order (display_order),
            KEY created_by (created_by),
            KEY language_code (language_code),
            KEY translation_group (translation_group),
            KEY source_external (source, external_id)
        ) {$charset_collate};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. This line only builds a table-name string from $wpdb->prefix and a hard-coded literal; no query is executed here and no user input is involved. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.

        // Categories table
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. CREATE TABLE is the one statement that can bind neither the table name nor get_charset_collate() as a placeholder, so the name is interpolated from $wpdb->prefix plus a hard-coded literal - no user input reaches this string. This is the plugin's only sanctioned %i exemption. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}plugixatestimonial_categories (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            language_code varchar(10) DEFAULT NULL,
            translation_group varchar(36) DEFAULT NULL,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            display_order int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY display_order (display_order),
            KEY language_code (language_code),
            KEY translation_group (translation_group)
        ) {$charset_collate};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. This line only builds a table-name string from $wpdb->prefix and a hard-coded literal; no query is executed here and no user input is involved. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.

        // Pivot table: testimonial_categories (many-to-many with per-category display_order)
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. CREATE TABLE is the one statement that can bind neither the table name nor get_charset_collate() as a placeholder, so the name is interpolated from $wpdb->prefix plus a hard-coded literal - no user input reaches this string. This is the plugin's only sanctioned %i exemption. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}plugixatestimonial_testimonial_categories (
            testimonial_id bigint(20) unsigned NOT NULL,
            category_id bigint(20) unsigned NOT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (testimonial_id, category_id),
            KEY category_id (category_id),
            KEY cat_display_order (category_id, display_order)
        ) {$charset_collate};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. This line only builds a table-name string from $wpdb->prefix and a hard-coded literal; no query is executed here and no user input is involved. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.

        // Views table
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. CREATE TABLE is the one statement that can bind neither the table name nor get_charset_collate() as a placeholder, so the name is interpolated from $wpdb->prefix plus a hard-coded literal - no user input reaches this string. This is the plugin's only sanctioned %i exemption. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}plugixatestimonial_views (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            config longtext NOT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            modified_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY is_default (is_default)
        ) {$charset_collate};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. This line only builds a table-name string from $wpdb->prefix and a hard-coded literal; no query is executed here and no user input is involved. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.

        // Shortcodes table
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. CREATE TABLE is the one statement that can bind neither the table name nor get_charset_collate() as a placeholder, so the name is interpolated from $wpdb->prefix plus a hard-coded literal - no user input reaches this string. This is the plugin's only sanctioned %i exemption. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}plugixatestimonial_shortcodes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            config longtext NOT NULL,
            created_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            modified_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {$charset_collate};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. This line only builds a table-name string from $wpdb->prefix and a hard-coded literal; no query is executed here and no user input is involved. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.

        // Custom fields definitions table
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. CREATE TABLE is the one statement that can bind neither the table name nor get_charset_collate() as a placeholder, so the name is interpolated from $wpdb->prefix plus a hard-coded literal - no user input reaches this string. This is the plugin's only sanctioned %i exemption. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}plugixatestimonial_custom_fields (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            language_code varchar(10) DEFAULT NULL,
            translation_group varchar(36) DEFAULT NULL,
            field_key varchar(100) NOT NULL,
            field_label varchar(200) NOT NULL,
            field_type varchar(50) NOT NULL DEFAULT 'text',
            field_options longtext DEFAULT NULL,
            placeholder varchar(200) DEFAULT NULL,
            is_required tinyint(1) NOT NULL DEFAULT 0,
            show_in_frontend tinyint(1) NOT NULL DEFAULT 1,
            menu_order int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY field_key_lang (field_key, language_code),
            KEY menu_order (menu_order)
        ) {$charset_collate};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. This line only builds a table-name string from $wpdb->prefix and a hard-coded literal; no query is executed here and no user input is involved. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.

        // Requests table (email testimonial requests)
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. CREATE TABLE is the one statement that can bind neither the table name nor get_charset_collate() as a placeholder, so the name is interpolated from $wpdb->prefix plus a hard-coded literal - no user input reaches this string. This is the plugin's only sanctioned %i exemption. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}plugixatestimonial_requests (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            recipient_email varchar(255) NOT NULL,
            recipient_name varchar(255) DEFAULT NULL,
            subject varchar(500) DEFAULT NULL,
            message longtext DEFAULT NULL,
            token varchar(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            testimonial_id bigint(20) unsigned DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            submitted_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY status (status),
            KEY recipient_email (recipient_email),
            KEY testimonial_id (testimonial_id)
        ) {$charset_collate};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. This line only builds a table-name string from $wpdb->prefix and a hard-coded literal; no query is executed here and no user input is involved. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.

        // Testimonial meta table (key-value storage for custom field values)
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; WordPress core has no API for it. CREATE TABLE is the one statement that can bind neither the table name nor get_charset_collate() as a placeholder, so the name is interpolated from $wpdb->prefix plus a hard-coded literal - no user input reaches this string. This is the plugin's only sanctioned %i exemption. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}plugixatestimonial_meta (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            testimonial_id bigint(20) unsigned NOT NULL,
            meta_key varchar(100) NOT NULL,
            meta_value longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY testimonial_id (testimonial_id),
            KEY meta_key (meta_key),
            KEY testimonial_meta (testimonial_id, meta_key)
        ) {$charset_collate};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- This statement can bind neither its table name nor get_charset_collate() as a placeholder, so both are interpolated from $wpdb->prefix plus hard-coded literals - no user input reaches this string. This is the plugin's only sanctioned %i exemption.
    }

    public static function maybe_create_tables(): void {
        global $wpdb;

        $testimonials_table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $views_table = $wpdb->prefix . 'plugixatestimonial_views';
        $custom_fields_table = $wpdb->prefix . 'plugixatestimonial_custom_fields';

        $requests_table = $wpdb->prefix . 'plugixatestimonial_requests';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal; SHOW TABLES LIKE compares a string, so the table name is bound with %s after esc_like(). This runs during activation/migration, before any cache exists to read.
        $t_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($testimonials_table)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal; SHOW TABLES LIKE compares a string, so the table name is bound with %s after esc_like(). This runs during activation/migration, before any cache exists to read.
        $v_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($views_table)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal; SHOW TABLES LIKE compares a string, so the table name is bound with %s after esc_like(). This runs during activation/migration, before any cache exists to read.
        $cf_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($custom_fields_table)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal; SHOW TABLES LIKE compares a string, so the table name is bound with %s after esc_like(). This runs during activation/migration, before any cache exists to read.
        $r_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($requests_table)));

        if (!$t_exists || !$v_exists || !$cf_exists || !$r_exists) {
            self::create_tables();
        }

        // Ensure pivot table has display_order column (added for per-category ordering)
        self::maybe_migrate_pivot_display_order();

        // Ensure multilingual columns exist
        self::maybe_migrate_multilingual();

        // Upgrade multilingual v2: lang→language_code, translation_group→UUID
        self::maybe_upgrade_multilingual_v2();

        // Backfill any rows with NULL language_code (e.g. created before Polylang was active)
        self::maybe_backfill_null_language_codes();

        // Add video_url column
        self::maybe_migrate_video_url();

        // Add the external_id column review imports deduplicate on
        self::maybe_migrate_external_id();

        // Make the newly-rendered website link opt-in on pre-existing displays
        self::maybe_migrate_show_website();

        // Drop the removed free-text custom CSS setting
        self::maybe_purge_custom_css();
    }

    /**
     * Add display_order column to pivot table for existing installs.
     */
    private static function maybe_migrate_pivot_display_order(): void {
        global $wpdb;

        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
        $column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $pivot_table, $wpdb->esc_like('display_order')));
        if (!empty($column)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN display_order int(11) NOT NULL DEFAULT 0 AFTER category_id", $pivot_table));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD KEY cat_display_order (category_id, display_order)", $pivot_table));
    }

    /**
     * Add lang + translation_group columns to testimonials, categories, and custom_fields tables.
     * Backfills existing rows with default language and translation_group = id.
     */
    private static function maybe_migrate_multilingual(): void {
        global $wpdb;

        if (get_option('plugixatestimonial_ml_migrated')) {
            return;
        }

        $tables = [
            $wpdb->prefix . 'plugixatestimonial_testimonials',
            $wpdb->prefix . 'plugixatestimonial_categories',
            $wpdb->prefix . 'plugixatestimonial_custom_fields',
        ];

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, $wpdb->esc_like('lang')));
            if (!empty($column)) {
                continue;
            }

            // Fresh installs already have the v2 schema (language_code + UUID
            // translation_group) — adding the legacy v1 columns would partially
            // fail and leave a junk `lang` column behind.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $v2_column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, $wpdb->esc_like('language_code')));
            if (!empty($v2_column)) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN lang varchar(10) NOT NULL DEFAULT '' AFTER id", $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN translation_group bigint(20) unsigned NOT NULL DEFAULT 0 AFTER lang", $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD KEY lang (lang)", $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD KEY translation_group (translation_group)", $table));
        }

        // Detect default language
        $default_lang = '';
        if (has_filter('wpml_default_language')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, consumed not created; the name is fixed by WPML.
            $default_lang = apply_filters('wpml_default_language', '');
        } elseif (function_exists('pll_default_language')) {
            $default_lang = pll_default_language();
        }
        if (empty($default_lang)) {
            $default_lang = substr(get_locale(), 0, 2);
        }

        // Backfill existing rows. Only a legacy (v1) table has a `lang` column: the
        // loop above deliberately leaves a fresh install on the v2 schema
        // (language_code + UUID translation_group) untouched, so there is nothing to
        // backfill there. Without this guard the UPDATE runs anyway and every
        // statement fails with "Unknown column 'lang' in 'where clause'", logging a
        // database error on each fresh activation.
        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $columns = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table), 0);
            if (!in_array('lang', $columns, true)) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->query($wpdb->prepare("UPDATE %i SET lang = %s, translation_group = id WHERE lang = ''", $table,
                $default_lang
            ));
        }

        update_option('plugixatestimonial_ml_migrated', 1);
    }

    /**
     * One-time: turn the frontend website link OFF on displays that predate it.
     *
     * `showWebsite` shipped as a default-true flag that no template ever rendered, so
     * every saved config carries `true` by accident rather than by choice. Now that the
     * templates honour it, leaving those values alone would publish visitor-submitted
     * URLs as live outbound links on update, on sites that never asked for one. Flip the
     * stored value to false so the toggle is genuinely opt-in - anyone who wants the link
     * switches it on, and from then on that choice is respected.
     */
    private static function maybe_migrate_show_website(): void {
        if (get_option('plugixatestimonial_show_website_migrated')) {
            return;
        }

        global $wpdb;

        foreach (['shortcodes', 'views'] as $entity) {
            $table = $wpdb->prefix . 'plugixatestimonial_' . $entity;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i. This runs once behind a migration flag during activation/upgrade, and Activator::activate() calls Cache::flush_all() straight after, so caching a read that is rewritten in the same request would be pointless.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, config FROM %i", $table), ARRAY_A);
            if (!$rows) {
                continue;
            }

            foreach ($rows as $row) {
                $config = json_decode((string) $row['config'], true);
                if (!is_array($config) || !isset($config['display']) || !is_array($config['display'])) {
                    continue;
                }
                if (!array_key_exists('showWebsite', $config['display'])) {
                    continue;
                }
                if ($config['display']['showWebsite'] === false) {
                    continue;
                }

                $config['display']['showWebsite'] = false;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. $wpdb->update() is core's own escaping API for the table name and values. One-time migration write behind a flag; Activator::activate() flushes the object cache immediately afterwards.
                $wpdb->update($table, ['config' => wp_json_encode($config)], ['id' => (int) $row['id']]);
            }
        }

        update_option('plugixatestimonial_show_website_migrated', 1);
    }

    /**
     * Drop the removed `custom_css` setting from existing installs.
     *
     * The free-text CSS box was removed in 1.0.2. WordPress.org does not permit a plugin
     * to accept arbitrary user-authored CSS and re-emit it on the frontend, and core
     * already ships Appearance > Customize > Additional CSS for exactly that. Every read
     * and write of the key is gone, so leaving the stored value behind would be a payload
     * that no code path consumes - the same dead-data shape as the `source` column that
     * nothing read. Purge it rather than orphan it.
     */
    private static function maybe_purge_custom_css(): void {
        if (get_option('plugixatestimonial_custom_css_purged')) {
            return;
        }

        $settings = get_option('plugixatestimonial_settings');
        if (is_array($settings) && array_key_exists('custom_css', $settings)) {
            unset($settings['custom_css']);
            update_option('plugixatestimonial_settings', $settings);
        }

        update_option('plugixatestimonial_custom_css_purged', 1);
    }

    /**
     * Upgrade multilingual columns from v1 (lang/BIGINT) to v2 (language_code/UUID).
     * Renames lang→language_code, converts translation_group from BIGINT to VARCHAR(36) UUID.
     */
    private static function maybe_upgrade_multilingual_v2(): void {
        global $wpdb;

        if (get_option('plugixatestimonial_ml_v2_migrated')) {
            return;
        }

        $tables = [
            $wpdb->prefix . 'plugixatestimonial_testimonials',
            $wpdb->prefix . 'plugixatestimonial_categories',
            $wpdb->prefix . 'plugixatestimonial_custom_fields',
        ];

        foreach ($tables as $table) {
            // Check if old 'lang' column exists
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $has_lang = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, $wpdb->esc_like('lang')));
            if (empty($has_lang)) {
                // Already migrated or fresh install with language_code
                continue;
            }

            // If language_code already exists alongside lang, the schema is
            // already v2 (fresh install) — running the rename/convert steps
            // would fail and drop live v2 data. Skip the table.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $has_v2 = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, $wpdb->esc_like('language_code')));
            if (!empty($has_v2)) {
                continue;
            }

            // Step 1: Rename lang → language_code
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i CHANGE `lang` `language_code` VARCHAR(10) DEFAULT NULL", $table));

            // Step 2: Add temp column for new UUID translation groups
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN `translation_group_new` VARCHAR(36) DEFAULT NULL", $table));

            // Step 3: For each distinct old translation_group value, generate UUID
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $old_groups = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT translation_group FROM %i WHERE translation_group IS NOT NULL AND translation_group != 0", $table));
            foreach ($old_groups as $old_group) {
                $uuid = wp_generate_uuid4();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->query($wpdb->prepare("UPDATE %i SET translation_group_new = %s WHERE translation_group = %s", $table,
                    $uuid,
                    $old_group
                ));
            }

            // Step 4: Drop old column, rename new
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i DROP COLUMN `translation_group`", $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i CHANGE `translation_group_new` `translation_group` VARCHAR(36) DEFAULT NULL", $table));

            // Step 5: Convert empty strings to NULL
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("UPDATE %i SET language_code = NULL WHERE language_code = ''", $table));

            // Step 6: Drop old indexes and add new ones
            // Suppress errors for dropping non-existent keys
            $wpdb->suppress_errors(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i DROP KEY `lang`", $table));
            $wpdb->suppress_errors(false);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD KEY `language_code` (`language_code`)", $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD KEY `translation_group` (`translation_group`)", $table));

            // Add unique constraint (ignore if it fails due to data issues)
            $wpdb->suppress_errors(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD UNIQUE KEY `lang_group` (`translation_group`, `language_code`)", $table));
            $wpdb->suppress_errors(false);
        }

        // For custom_fields: replace old field_key unique with composite
        $cf_table = $wpdb->prefix . 'plugixatestimonial_custom_fields';
        $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
        $wpdb->query($wpdb->prepare("ALTER TABLE %i DROP KEY `field_key`", $cf_table));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD UNIQUE KEY `field_key_lang` (`field_key`, `language_code`)", $cf_table));
        $wpdb->suppress_errors(false);

        update_option('plugixatestimonial_ml_v2_migrated', 1);
    }

    /**
     * Backfill rows with NULL language_code using the default language.
     * Runs every time (no flag) since it's a no-op when all rows already have a language.
     */
    private static function maybe_backfill_null_language_codes(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'plugixatestimonial_testimonials',
            $wpdb->prefix . 'plugixatestimonial_categories',
            $wpdb->prefix . 'plugixatestimonial_custom_fields',
        ];

        // Detect default language (Polylang/WPML may not be fully loaded yet)
        $default_lang = '';
        if (function_exists('pll_default_language')) {
            $default_lang = pll_default_language();
        }
        if (empty($default_lang)) {
            // Read Polylang option directly from DB (works before Polylang initializes)
            $pll_options = get_option('polylang');
            if (is_array($pll_options) && !empty($pll_options['default_lang'])) {
                $default_lang = $pll_options['default_lang'];
            }
        }
        if (empty($default_lang) && has_filter('wpml_default_language')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, consumed not created; the name is fixed by WPML.
            $default_lang = apply_filters('wpml_default_language', '');
        }
        if (empty($default_lang)) {
            $default_lang = substr(get_locale(), 0, 2);
        }

        foreach ($tables as $table) {
            // Check if there are any NULL rows to backfill
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $null_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE language_code IS NULL", $table));
            if ($null_count === 0) {
                continue;
            }

            // Set language_code for NULL rows
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->query($wpdb->prepare("UPDATE %i SET language_code = %s WHERE language_code IS NULL", $table,
                $default_lang
            ));

            // Generate translation_group UUIDs for rows that don't have one
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $missing_groups = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE translation_group IS NULL OR translation_group = ''", $table));
            foreach ($missing_groups as $row_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->update($table, ['translation_group' => wp_generate_uuid4()], ['id' => $row_id]);
            }
        }
    }

    private static function maybe_add_status_columns(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'plugixatestimonial_categories',
            $wpdb->prefix . 'plugixatestimonial_custom_fields',
        ];

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, $wpdb->esc_like('status')));
            if (!empty($column)) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN status varchar(20) NOT NULL DEFAULT 'publish'", $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD KEY status (status)", $table));
        }
    }

    private static function maybe_migrate_video_url(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
        $column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, $wpdb->esc_like('video_url')));
        if (!empty($column)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN video_url varchar(500) DEFAULT NULL AFTER client_photo_id", $table));
    }

    /**
     * Add the external_id column: the id a row has on the platform it was imported from
     * (a review id, a comment id). An import deduplicates on (source, external_id), so a
     * review edited on its platform is still recognised as the same review.
     *
     * Flagged by an option because maybe_create_tables() runs on every request.
     */
    private static function maybe_migrate_external_id(): void {
        global $wpdb;

        if (get_option('plugixatestimonial_external_id_migrated')) {
            return;
        }

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
        $column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, $wpdb->esc_like('external_id')));
        if (empty($column)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN external_id varchar(191) DEFAULT NULL AFTER source", $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/migration, before any cache exists to read.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD KEY source_external (source, external_id)", $table));
        }

        update_option('plugixatestimonial_external_id_migrated', 1);
    }

    /**
     * Bumped whenever the capability list below changes.
     */
    public const CAPS_VERSION = 1;

    /**
     * Grant the capability set to installs that already activated.
     *
     * activate() only ever runs once, so a capability added after 1.0 would never reach an
     * existing site. This is polish, not a correctness fix: RestController::can() falls
     * back to core capabilities, so administrators and editors work with or without it.
     * What it actually closes is the uninstall -> reinstall cycle, where
     * Uninstaller::remove_capabilities() strips all five and a Freemius-driven reinstall
     * may not re-fire register_activation_hook().
     *
     * Called from Plugin::__construct() on plugins_loaded, not admin_init - REST requests
     * never fire admin_init, and the capability set must be identical for both.
     */
    public static function maybe_upgrade_capabilities(): void {
        if ((int) get_option('plugixatestimonial_caps_version') === self::CAPS_VERSION) {
            return;
        }

        self::add_capabilities();
        update_option('plugixatestimonial_caps_version', self::CAPS_VERSION, true);
    }

    private static function add_capabilities(): void {
        $admin = get_role('administrator');

        if ($admin) {
            $admin->add_cap('plugixa_testimonial_manage');
            $admin->add_cap('plugixa_testimonial_edit');
            $admin->add_cap('plugixa_testimonial_delete');
            $admin->add_cap('plugixa_testimonial_approve');
            $admin->add_cap('plugixa_testimonial_settings');
        }
    }

    private static function set_default_options(): void {
        $defaults = [
            'items_per_page'     => 20,
            'default_status'     => 'pending',
            'auto_approve'       => false,
            'default_rating'     => 5,
            'show_empty_stars'   => true,
            'date_format'        => 'M j, Y',
            'excerpt_length'     => 150,
            'google_fonts_api_key' => '',
            'enable_schema'      => true,
            'schema_type'        => 'LocalBusiness',
            'schema_name'        => '',
        ];

        if (get_option('plugixatestimonial_settings') === false) {
            add_option('plugixatestimonial_settings', $defaults);
        }
    }

    public static function seed_default_views(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_views';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The query string is a constant literal and the table name is bound as an identifier with %i. This runs during activation/seeding, before any cache exists to read.
        $existing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $table));
        if ($existing > 0) {
            return;
        }

        $user_id = get_current_user_id();
        $now = current_time('mysql');

        $grid_config = self::default_grid_view_config();

        $list_config = array_replace_recursive($grid_config, [
            'layout' => [
                'style' => 'list',
                'columns' => ['desktop' => 1, 'tablet' => 1, 'mobile' => 1],
            ],
            'display' => [
                'excerptLength' => 300,
            ],
        ]);

        $views = [
            [
                'name'        => 'Grid',
                'slug'        => 'grid',
                'description' => 'Responsive grid layout with cards - clean and professional.',
                'config'      => wp_json_encode($grid_config),
                'is_default'  => 1,
                'created_at'  => $now,
                'created_by'  => $user_id,
            ],
            [
                'name'        => 'List',
                'slug'        => 'list',
                'description' => 'Full-width list layout ideal for detailed testimonials.',
                'config'      => wp_json_encode($list_config),
                'is_default'  => 0,
                'created_at'  => $now,
                'created_by'  => $user_id,
            ],
        ];

        foreach ($views as $view) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->insert($table, $view);
        }
    }

    /**
     * The config of the seeded Grid view; other layouts' seeded views start from it.
     */
    public static function default_grid_view_config(): array {
        return [
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
            'photo' => [
                'size' => 60,
                'borderRadius' => 50,
            ],
        ];
    }
}
