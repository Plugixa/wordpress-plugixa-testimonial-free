<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Categories;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;
use PlugixaTestimonial\Modules\Categories\Http\CategoriesController;

/**
 * Testimonial categories: their list, CRUD and manual ordering under /categories.
 *
 * Its table, plugixatestimonial_categories, and the testimonial pivot are still created by
 * Activator; see ModuleInterface.
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'categories';
    }

    public function register_routes(): void {
        (new CategoriesController())->register_routes();
    }

    public function register_hooks(): void {
        // Nothing beyond its routes.
    }
}
