<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Elementor;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;

/**
 * The Elementor widget. Only when Elementor itself has loaded.
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'elementor';
    }

    public function register_routes(): void {
        // The widget renders through the shortcode; it has no routes of its own.
    }

    public function register_hooks(): void {
        // Priority 20, after Elementor's own plugins_loaded, so `elementor/loaded` has fired
        // on a site that runs it and has not on one that does not.
        add_action('plugins_loaded', static function (): void {
            if (did_action('elementor/loaded')) {
                Elementor::instance();
            }
        }, 20);
    }
}
