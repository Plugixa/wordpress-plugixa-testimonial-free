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
 * Plugin Uninstaller - Drops all tables, options, capabilities, and demo media.
 *
 * Runs via the Freemius `after_uninstall` action (registered in the main plugin file),
 * NOT a root uninstall.php: WordPress only runs uninstall.php from the active plugin
 * folder, which breaks with Freemius's free/premium version switching, and Freemius
 * rejects plugins that ship one.
 */
class Uninstaller {

    public static function uninstall(): void {
        self::drop_tables();
        self::delete_demo_attachments();
        self::delete_options();
        self::remove_capabilities();
        \PlugixaTestimonial\Modules\GoogleFonts\FontManager::cleanup();
    }

    /**
     * Every table Activator::create_tables() creates. Child tables first so the
     * foreign-key-style dependents go before the rows they point at.
     *
     * Keep in sync with includes/class-activator.php - a table missing here survives
     * uninstall, and `requests` in particular holds recipient names and email
     * addresses, so leaving it behind orphans personal data on the site.
     */
    private const TABLES = [
        'plugixatestimonial_meta',
        'plugixatestimonial_custom_fields',
        'plugixatestimonial_testimonial_categories',
        'plugixatestimonial_requests',
        'plugixatestimonial_testimonials',
        'plugixatestimonial_categories',
        'plugixatestimonial_views',
        'plugixatestimonial_shortcodes',
    ];

    private static function drop_tables(): void {
        global $wpdb;

        foreach (self::TABLES as $table) {
            $name = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Table name is built from $wpdb->prefix and a hardcoded constant, and is bound as an identifier with %i; uninstall is a one-off with nothing to cache.
            $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $name));
        }
    }

    private static function delete_demo_attachments(): void {
        // Delete demo attachments tracked at seed time
        $demo_attachment_ids = get_option('plugixatestimonial_demo_attachment_ids', []);
        if (is_array($demo_attachment_ids)) {
            foreach ($demo_attachment_ids as $id) {
                wp_delete_attachment((int) $id, true);
            }
        }

        // Fallback: query by meta tag for any demo attachments not in the option
        $remaining_demo = get_posts([
            'post_type'   => 'attachment',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            'meta_key'    => '_plugixatestimonial_demo',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value itself is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            'meta_value'  => '1',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        foreach ($remaining_demo as $id) {
            wp_delete_attachment((int) $id, true);
        }
    }

    private static function delete_options(): void {
        $options = [
            'plugixatestimonial_settings',
            'plugixatestimonial_demo_attachment_ids',
            'plugixatestimonial_demo_loaded',
            // Schema-migration flags. Left behind, these make a later reinstall skip
            // the multilingual migrations against a fresh (already-v2) schema.
            'plugixatestimonial_ml_migrated',
            'plugixatestimonial_ml_v2_migrated',
            'plugixatestimonial_show_website_migrated',
            'plugixatestimonial_custom_css_purged',
            'plugixatestimonial_external_id_migrated',
            'plugixatestimonial_layout_views_seeded',
            // Review-platform connections (API keys, OAuth tokens) and the sync log the
            // premium review importers keep. Credentials must not outlive the plugin.
            'plugixatestimonial_review_credentials',
            'plugixatestimonial_review_sync_state',
            // Same reasoning: left behind, this makes the next install's
            // Activator::maybe_upgrade_capabilities() short-circuit and leave the
            // administrator role without the capabilities remove_capabilities() just took.
            'plugixatestimonial_caps_version',
        ];

        foreach ($options as $option) {
            delete_option($option);
        }

        delete_transient('plugixatestimonial_google_fonts');
        wp_clear_scheduled_hook('plugixatestimonial_review_sync');
    }

    private static function remove_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap('plugixa_testimonial_manage');
            $admin->remove_cap('plugixa_testimonial_edit');
            $admin->remove_cap('plugixa_testimonial_delete');
            $admin->remove_cap('plugixa_testimonial_approve');
            $admin->remove_cap('plugixa_testimonial_settings');
        }
    }
}
