<?php
/**
 * Plugixa Testimonial
 *
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central allowed-HTML definitions for late-escaping frontend output.
 *
 * Every fragment the plugin assembles (testimonial cards, the submission form, the
 * category and rating filters, pagination, the social-proof popup, ...) is passed
 * through wp_kses() with the allowlist below AT ECHO TIME. The list is the union of
 * what a testimonial body may contain ('content' context) and the interactive
 * vocabulary the plugin's own UI uses (<button>, <input>, <iframe> video embeds,
 * inline SVG icons, ...).
 *
 * Call wp_kses() DIRECTLY at each echo site:
 *
 *     echo wp_kses($html, \PlugixaTestimonial\Core\Kses::allowed_html());
 *
 * Do NOT wrap it in a helper and echo that — WordPress.Security.EscapeOutput only
 * recognises the direct call and flags anything else, which is how the "escape late"
 * review flag comes back.
 */
final class Kses {

    /**
     * Allowed-HTML map for a rendering context.
     *
     * Contexts:
     * - 'content'  — the testimonial body as authored/submitted.
     *                wp_kses_allowed_html('post') extended via the
     *                plugixa_testimonial_content_allowed_html filter.
     * - 'frontend' — full plugin-rendered fragments. Superset of 'content' (fragments
     *                embed testimonial bodies) plus the plugin UI vocabulary. No
     *                <script> and no <style>: the JSON-LD payload goes through
     *                wp_print_inline_script_tag() and per-instance styling travels as
     *                --pxt-* CSS custom properties in style attributes.
     *
     * @param string $context 'frontend' or 'content'.
     * @return array wp_kses() allowlist.
     */
    public static function allowed_html(string $context = 'frontend'): array {
        $content = apply_filters(
            'plugixa_testimonial_content_allowed_html',
            wp_kses_allowed_html('post')
        );

        if ($context === 'content') {
            return $content;
        }

        $allowed = $content;

        // Structural / interactive elements the plugin UI emits.
        $allowed['nav']        = [];
        $allowed['button']     = ['type' => true, 'name' => true, 'value' => true, 'disabled' => true];
        $allowed['form']       = array_merge($allowed['form'] ?? [], [
            'action' => true, 'method' => true, 'enctype' => true, 'novalidate' => true,
        ]);
        $allowed['input']      = array_merge($allowed['input'] ?? [], [
            'type' => true, 'name' => true, 'value' => true, 'placeholder' => true,
            'required' => true, 'maxlength' => true, 'minlength' => true,
            'autocomplete' => true, 'min' => true, 'max' => true, 'step' => true,
            'checked' => true, 'disabled' => true, 'readonly' => true, 'accept' => true,
            'pattern' => true,
        ]);
        $allowed['textarea']   = [
            'name' => true, 'rows' => true, 'cols' => true, 'placeholder' => true,
            'maxlength' => true, 'minlength' => true, 'required' => true, 'disabled' => true,
        ];
        $allowed['select']     = array_merge($allowed['select'] ?? [], [
            'name' => true, 'multiple' => true, 'disabled' => true, 'required' => true,
        ]);
        $allowed['option']     = array_merge($allowed['option'] ?? [], [
            'value' => true, 'selected' => true, 'disabled' => true,
        ]);
        $allowed['optgroup']   = ['label' => true];
        $allowed['label']      = array_merge($allowed['label'] ?? [], ['for' => true]);
        $allowed['fieldset']   = ['disabled' => true];
        $allowed['legend']     = [];
        $allowed['figure']     = $allowed['figure'] ?? [];
        $allowed['figcaption'] = $allowed['figcaption'] ?? [];
        $allowed['blockquote'] = array_merge($allowed['blockquote'] ?? [], ['cite' => true]);
        $allowed['time']       = ['datetime' => true];
        $allowed['mark']       = [];
        $allowed['noscript']   = [];

        // Responsive client photos: Shortcodes::render_photo() emits <picture>/srcset,
        // which wp_kses_allowed_html('post') does not carry in full.
        $allowed['picture'] = [];
        $allowed['source']  = [
            'src' => true, 'srcset' => true, 'sizes' => true, 'type' => true, 'media' => true,
        ];

        // Video testimonials. Self-hosted files render in <video>; YouTube plays from
        // the privacy-enhanced youtube-nocookie domain and Vimeo from player.vimeo.com,
        // both only after the visitor clicks the click-to-play thumbnail.
        $allowed['video'] = [
            'src' => true, 'controls' => true, 'preload' => true, 'poster' => true,
            'width' => true, 'height' => true, 'playsinline' => true, 'muted' => true,
            'loop' => true, 'autoplay' => true,
        ];
        $allowed['iframe'] = [
            'src' => true, 'width' => true, 'height' => true, 'frameborder' => true,
            'allow' => true, 'allowfullscreen' => true, 'loading' => true,
            'referrerpolicy' => true, 'sandbox' => true, 'name' => true,
        ];

        // Inline SVG icons (kses lowercases attribute names: viewBox => viewbox).
        $svg_paint = [
            'fill' => true, 'stroke' => true, 'stroke-width' => true,
            'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-dasharray' => true,
            'fill-rule' => true, 'clip-rule' => true, 'opacity' => true, 'transform' => true,
        ];
        $allowed['svg']      = array_merge($svg_paint, [
            'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true,
            'focusable' => true, 'preserveaspectratio' => true, 'xmlns:xlink' => true,
        ]);
        $allowed['path']     = array_merge($svg_paint, ['d' => true]);
        $allowed['circle']   = array_merge($svg_paint, ['cx' => true, 'cy' => true, 'r' => true]);
        $allowed['ellipse']  = array_merge($svg_paint, ['cx' => true, 'cy' => true, 'rx' => true, 'ry' => true]);
        $allowed['line']     = array_merge($svg_paint, ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true]);
        $allowed['rect']     = array_merge($svg_paint, [
            'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true,
        ]);
        $allowed['polyline'] = array_merge($svg_paint, ['points' => true]);
        $allowed['polygon']  = array_merge($svg_paint, ['points' => true]);
        $allowed['g']        = $svg_paint;
        $allowed['defs']     = [];
        $allowed['use']      = array_merge($svg_paint, ['href' => true, 'xlink:href' => true]);
        $allowed['title']    = [];

        // Link/image extras beyond the 'post' defaults.
        $allowed['a']   = array_merge($allowed['a'] ?? [], ['target' => true, 'rel' => true]);
        $allowed['img'] = array_merge($allowed['img'] ?? [], [
            'loading' => true, 'decoding' => true, 'srcset' => true, 'sizes' => true,
            'fetchpriority' => true,
        ]);

        // Global attributes on every allowed element.
        //
        // wp_kses() supports ONLY the data-* wildcard — an 'aria-*' key is silently
        // ignored and every aria attribute is stripped. The slider controls, the
        // pagination nav and the social-proof live region all depend on these, so each
        // one is listed explicitly. Adding a new aria-* attribute to a template means
        // adding it here too, or it vanishes with no error.
        $global_attrs = [
            'id' => true, 'class' => true, 'style' => true, 'role' => true,
            'title' => true, 'tabindex' => true, 'hidden' => true,
            'lang' => true, 'dir' => true,
            'data-*' => true,
            'aria-label' => true, 'aria-labelledby' => true, 'aria-describedby' => true,
            'aria-hidden' => true, 'aria-expanded' => true, 'aria-controls' => true,
            'aria-current' => true, 'aria-live' => true, 'aria-atomic' => true,
            'aria-pressed' => true, 'aria-selected' => true, 'aria-disabled' => true,
            'aria-haspopup' => true, 'aria-required' => true, 'aria-invalid' => true,
            'aria-valuenow' => true, 'aria-valuemin' => true, 'aria-valuemax' => true,
            'aria-valuetext' => true, 'aria-roledescription' => true,
            // Dialog / listbox vocabulary used by the submission form's modal style.
            'aria-modal' => true, 'aria-multiselectable' => true, 'aria-orientation' => true,
            'aria-owns' => true, 'aria-placeholder' => true, 'aria-readonly' => true,
            'aria-level' => true, 'aria-setsize' => true, 'aria-posinset' => true,
            'aria-busy' => true, 'aria-checked' => true, 'aria-errormessage' => true,
            'aria-relevant' => true, 'aria-flowto' => true, 'aria-keyshortcuts' => true,
        ];
        foreach ($allowed as $tag => $attrs) {
            $allowed[$tag] = array_merge(is_array($attrs) ? $attrs : [], $global_attrs);
        }

        /**
         * Filter the frontend allowed-HTML vocabulary.
         *
         * @param array  $allowed wp_kses() allowlist.
         * @param string $context Rendering context.
         */
        return apply_filters('plugixa_testimonial_allowed_html', $allowed, $context);
    }

    /**
     * Permit the plugin's CSS custom properties in kses'd style attributes.
     *
     * Registered once (see register()); wp_kses() runs style attribute values through
     * safecss_filter_attr(), whose support for custom-property declarations and var()
     * values varies across WP versions. Every per-instance colour, font and spacing
     * value the view builder produces travels as a --pxt-* property, so those must
     * always survive or configured displays render unstyled.
     *
     * @param bool   $allow           Core's verdict for this declaration.
     * @param string $css_test_string The single CSS declaration under test.
     * @return bool
     */
    public static function allow_custom_property_css($allow, $css_test_string) {
        if ($allow) {
            return $allow;
        }
        // "--pxt-card-bg:#1d2327" (custom property declaration) or
        // "background-color:var(--pxt-card-bg)" (var() value).
        if (preg_match('/^--[a-zA-Z0-9_-]+\s*:/', (string) $css_test_string)
            || preg_match('/^[a-zA-Z-]+\s*:\s*[^;]*var\(--[a-zA-Z0-9_-]+/', (string) $css_test_string)) {
            return true;
        }
        return $allow;
    }

    /**
     * Hook the CSS custom-property allowance. Idempotent; called from Plugin::init().
     */
    public static function register(): void {
        if (!has_filter('safecss_filter_attr_allow_css', [self::class, 'allow_custom_property_css'])) {
            add_filter('safecss_filter_attr_allow_css', [self::class, 'allow_custom_property_css'], 10, 2);
        }
    }
}
