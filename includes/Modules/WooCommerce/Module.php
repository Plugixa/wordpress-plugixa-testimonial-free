<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\WooCommerce;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;
use PlugixaTestimonial\Modules\WooCommerce\Http\WoocommerceContentController;

/**
 * The WooCommerce integration: the product tab and product-data panel, and the routes that
 * list products and product categories. All of it only when WooCommerce is active.
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'woocommerce';
    }

    public function register_routes(): void {
        if (class_exists('WooCommerce')) {
            (new WoocommerceContentController())->register_routes();
        }
    }

    public function register_hooks(): void {
        // Priority 20, after every plugin's own plugins_loaded: WooCommerce defines its main
        // class when it loads, and the integration must not boot on a site without it.
        add_action('plugins_loaded', static function (): void {
            if (class_exists('WooCommerce')) {
                WooCommerce::instance();
            }
        }, 20);
    }
}
