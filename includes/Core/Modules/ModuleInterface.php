<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core\Modules;

if (!defined('ABSPATH')) { exit; }

/**
 * The contract every module implements, free or premium; the layout shared with
 * plugixa-inventory.
 *
 * A module is a self-contained folder. Adding one means dropping a directory into
 * includes/Modules/ (free) or pro/includes/Modules/ (premium): ModuleRegistry finds it on
 * disk, and no core file names it.
 *
 * Tables are not part of the contract yet. The plugin's eight tables and their migrations
 * still live in Activator; they move here module by module once every controller has.
 */
interface ModuleInterface {

    /**
     * Stable machine id, e.g. "categories". Used to order modules and in log messages.
     */
    public function id(): string;

    /**
     * Register REST routes. Called on rest_api_init.
     */
    public function register_routes(): void;

    /**
     * Register WordPress hooks: filters, actions, cron. Called once, when the plugin boots.
     */
    public function register_hooks(): void;
}
