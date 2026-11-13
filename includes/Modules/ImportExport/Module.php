<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\ImportExport;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;
use PlugixaTestimonial\Modules\ImportExport\Http\ImportExportController;

/**
 * The import-export module: its REST routes, registered from Http/ImportExportController.
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'import-export';
    }

    public function register_routes(): void {
        (new ImportExportController())->register_routes();
    }

    public function register_hooks(): void {
        // Nothing beyond its routes.
    }
}
