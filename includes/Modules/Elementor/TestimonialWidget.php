<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Elementor;

if (!defined('ABSPATH')) { exit; }

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use PlugixaTestimonial\Modules\Frontend\Shortcodes;

/**
 * Elementor Testimonial Widget
 */
class TestimonialWidget extends Widget_Base {

    public function get_name(): string {
        return 'plugixa_testimonial';
    }

    public function get_title(): string {
        return __('Plugixa Testimonials', 'plugixa-testimonial');
    }

    public function get_icon(): string {
        return 'eicon-review';
    }

    public function get_categories(): array {
        return ['plugixa-testimonial', 'general'];
    }

    public function get_style_depends(): array {
        return [Shortcodes::SWIPER_HANDLE, Shortcodes::FRONTEND_HANDLE];
    }

    public function get_script_depends(): array {
        return [Shortcodes::SWIPER_HANDLE, Shortcodes::FRONTEND_HANDLE];
    }

    public function get_keywords(): array {
        return ['testimonial', 'review', 'rating', 'plugixa'];
    }

    protected function register_controls(): void {
        $this->register_content_controls();
        $this->register_style_controls();
    }

    private function register_content_controls(): void {
        $this->start_controls_section('section_content', [
            'label' => __('Content', 'plugixa-testimonial'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('config_source', [
            'label'   => __('Config Source', 'plugixa-testimonial'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'saved',
            'options' => [
                'saved'  => __('Saved Shortcode', 'plugixa-testimonial'),
                'custom' => __('Custom', 'plugixa-testimonial'),
            ],
        ]);

        // Saved shortcode dropdown
        $this->add_control('shortcode_id', [
            'label'     => __('Select Shortcode', 'plugixa-testimonial'),
            'type'      => Controls_Manager::SELECT2,
            'options'   => $this->get_shortcode_options(),
            'default'   => '',
            'condition' => ['config_source' => 'saved'],
        ]);

        // Custom config controls
        $this->add_control('view_id', [
            'label'     => __('View', 'plugixa-testimonial'),
            'type'      => Controls_Manager::SELECT2,
            'options'   => $this->get_view_options(),
            'default'   => '',
            'condition' => ['config_source' => 'custom'],
        ]);

        $this->add_control('categories', [
            'label'       => __('Categories', 'plugixa-testimonial'),
            'type'        => Controls_Manager::SELECT2,
            'options'     => $this->get_category_options(),
            'multiple'    => true,
            'default'     => [],
            'condition'   => ['config_source' => 'custom'],
        ]);

        $this->add_control('limit', [
            'label'     => __('Limit', 'plugixa-testimonial'),
            'type'      => Controls_Manager::NUMBER,
            'default'   => 0,
            'min'       => 0,
            'max'       => 100,
            'condition' => ['config_source' => 'custom'],
            'description' => __('0 = no limit', 'plugixa-testimonial'),
        ]);

        $this->add_control('orderby', [
            'label'     => __('Order By', 'plugixa-testimonial'),
            'type'      => Controls_Manager::SELECT,
            'default'   => 'display_order',
            'options'   => [
                'display_order' => __('Display Order', 'plugixa-testimonial'),
                'created_at'    => __('Date', 'plugixa-testimonial'),
                'rating'        => __('Rating', 'plugixa-testimonial'),
                'client_name'   => __('Name', 'plugixa-testimonial'),
            ],
            'condition' => ['config_source' => 'custom'],
        ]);

        $this->add_control('order', [
            'label'     => __('Order', 'plugixa-testimonial'),
            'type'      => Controls_Manager::SELECT,
            'default'   => 'ASC',
            'options'   => [
                'ASC'  => __('Ascending', 'plugixa-testimonial'),
                'DESC' => __('Descending', 'plugixa-testimonial'),
            ],
            'condition' => ['config_source' => 'custom'],
        ]);

        $this->add_control('featured_only', [
            'label'        => __('Featured Only', 'plugixa-testimonial'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('Yes', 'plugixa-testimonial'),
            'label_off'    => __('No', 'plugixa-testimonial'),
            'return_value' => '1',
            'default'      => '',
            'condition'    => ['config_source' => 'custom'],
        ]);

        $this->add_control('min_rating', [
            'label'     => __('Minimum Rating', 'plugixa-testimonial'),
            'type'      => Controls_Manager::SELECT,
            'default'   => '0',
            'options'   => [
                '0' => __('Any', 'plugixa-testimonial'),
                '1' => '1+',
                '2' => '2+',
                '3' => '3+',
                '4' => '4+',
                '5' => '5',
            ],
            'condition' => ['config_source' => 'custom'],
        ]);

        $this->end_controls_section();

        // Display Options section
        $this->start_controls_section('section_display', [
            'label'     => __('Display Options', 'plugixa-testimonial'),
            'tab'       => Controls_Manager::TAB_CONTENT,
            'condition' => ['config_source' => 'custom'],
        ]);

        $display_fields = [
            'show_photo'      => __('Show Photo', 'plugixa-testimonial'),
            'show_rating'     => __('Show Rating', 'plugixa-testimonial'),
            'show_role'       => __('Show Role', 'plugixa-testimonial'),
            'show_company'    => __('Show Company', 'plugixa-testimonial'),
            'show_date'       => __('Show Date', 'plugixa-testimonial'),
            'show_website'    => __('Show Website', 'plugixa-testimonial'),
            'show_quote_icon' => __('Show Quote Icon', 'plugixa-testimonial'),
        ];

        foreach ($display_fields as $key => $label) {
            $this->add_control($key, [
                'label'        => $label,
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'plugixa-testimonial'),
                'label_off'    => __('No', 'plugixa-testimonial'),
                'return_value' => '1',
                'default'      => '1',
            ]);
        }

        $this->end_controls_section();
    }

    private function register_style_controls(): void {
        $this->start_controls_section('section_style_card', [
            'label'     => __('Card', 'plugixa-testimonial'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['config_source' => 'custom'],
        ]);

        $this->add_control('card_bg', [
            'label'   => __('Card Background', 'plugixa-testimonial'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#ffffff',
        ]);

        $this->add_control('text_color', [
            'label'   => __('Text Color', 'plugixa-testimonial'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#1d2327',
        ]);

        $this->add_control('name_color', [
            'label'   => __('Name Color', 'plugixa-testimonial'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#1d2327',
        ]);

        $this->add_control('role_color', [
            'label'   => __('Role Color', 'plugixa-testimonial'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#646970',
        ]);

        $this->add_control('border_color', [
            'label'   => __('Border Color', 'plugixa-testimonial'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#e5e7eb',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_style_stars', [
            'label'     => __('Stars', 'plugixa-testimonial'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['config_source' => 'custom'],
        ]);

        $this->add_control('star_color', [
            'label'   => __('Star Color', 'plugixa-testimonial'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#f59e0b',
        ]);

        $this->add_control('star_empty_color', [
            'label'   => __('Empty Star Color', 'plugixa-testimonial'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#d1d5db',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_style_spacing', [
            'label'     => __('Spacing & Borders', 'plugixa-testimonial'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['config_source' => 'custom'],
        ]);

        $this->add_control('border_radius', [
            'label'   => __('Border Radius', 'plugixa-testimonial'),
            'type'    => Controls_Manager::SLIDER,
            'range'   => ['px' => ['min' => 0, 'max' => 50]],
            'default' => ['size' => 8, 'unit' => 'px'],
        ]);

        $this->add_control('card_padding', [
            'label'   => __('Card Padding', 'plugixa-testimonial'),
            'type'    => Controls_Manager::SLIDER,
            'range'   => ['px' => ['min' => 0, 'max' => 60]],
            'default' => ['size' => 24, 'unit' => 'px'],
        ]);

        $this->add_control('gap', [
            'label'   => __('Gap', 'plugixa-testimonial'),
            'type'    => Controls_Manager::SLIDER,
            'range'   => ['px' => ['min' => 0, 'max' => 60]],
            'default' => ['size' => 24, 'unit' => 'px'],
        ]);

        $this->add_control('border_width', [
            'label'   => __('Border Width', 'plugixa-testimonial'),
            'type'    => Controls_Manager::SLIDER,
            'range'   => ['px' => ['min' => 0, 'max' => 10]],
            'default' => ['size' => 1, 'unit' => 'px'],
        ]);

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        Shortcodes::enqueue_frontend_assets();

        if ($settings['config_source'] === 'saved' && !empty($settings['shortcode_id'])) {
            $atts = ['id' => (int) $settings['shortcode_id']];
        } else {
            $atts = [];
            if (!empty($settings['view_id'])) {
                $atts['view_id'] = (int) $settings['view_id'];
            }
            if (!empty($settings['categories']) && is_array($settings['categories'])) {
                $atts['category'] = implode(',', $settings['categories']);
            }
            if (!empty($settings['limit'])) {
                $atts['limit'] = (int) $settings['limit'];
            }
            if (!empty($settings['orderby'])) {
                $atts['orderby'] = $settings['orderby'];
            }
            if (!empty($settings['order'])) {
                $atts['order'] = $settings['order'];
            }
            if (!empty($settings['featured_only'])) {
                $atts['featured_only'] = '1';
            }
            if (!empty($settings['min_rating'])) {
                $atts['min_rating'] = (int) $settings['min_rating'];
            }

            // Pass display toggles when toggled off
            $display_keys = ['show_photo', 'show_rating', 'show_role', 'show_company', 'show_date', 'show_website', 'show_quote_icon'];
            foreach ($display_keys as $key) {
                if (isset($settings[$key]) && $settings[$key] !== '1') {
                    $atts[$key] = '0';
                }
            }
        }

        $shortcodes = new Shortcodes();
        echo wp_kses($shortcodes->render_testimonials($atts), \PlugixaTestimonial\Core\Kses::allowed_html());

        // Reinit JS in the Elementor editor preview, where the widget is re-rendered
        // without a page load. Emitted through the core inline-script API rather than a
        // literal <script> tag - the same reason the JSON-LD payload goes through it.
        if (\Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode()) {
            wp_print_inline_script_tag('if(window.PlugixaTestimonialApp){window.PlugixaTestimonialApp.init();}');
        }
    }

    protected function content_template(): void {
        // Dynamic widget — server-side render only
    }

    private function get_shortcode_options(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixatestimonial_shortcodes';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM %i ORDER BY name ASC", $table), ARRAY_A);
        $options = ['' => __('Select a shortcode...', 'plugixa-testimonial')];
        foreach ($rows ?: [] as $row) {
            $options[$row['id']] = $row['name'];
        }
        return $options;
    }

    private function get_view_options(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixatestimonial_views';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM %i ORDER BY name ASC", $table), ARRAY_A);
        $options = ['' => __('Default View', 'plugixa-testimonial')];
        foreach ($rows ?: [] as $row) {
            $options[$row['id']] = $row['name'];
        }
        return $options;
    }

    private function get_category_options(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixatestimonial_categories';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM %i ORDER BY name ASC", $table), ARRAY_A);
        $options = [];
        foreach ($rows ?: [] as $row) {
            $options[$row['id']] = $row['name'];
        }
        return $options;
    }
}
