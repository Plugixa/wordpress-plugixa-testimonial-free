<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Elementor;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Modules\Frontend\Shortcodes;

/**
 * Elementor Integration Loader
 */
class Elementor {

    private static ?Elementor $instance = null;

    public static function instance(): Elementor {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!did_action('elementor/loaded')) {
            return;
        }

        if (!version_compare(ELEMENTOR_VERSION, '3.0.0', '>=')) {
            return;
        }

        add_action('elementor/elements/categories_registered', [$this, 'register_categories']);
        add_action('elementor/widgets/register', [$this, 'register_widgets']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_assets']);
        add_action('elementor/frontend/after_register_scripts', [$this, 'register_frontend_assets']);
        add_action('elementor/preview/enqueue_styles', [$this, 'enqueue_preview_assets']);
    }

    public function register_categories($elements_manager): void {
        $elements_manager->add_category('plugixa-testimonial', [
            'title' => __('Plugixa Testimonial', 'plugixa-testimonial'),
            'icon'  => 'eicon-review',
        ]);
    }

    public function register_widgets($widgets_manager): void {
        $widgets_manager->register(new TestimonialWidget());
    }

    public function enqueue_editor_assets(): void {
        wp_enqueue_style(
            'plugixa-testimonial-elementor-editor',
            PLUGIXA_TESTIMONIAL_URL . 'assets/integrations/elementor/editor.css',
            [],
            PLUGIXA_TESTIMONIAL_VERSION
        );
    }

    public function register_frontend_assets(): void {
        Shortcodes::enqueue_frontend_assets();
    }

    public function enqueue_preview_assets(): void {
        Shortcodes::enqueue_frontend_assets();
    }
}
