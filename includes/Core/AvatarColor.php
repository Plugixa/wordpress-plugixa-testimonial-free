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
 * Picks a background colour for a photo placeholder from the client's name, for views
 * whose photo.fallbackColorMode is 'auto'.
 *
 * The colour is a hash of the name, not a random pick, so a person keeps the same colour
 * on every page load, in every layout and in the admin preview.
 *
 * The admin preview has a copy of this in assets/backend/app/src/lib/avatarColor.ts.
 * Both must keep the same palette, in the same order, and the same hash, or the preview
 * shows different colours from the site.
 */
final class AvatarColor {

    /** Every colour reaches at least 4.5:1 contrast with TEXT. */
    public const PALETTE = [
        '#1d4ed8', // blue
        '#0e7490', // cyan
        '#7c3aed', // violet
        '#047857', // emerald
        '#be185d', // pink
        '#c2410c', // orange
        '#4f46e5', // indigo
        '#0f766e', // teal
        '#a21caf', // fuchsia
        '#b91c1c', // red
        '#3f6212', // lime
        '#475569', // slate
    ];

    public const TEXT = '#ffffff';

    public static function for_name(string $name): string {
        $hash = 0;
        foreach (mb_str_split(mb_strtolower(trim($name))) as $char) {
            $hash = ($hash * 31 + mb_ord($char)) & 0xFFFFFFFF;
        }
        return self::PALETTE[$hash % count(self::PALETTE)];
    }

    /**
     * A style attribute (with its leading space) that overrides the view's single
     * placeholder colour for this one card.
     */
    public static function style_attribute(string $name): string {
        $css = '--pxt-photo-fallback-bg:' . self::for_name($name) . ';--pxt-photo-fallback-text:' . self::TEXT;
        return ' style="' . esc_attr($css) . '"';
    }
}
