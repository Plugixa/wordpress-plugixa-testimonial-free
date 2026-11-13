<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Blocks;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Modules\Frontend\Shortcodes;

/**
 * Gutenberg Block - Testimonial Display
 */
class TestimonialBlock {

    public function register(): void {
        $block_dir = PLUGIXA_TESTIMONIAL_PATH . 'assets/build/blocks/testimonial-block';

        if (!file_exists($block_dir . '/block.json')) {
            return;
        }

        register_block_type($block_dir, [
            'render_callback' => [$this, 'render'],
        ]);

        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    public function enqueue_editor_assets(): void {
        Shortcodes::enqueue_frontend_assets();

        // Re-init the frontend runtime after Gutenberg's ServerSideRender replaces the
        // block markup. Shipped as a real file, not an inline heredoc (Plugin Check
        // disallows heredocs, and a static asset is cacheable).
        wp_enqueue_script(
            'plugixa-testimonial-block-editor',
            PLUGIXA_TESTIMONIAL_URL . 'assets/backend/js/block-editor-reinit.js',
            [\PlugixaTestimonial\Modules\Frontend\Shortcodes::SWIPER_HANDLE],
            PLUGIXA_TESTIMONIAL_VERSION,
            true
        );
    }

    public function render(array $attributes): string {
        $atts = [];

        $mode = $attributes['mode'] ?? 'existing';

        if ($mode === 'existing' && !empty($attributes['shortcodeId'])) {
            $atts['id'] = (int) $attributes['shortcodeId'];
        } else {
            if (!empty($attributes['viewId'])) {
                $atts['view_id'] = (int) $attributes['viewId'];
            }
            if (!empty($attributes['category'])) {
                $atts['category'] = sanitize_text_field($attributes['category']);
            }
            if (!empty($attributes['limit'])) {
                $atts['limit'] = (int) $attributes['limit'];
            }
            if (!empty($attributes['orderby'])) {
                $atts['orderby'] = sanitize_key($attributes['orderby']);
            }
            if (!empty($attributes['order'])) {
                $atts['order'] = strtoupper(sanitize_key($attributes['order']));
            }
            if (!empty($attributes['featuredOnly'])) {
                $atts['featured_only'] = '1';
            }
            if (!empty($attributes['minRating'])) {
                $atts['min_rating'] = (int) $attributes['minRating'];
            }

            // Pass display toggles when set to false
            $display_map = [
                'showPhoto'     => 'show_photo',
                'showRating'    => 'show_rating',
                'showRole'      => 'show_role',
                'showCompany'   => 'show_company',
                'showDate'      => 'show_date',
                'showWebsite'   => 'show_website',
                'showQuoteIcon' => 'show_quote_icon',
            ];
            foreach ($display_map as $block_attr => $shortcode_attr) {
                if (isset($attributes[$block_attr]) && $attributes[$block_attr] === false) {
                    $atts[$shortcode_attr] = '0';
                }
            }
        }

        $shortcodes = new Shortcodes();
        return $shortcodes->render_testimonials($atts);
    }
}
