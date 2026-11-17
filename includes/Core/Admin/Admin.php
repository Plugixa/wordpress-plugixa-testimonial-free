<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core\Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * Admin Class - Handles admin menu and asset loading
 */
class Admin {

    /**
     * Hook suffixes returned by add_menu_page()/add_submenu_page().
     *
     * Assets are scoped against these rather than against a $_GET['page'] prefix
     * match: Freemius's auto-generated pages (pricing/account/contact) also start
     * with the 'plugixa-testimonial' slug, and must not load the React app.
     *
     * @var string[]
     */
    private array $hook_suffixes = [];

    public function add_admin_menu(): void {
        $icon = 'dashicons-format-quote';

        $this->hook_suffixes[] = add_menu_page(
            __('Plugixa Testimonial', 'plugixa-testimonial'),
            __('Testimonials', 'plugixa-testimonial'),
            'edit_posts',
            'plugixa-testimonial',
            [$this, 'render_app'],
            $icon,
            26
        );

        // Submenus (all point to the same page, React handles routing)
        $submenus = [
            'plugixa-testimonial'              => __('Dashboard', 'plugixa-testimonial'),
            'plugixa-testimonial-testimonials'  => __('Testimonials', 'plugixa-testimonial'),
            'plugixa-testimonial-categories'    => __('Categories', 'plugixa-testimonial'),
            'plugixa-testimonial-views'         => __('Views', 'plugixa-testimonial'),
            'plugixa-testimonial-shortcodes'    => __('Shortcodes', 'plugixa-testimonial'),
        ];

        foreach ($submenus as $slug => $title) {
            $this->hook_suffixes[] = add_submenu_page(
                'plugixa-testimonial',
                $title,
                $title,
                'edit_posts',
                $slug,
                [$this, 'render_app']
            );
        }

        // Settings submenu (requires manage_options)
        $this->hook_suffixes[] = add_submenu_page(
            'plugixa-testimonial',
            __('Settings', 'plugixa-testimonial'),
            __('Settings', 'plugixa-testimonial'),
            'manage_options',
            'plugixa-testimonial-settings',
            [$this, 'render_app']
        );

        $this->hook_suffixes = array_filter($this->hook_suffixes);
    }

    public function render_app(): void {
        $route = $this->get_current_route();
        $title = $this->get_page_title($route);

        echo '<div id="plugixatestimonial-root">';
        $this->render_skeleton($title);
        echo '</div>';
    }

    /**
     * Render a static skeleton placeholder that paints instantly with the HTML response.
     * React replaces this when it mounts (~3s later). This reduces LCP from ~6s to ~500ms.
     */
    private function render_skeleton(string $title): void {
        $tabs = [
            __('Dashboard', 'plugixa-testimonial'),
            __('Testimonials', 'plugixa-testimonial'),
            __('Categories', 'plugixa-testimonial'),
            __('Views', 'plugixa-testimonial'),
            __('Shortcodes', 'plugixa-testimonial'),
        ];

        // Settings is admin-only, as in the React nav.
        if (current_user_can('manage_options')) {
            $tabs[] = __('Settings', 'plugixa-testimonial');
        }

        // Outer wrapper
        echo '<div style="min-height:calc(100vh - 32px);background:#f0f0f1">';

        // Top nav bar
        echo '<div style="background:#fff;border-bottom:1px solid #c3c4c7;padding:0 24px;display:flex;align-items:center;height:48px">';
        echo '<span style="font-weight:600;font-size:1.1rem;color:#1d2327;margin-right:32px;white-space:nowrap">Plugixa Testimonial</span>';
        echo '<div style="display:flex;gap:4px;overflow:hidden">';
        foreach ($tabs as $tab) {
            $style = ($tab === $title)
                ? 'color:#2271b1;border-bottom:3px solid #2271b1'
                : 'color:#50575e';
            echo '<span style="' . esc_attr('padding:12px 16px;font-size:0.875rem;font-weight:500;white-space:nowrap;' . $style) . '">' . esc_html($tab) . '</span>';
        }
        echo '</div></div>';

        // Page content area
        echo '<div style="padding:24px;max-width:1200px;margin:0 auto">';

        // Title + action buttons row
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">';
        echo '<span style="font-size:1.125rem;font-weight:600;color:#1d2327">' . esc_html($title) . '</span>';
        echo '<div style="display:flex;gap:12px">';
        echo '<span style="background:#e0e0e0;border-radius:4px;width:100px;height:36px;display:inline-block"></span>';
        echo '<span style="background:#2271b1;border-radius:4px;width:140px;height:36px;display:inline-block"></span>';
        echo '</div></div>';

        // Table skeleton
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden">';
        echo '<table style="width:100%;border-collapse:collapse">';
        echo '<thead><tr style="background:#f6f7f7">';
        echo '<th style="padding:12px 16px;text-align:left;font-weight:600;font-size:0.875rem;border-bottom:1px solid #e0e0e0">' . esc_html__('Name', 'plugixa-testimonial') . '</th>';
        echo '<th style="padding:12px 16px;text-align:left;font-weight:600;font-size:0.875rem;border-bottom:1px solid #e0e0e0">' . esc_html__('Details', 'plugixa-testimonial') . '</th>';
        echo '<th style="padding:12px 16px;text-align:left;font-weight:600;font-size:0.875rem;border-bottom:1px solid #e0e0e0;width:120px">' . esc_html__('Status', 'plugixa-testimonial') . '</th>';
        echo '<th style="padding:12px 16px;text-align:left;font-weight:600;font-size:0.875rem;border-bottom:1px solid #e0e0e0;width:120px">' . esc_html__('Date', 'plugixa-testimonial') . '</th>';
        echo '</tr></thead><tbody>';

        // 5 skeleton rows with shimmer placeholder bars
        $widths = [[65, 80, 45, 60], [50, 70, 55, 60], [75, 60, 40, 60], [55, 75, 50, 60], [70, 65, 45, 60]];
        foreach ($widths as $w) {
            echo '<tr>';
            foreach ($w as $pct) {
                echo '<td style="padding:14px 16px"><div style="' . esc_attr('background:#e8e8e8;border-radius:4px;height:14px;width:' . (int) $pct . '%') . '"></div></td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</div></div>';
    }

    private function get_current_route(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; no state change.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'plugixa-testimonial';
        $routes = [
            'plugixa-testimonial'              => '/dashboard',
            'plugixa-testimonial-testimonials'  => '/testimonials',
            'plugixa-testimonial-categories'    => '/categories',
            'plugixa-testimonial-views'         => '/views',
            'plugixa-testimonial-shortcodes'    => '/shortcodes',
            'plugixa-testimonial-settings'      => '/settings',
        ];
        return $routes[$page] ?? '/dashboard';
    }

    private function get_page_title(string $route): string {
        $titles = [
            '/dashboard'     => __('Dashboard', 'plugixa-testimonial'),
            '/testimonials'  => __('Testimonials', 'plugixa-testimonial'),
            '/categories'    => __('Categories', 'plugixa-testimonial'),
            '/views'         => __('Views', 'plugixa-testimonial'),
            '/shortcodes'    => __('Shortcodes', 'plugixa-testimonial'),
            '/settings'      => __('Settings', 'plugixa-testimonial'),
        ];
        return $titles[$route] ?? __('Dashboard', 'plugixa-testimonial');
    }

    public function enqueue_assets(string $hook): void {
        // Only load on the plugin's own screens - not on Freemius's auto-generated
        // pages, whose slugs share the same prefix.
        if (!in_array($hook, $this->hook_suffixes, true)) {
            return;
        }

        $assets = new Assets(
            PLUGIXA_TESTIMONIAL_PATH,
            PLUGIXA_TESTIMONIAL_URL,
            PLUGIXA_TESTIMONIAL_VERSION
        );
        $assets->enqueue_app_assets();
    }
}
