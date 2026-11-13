<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\GoogleFonts;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Modules\ModuleInterface;
use PlugixaTestimonial\Modules\GoogleFonts\Http\GoogleFontsController;

/**
 * Google Fonts for the view builder: the font-list proxy route, and FontManager, which
 * downloads a chosen font once and serves it from this site (no visitor request to Google).
 */
final class Module implements ModuleInterface {

    public function id(): string {
        return 'google-fonts';
    }

    public function register_routes(): void {
        (new GoogleFontsController())->register_routes();
    }

    public function register_hooks(): void {
        FontManager::register();
    }
}
