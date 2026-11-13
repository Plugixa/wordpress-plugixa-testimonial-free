<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core;

defined('ABSPATH') || exit;

/**
 * Which build is installed: free or premium.
 *
 * The premium build is the free one plus a `pro/` folder, so the folder is the answer. No
 * licence check: a licence is Freemius's business, and the free build must work with no
 * account at all. One helper, so the admin app's "show the Pro features page" flag and
 * every other "is this premium" question can never disagree.
 */
final class Edition {

    /** The premium plans on plugixa.com: where the free build's "See Pro plans" goes. */
    public const PRICING_URL = 'https://www.plugixa.com/plugins/plugixa-testimonial#pricing';

    /**
     * `premium` when the `pro/` folder is on disk, `free` otherwise.
     *
     * @param string|null $plugin_path The plugin folder; the installed one when omitted.
     *                                 Only a test passes it.
     */
    public static function current(?string $plugin_path = null): string {
        $base = trailingslashit($plugin_path ?? PLUGIXA_TESTIMONIAL_PATH);

        return is_dir($base . 'pro/') ? 'premium' : 'free';
    }

    public static function is_premium(): bool {
        return self::current() === 'premium';
    }
}
