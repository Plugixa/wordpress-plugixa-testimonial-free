<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\WooCommerce;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Modules\Frontend\Shortcodes;

/**
 * WooCommerce Product Frontend Tab - Displays testimonials on product pages
 */
class WCProductTab {

    public function __construct() {
        add_filter('woocommerce_product_tabs', [$this, 'add_product_tab']);
    }

    public function add_product_tab(array $tabs): array {
        $settings = get_option('plugixatestimonial_settings', []);

        if (empty($settings['wc_testimonial_enabled'])) {
            return $tabs;
        }

        global $product;
        if (!$product) {
            return $tabs;
        }

        $shortcode_id = get_post_meta($product->get_id(), '_plugixa_testimonial_shortcode', true);
        if (empty($shortcode_id)) {
            return $tabs;
        }

        $title = apply_filters(
            'plugixa_testimonial_product_tab_title',
            $settings['wc_testimonial_tab_title'] ?? __('Testimonials', 'plugixa-testimonial')
        );

        $priority = apply_filters(
            'plugixa_testimonial_product_tab_priority',
            (int) ($settings['wc_testimonial_tab_priority'] ?? 50)
        );

        $tabs['plugixa_testimonial'] = [
            'title'    => $title,
            'priority' => $priority,
            'callback' => [$this, 'render_tab_content'],
        ];

        return $tabs;
    }

    public function render_tab_content(): void {
        global $product;
        if (!$product) {
            return;
        }

        $shortcode_id = get_post_meta($product->get_id(), '_plugixa_testimonial_shortcode', true);
        if (empty($shortcode_id)) {
            return;
        }

        $content = do_shortcode('[plugixa_testimonial id="' . (int) $shortcode_id . '"]');

        echo wp_kses(
            apply_filters('plugixa_testimonial_product_tab_content', $content, $product),
            \PlugixaTestimonial\Core\Kses::allowed_html()
        );
    }
}
