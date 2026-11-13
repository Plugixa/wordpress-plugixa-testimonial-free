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
 * Validates every value of a view configuration that ends up in CSS.
 *
 * A view's colours, sizes, shadows and fonts are printed as custom properties in the
 * container's style attribute and in the dark-mode rule added with wp_add_inline_style().
 * esc_attr() encodes none of ; { } :, so escaping alone would let a stored value close
 * its declaration and open another. Each value is therefore VALIDATED against the type
 * it must have, and replaced by the default when it is not of that type.
 *
 * normalize() runs twice: in ViewsController when a view is saved, and in Shortcodes
 * when it is rendered, so rows saved before this check existed are covered as well.
 */
final class ViewStyle {

    /** Config groups whose every scalar leaf is a colour. */
    private const COLOR_GROUPS = [
        ['colors'],
        ['darkMode'],
        ['darkMode', 'categories'],
        ['categories', 'colors'],
        ['pagination', 'numbers'],
        ['pagination', 'loadMore'],
        ['pagination', 'infiniteScroll'],
    ];

    /** Single colour leaves outside the groups above. */
    private const COLOR_LEAVES = [
        ['photo', 'fallbackBgColor'],
        ['photo', 'fallbackTextColor'],
        ['photo', 'borderColor'],
    ];

    /** Numeric leaves: path => [min, max]. */
    private const NUMBERS = [
        'typography.textFontSize'      => [6, 200],
        'typography.nameFontSize'      => [6, 200],
        'typography.roleFontSize'      => [6, 200],
        'typography.nameFontWeight'    => [100, 900],
        'typography.textFontWeight'    => [100, 900],
        'spacing.gap'                  => [0, 500],
        'spacing.cardPadding'          => [0, 500],
        'spacing.photoPadding'         => [0, 500],
        'borders.radius'               => [0, 500],
        'borders.width'                => [0, 100],
        'photo.size'                   => [0, 2000],
        'photo.borderRadius'           => [0, 100],
        'photo.borderWidth'            => [0, 100],
        'photo.opacity'                => [0, 1],
        'photo.hoverScale'             => [0, 5],
        'categories.typography.fontSize'   => [6, 200],
        'categories.typography.fontWeight' => [100, 900],
        'categories.spacing.padding'   => [0, 500],
        'categories.spacing.gap'       => [0, 500],
        'categories.border.width'      => [0, 100],
        'categories.border.radius'     => [0, 500],
    ];

    /** Keyword leaves: path => allowed values (the first is the fallback). */
    private const KEYWORDS = [
        'photo.sizeUnit'    => ['px', '%'],
        'photo.fallbackColorMode' => ['single', 'auto'],
        'photo.borderStyle' => ['none', 'solid', 'dashed', 'dotted', 'double'],
    ];

    private const SHADOWS = ['shadows.value', 'photo.shadowValue'];

    private const FONTS = [
        'typography.textFontFamily',
        'typography.nameFontFamily',
        'typography.roleFontFamily',
        'categories.typography.fontFamily',
    ];

    /**
     * Return $config with every CSS-bound leaf validated. Leaves that are absent stay
     * absent (the renderer applies its own defaults); invalid ones are dropped so the
     * same defaults apply.
     */
    public static function normalize(array $config): array {
        foreach (self::COLOR_GROUPS as $path) {
            $group = self::get($config, $path);
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $key => $value) {
                if (is_array($value) || is_bool($value)) {
                    continue; // nested group (handled by its own entry) or a flag such as darkMode.enabled
                }
                $config = self::put($config, array_merge($path, [$key]), self::color((string) $value));
            }
        }

        foreach (self::COLOR_LEAVES as $path) {
            $value = self::get($config, $path);
            if ($value !== null && !is_array($value)) {
                $config = self::put($config, $path, self::color((string) $value));
            }
        }

        foreach (self::NUMBERS as $dotted => [$min, $max]) {
            $path = explode('.', $dotted);
            $value = self::get($config, $path);
            if ($value !== null) {
                $config = self::put($config, $path, is_numeric($value) ? max($min, min($max, 0 + $value)) : null);
            }
        }

        foreach (self::KEYWORDS as $dotted => $allowed) {
            $path = explode('.', $dotted);
            $value = self::get($config, $path);
            if ($value !== null) {
                $config = self::put($config, $path, in_array($value, $allowed, true) ? $value : $allowed[0]);
            }
        }

        foreach (self::SHADOWS as $dotted) {
            $path = explode('.', $dotted);
            $value = self::get($config, $path);
            if ($value !== null) {
                $config = self::put($config, $path, self::shadow((string) $value));
            }
        }

        foreach (self::FONTS as $dotted) {
            $path = explode('.', $dotted);
            $value = self::get($config, $path);
            if ($value !== null) {
                $config = self::put($config, $path, self::font_family((string) $value));
            }
        }

        return $config;
    }

    /**
     * Validate one CSS colour. Returns the colour, normalised, or '' when $value is not
     * one this plugin accepts ('' makes the caller fall back to its default).
     *
     * Also used for the "color" custom field, whose value a visitor can submit through
     * the public form - so this is the only thing between that input and a style attribute.
     */
    public static function color(string $value): string {
        $value = strtolower(trim($value));

        // Hex: #rgb, #rgba, #rrggbb, #rrggbbaa (what the admin pickers save).
        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value)) {
            return $value;
        }

        if ($value === 'transparent') {
            return $value;
        }

        // rgb()/rgba() in comma syntax, as mui-color-input writes it: three 0-255
        // integers and an optional 0-1 alpha. Nothing else can appear inside.
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(0|1|0?\.\d+|1\.0+)\s*)?\)$/', $value, $m)
            && max((int) $m[1], (int) $m[2], (int) $m[3]) <= 255
        ) {
            return $value;
        }

        return '';
    }

    /**
     * A box-shadow: lengths, the keyword inset, and colours, with no character that can
     * end a declaration (; { } :), open a string, or form a url().
     */
    public static function shadow(string $value): string {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'none') {
            return 'none';
        }
        if (strlen($value) > 200 || !preg_match('/^[a-z0-9#.,%()\s-]+$/i', $value)) {
            return '';
        }
        // Only colour functions may appear; anything else in brackets (url, expression, var…) is refused.
        if (preg_match_all('/([a-z-]+)\s*\(/i', $value, $m)) {
            foreach ($m[1] as $fn) {
                if (!in_array(strtolower($fn), ['rgb', 'rgba', 'hsl', 'hsla'], true)) {
                    return '';
                }
            }
        }
        return $value;
    }

    /**
     * A font-family name or list. Each family keeps letters, digits, spaces, hyphens and
     * underscores only. The name is otherwise left as saved, because the Google Fonts
     * download looks the family up by it; quoting happens where the CSS is printed.
     */
    public static function font_family(string $value): string {
        $families = [];
        foreach (explode(',', $value) as $family) {
            $family = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $family));
            if ($family !== '') {
                $families[] = $family;
            }
        }
        return $families ? implode(', ', $families) : 'inherit';
    }

    /** @param array<int|string> $path */
    private static function get(array $config, array $path) {
        foreach ($path as $key) {
            if (!is_array($config) || !array_key_exists($key, $config)) {
                return null;
            }
            $config = $config[$key];
        }
        return $config;
    }

    /**
     * Set a leaf; a null or '' value removes it so the renderer's default applies.
     *
     * @param array<int|string> $path
     */
    private static function put(array $config, array $path, $value): array {
        $key = array_shift($path);
        if ($path) {
            $config[$key] = self::put(is_array($config[$key] ?? null) ? $config[$key] : [], $path, $value);
        } elseif ($value === null || $value === '') {
            unset($config[$key]);
        } else {
            $config[$key] = $value;
        }
        return $config;
    }
}
