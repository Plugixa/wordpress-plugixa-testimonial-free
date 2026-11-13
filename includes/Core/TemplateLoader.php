<?php
/**
 * Template Loader
 *
 * Locates frontend templates with a four-step lookup:
 *   1. Child theme override
 *   2. Parent theme override
 *   3. Pro plugin templates (stripped from free build via @fs_premium_only /pro/)
 *   4. Free plugin templates
 *
 * @package PlugixaTestimonial
 *
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core;

if (!defined('ABSPATH')) {
    exit;
}

class TemplateLoader {

    const THEME_DIR = 'plugixa-testimonial';

    const PLUGIN_DIR = 'templates';

    const PRO_PLUGIN_DIR = 'pro/templates/frontend';

    public static function locate(string $template): string {
        $template_name = $template . '.php';
        $located = '';

        $child_theme_path = get_stylesheet_directory() . '/' . self::THEME_DIR . '/' . $template_name;
        if (file_exists($child_theme_path)) {
            $located = $child_theme_path;
        }

        if (empty($located) && get_stylesheet_directory() !== get_template_directory()) {
            $parent_theme_path = get_template_directory() . '/' . self::THEME_DIR . '/' . $template_name;
            if (file_exists($parent_theme_path)) {
                $located = $parent_theme_path;
            }
        }

        if (empty($located)) {
            $pro_path = PLUGIXA_TESTIMONIAL_PATH . self::PRO_PLUGIN_DIR . '/' . $template_name;
            if (file_exists($pro_path)) {
                $located = $pro_path;
            }
        }

        if (empty($located)) {
            $plugin_path = PLUGIXA_TESTIMONIAL_PATH . self::PLUGIN_DIR . '/' . $template_name;
            if (file_exists($plugin_path)) {
                $located = $plugin_path;
            }
        }

        return apply_filters('plugixa_testimonial_template_path', $located, $template, $template_name);
    }

    public static function get_template_list(): array {
        $templates = [
            'grid'            => __('Grid layout', 'plugixa-testimonial'),
            'slider'          => __('Slider/carousel layout', 'plugixa-testimonial'),
            'masonry'         => __('Masonry layout', 'plugixa-testimonial'),
            'list'            => __('Vertical list layout', 'plugixa-testimonial'),
            'submission-form' => __('Frontend submission form', 'plugixa-testimonial'),
        ];

        if (Edition::is_premium()) {
            $templates['video-card']         = __('Video testimonial card (Pro)', 'plugixa-testimonial');
            $templates['custom-fields-row']  = __('Custom fields row partial (Pro)', 'plugixa-testimonial');
        }

        return $templates;
    }

    public static function get_template_override_info(string $template): array|false {
        $template_name = $template . '.php';

        $child_path = get_stylesheet_directory() . '/' . self::THEME_DIR . '/' . $template_name;
        if (file_exists($child_path)) {
            return [
                'source' => 'child_theme',
                'theme'  => wp_get_theme()->get('Name'),
                'path'   => $child_path,
            ];
        }

        if (get_stylesheet_directory() !== get_template_directory()) {
            $parent_path = get_template_directory() . '/' . self::THEME_DIR . '/' . $template_name;
            if (file_exists($parent_path)) {
                $parent_theme = wp_get_theme()->parent();
                return [
                    'source' => 'parent_theme',
                    'theme'  => $parent_theme ? $parent_theme->get('Name') : __('Parent Theme', 'plugixa-testimonial'),
                    'path'   => $parent_path,
                ];
            }
        }

        return false;
    }

    public static function get_theme_override_path(string $template): string {
        return get_stylesheet_directory() . '/' . self::THEME_DIR . '/' . $template . '.php';
    }
}
