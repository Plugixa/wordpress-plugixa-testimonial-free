<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Blocks;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;

/**
 * The Gutenberg block, registered on init from assets/build/blocks.
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'blocks';
    }

    public function register_routes(): void {
        // The block renders through the shortcode; it has no routes of its own.
    }

    public function register_hooks(): void {
        add_action('init', static function (): void {
            (new TestimonialBlock())->register();
        });
    }
}
