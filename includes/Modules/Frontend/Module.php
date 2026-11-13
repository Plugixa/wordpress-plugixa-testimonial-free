<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Frontend;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;
use PlugixaTestimonial\Modules\Frontend\Http\FrontendApi;

/**
 * What visitors see: the shortcodes and templates, the social-proof popups, the review
 * schema, and the public REST routes the frontend reads and submits to.
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'frontend';
    }

    public function register_routes(): void {
        (new FrontendApi())->register_routes();
    }

    public function register_hooks(): void {
        $shortcodes = new Shortcodes();
        add_action('init', [$shortcodes, 'register']);

        (new SocialProof())->init();
    }
}
