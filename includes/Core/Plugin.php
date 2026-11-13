<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Admin\Admin;
use PlugixaTestimonial\Core\Modules\ModuleRegistry;

/**
 * Main Plugin Class - Singleton pattern
 */
class Plugin {

    private static ?Plugin $instance = null;

    /**
     * The modules found on disk (includes/Modules, and pro/includes/Modules in the premium
     * build); the layout shared with plugixa-inventory.
     */
    private ModuleRegistry $modules;

    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->modules = new ModuleRegistry();
        Activator::maybe_create_tables();
        Activator::maybe_upgrade_capabilities();
        Multilingual::instance();
        $this->init_hooks();
    }

    private function init_hooks(): void {
        // No load_plugin_textdomain(): since WP 4.6 translations are loaded just-in-time
        // from the Domain Path header (/languages), and Plugin Check flags the explicit call.

        // Object-cache invalidation for the custom tables. Hooked once, on the REST
        // lifecycle, so no individual write site can forget to flush.
        Cache::register();

        // Allow the plugin's --pxt-* custom properties through kses'd style attributes.
        // Every frontend fragment is late-escaped with wp_kses() + Kses::allowed_html(),
        // and per-instance theming travels entirely as those custom properties.
        Kses::register();

        if (is_admin()) {
            $admin = new Admin();
            add_action('admin_menu', [$admin, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$admin, 'enqueue_assets']);
        }

        // Everything else belongs to a module: the shortcodes, social proof, the block, the
        // Elementor and WooCommerce integrations, font self-hosting, and in the premium build
        // the Pro overlay's own hooks (pro/includes/Modules/Pro), which the free build lacks.
        $this->modules->register_hooks();
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }


    /**
     * Every REST route comes from a module now. Premium modules sit in pro/includes/Modules,
     * which the free build does not contain, so the free core names no premium class at all:
     * there is nothing to check for and nothing to lock (WordPress.org guideline 5).
     */
    public function register_rest_routes(): void {
        $this->modules->register_routes();
    }

}
