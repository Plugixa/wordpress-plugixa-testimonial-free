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
 * Finds the modules on disk and hands each the WordPress events it registers on.
 *
 * Discovery is by folder: every includes/Modules/<Name>/Module.php is a free module and
 * every pro/includes/Modules/<Name>/Module.php a premium one. The premium folder is absent
 * from the free build, so on a free site there is nothing there to find: availability is
 * decided by what code is shipped, never by a licence check (WordPress.org guideline 5).
 */
final class ModuleRegistry {

    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    private bool $discovered = false;

    /**
     * Load every module once. Idempotent, so the routes and the hooks can both ask.
     */
    public function discover(): void {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        $sources = [
            'PlugixaTestimonial\\Modules\\'      => PLUGIXA_TESTIMONIAL_PATH . 'includes/Modules/',
            'PlugixaTestimonial\\Pro\\Modules\\' => PLUGIXA_TESTIMONIAL_PATH . 'pro/includes/Modules/',
        ];

        foreach ($sources as $namespace => $dir) {
            foreach (glob($dir . '*/Module.php') ?: [] as $file) {
                $class = $namespace . basename(dirname($file)) . '\\Module';
                if (!class_exists($class)) {
                    continue;
                }
                $module = new $class();
                if ($module instanceof ModuleInterface) {
                    $this->modules[$module->id()] = $module;
                }
            }
        }

        // A stable order, whatever order the filesystem returned the folders in.
        ksort($this->modules);
    }

    /**
     * @return array<string, ModuleInterface>
     */
    public function all(): array {
        $this->discover();
        return $this->modules;
    }

    public function register_routes(): void {
        foreach ($this->all() as $module) {
            $module->register_routes();
        }
    }

    public function register_hooks(): void {
        foreach ($this->all() as $module) {
            $module->register_hooks();
        }
    }
}
