<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Views;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;
use PlugixaTestimonial\Modules\Views\Http\ViewsController;

/**
 * The views module: its REST routes, registered from Http/ViewsController.
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'views';
    }

    public function register_routes(): void {
        (new ViewsController())->register_routes();
    }

    public function register_hooks(): void {
        // Nothing beyond its routes.
    }
}
