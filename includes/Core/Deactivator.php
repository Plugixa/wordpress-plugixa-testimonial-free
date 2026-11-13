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
 * Plugin Deactivator
 */
class Deactivator {

    public static function deactivate(): void {
        self::clear_scheduled_events();
        flush_rewrite_rules();
    }

    private static function clear_scheduled_events(): void {
        wp_clear_scheduled_hook('plugixatestimonial_daily_cleanup');
        // Review-platform sync, scheduled by the premium review importers when it is on.
        wp_clear_scheduled_hook('plugixatestimonial_review_sync');
    }
}
