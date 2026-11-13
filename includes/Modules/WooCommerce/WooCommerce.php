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
 * WooCommerce Integration Loader
 */
class WooCommerce {

    private static ?WooCommerce $instance = null;

    public static function instance(): WooCommerce {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        new WCProductMetabox();
        new WCProductTab();
    }
}
