<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\WooCommerce;

if (!defined('ABSPATH')) { exit; }

/**
 * WooCommerce Product Data Panel - Testimonials Tab
 */
class WCProductMetabox {

    public function __construct() {
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'render_product_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_tab_icon_style']);
    }

    /**
     * Dashicon for the Testimonials tab in the product data panel.
     *
     * Attached to WooCommerce's own admin stylesheet through wp_add_inline_style()
     * rather than printed as a <style> block inside the panel markup.
     */
    public function enqueue_tab_icon_style(): void {
        if (!wp_style_is('woocommerce_admin_styles', 'enqueued')
            && !wp_style_is('woocommerce_admin_styles', 'registered')) {
            return;
        }

        wp_add_inline_style(
            'woocommerce_admin_styles',
            '#woocommerce-product-data ul.wc-tabs li.plugixa_testimonial_options a::before{content:"\f155";font-family:dashicons}'
        );
    }

    public function add_product_tab(array $tabs): array {
        $tabs['plugixa_testimonial'] = [
            'label'    => __('Testimonials', 'plugixa-testimonial'),
            'target'   => 'plugixa_testimonial_product_data',
            'class'    => [],
            'priority' => 80,
        ];
        return $tabs;
    }

    public function render_product_panel(): void {
        global $post, $wpdb;

        $shortcode_id = get_post_meta($post->ID, '_plugixa_testimonial_shortcode', true);
        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $shortcodes = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM %i ORDER BY name ASC", $table), ARRAY_A) ?: [];

        ?>
        <div id="plugixa_testimonial_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="_plugixa_testimonial_shortcode"><?php esc_html_e('Testimonial Shortcode', 'plugixa-testimonial'); ?></label>
                    <select id="_plugixa_testimonial_shortcode" name="_plugixa_testimonial_shortcode" class="select short">
                        <option value=""><?php esc_html_e('None', 'plugixa-testimonial'); ?></option>
                        <?php foreach ($shortcodes as $sc) : ?>
                            <option value="<?php echo esc_attr($sc['id']); ?>" <?php selected($shortcode_id, $sc['id']); ?>>
                                <?php echo esc_html($sc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo wp_kses(wc_help_tip(__('Select a testimonial shortcode to display on this product page.', 'plugixa-testimonial')), \PlugixaTestimonial\Core\Kses::allowed_html()); ?>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_product_meta(int $post_id): void {
        // WooCommerce verifies its own nonce and capabilities before firing this hook,
        // but don't rely on that: the hook can be reached by anything that calls it, so
        // re-check here rather than trusting the caller.
        $nonce = isset($_POST['woocommerce_meta_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce']))
            : '';

        if (!wp_verify_nonce($nonce, 'woocommerce_save_data')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['_plugixa_testimonial_shortcode'])) {
            update_post_meta(
                $post_id,
                '_plugixa_testimonial_shortcode',
                sanitize_text_field(wp_unslash($_POST['_plugixa_testimonial_shortcode']))
            );
        }
    }
}
