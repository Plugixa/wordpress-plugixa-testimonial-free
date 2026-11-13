<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\CustomFields;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;
use PlugixaTestimonial\Modules\CustomFields\Http\CustomFieldsController;

/**
 * The custom-fields module: its REST routes, registered from Http/CustomFieldsController.
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'custom-fields';
    }

    public function register_routes(): void {
        (new CustomFieldsController())->register_routes();
    }

    public function register_hooks(): void {
        // Nothing beyond its routes.
    }
}
