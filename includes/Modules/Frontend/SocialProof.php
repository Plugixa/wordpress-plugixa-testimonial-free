<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Frontend;

if (!defined('ABSPATH')) { exit; }

/**
 * Social Proof Notification Popups
 *
 * Shows small notification popups on frontend pages displaying recent testimonials.
 * Loads testimonials via REST API and cycles through them with configurable timing.
 */
class SocialProof {

    private array $settings;

    public function __construct() {
        $this->settings = get_option('plugixatestimonial_settings', []);
    }

    public function init(): void {
        if (empty($this->settings['sp_enabled'])) {
            return;
        }

        // Don't load in admin
        if (is_admin()) {
            return;
        }

        add_action('wp_footer', [$this, 'render_popup_container']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void {
        $css_file = PLUGIXA_TESTIMONIAL_PATH . 'assets/frontend/css/social-proof.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'plugixa-social-proof',
                PLUGIXA_TESTIMONIAL_URL . 'assets/frontend/css/social-proof.css',
                [],
                PLUGIXA_TESTIMONIAL_VERSION
            );
        }

        $js_file = PLUGIXA_TESTIMONIAL_PATH . 'assets/frontend/js/social-proof.js';
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'plugixa-social-proof',
                PLUGIXA_TESTIMONIAL_URL . 'assets/frontend/js/social-proof.js',
                [],
                PLUGIXA_TESTIMONIAL_VERSION,
                true
            );

            wp_localize_script('plugixa-social-proof', 'plugixaTestimonialSocialProof', [
                'restUrl'         => esc_url_raw(rest_url('plugixatestimonial/v1/frontend/social-proof')),
                'nonce'           => Shortcodes::public_rest_nonce(),
                'position'        => $this->settings['sp_position'] ?? 'bottom-left',
                'displayDuration' => (int) ($this->settings['sp_display_duration'] ?? 5),
                'delayBetween'    => (int) ($this->settings['sp_delay_between'] ?? 10),
                'initialDelay'    => (int) ($this->settings['sp_initial_delay'] ?? 5),
                'maxPopups'       => (int) ($this->settings['sp_max_popups'] ?? 5),
                'showPhoto'       => !empty($this->settings['sp_show_photo']),
                'showRating'      => !empty($this->settings['sp_show_rating']),
                'showTimeAgo'     => !empty($this->settings['sp_show_time_ago']),
                'bgColor'         => $this->settings['sp_bg_color'] ?? '#ffffff',
                'textColor'       => $this->settings['sp_text_color'] ?? '#1d2327',
                'borderColor'     => $this->settings['sp_border_color'] ?? '#e0e0e0',
                'hideOnMobile'    => !empty($this->settings['sp_hide_on_mobile']),
            ]);
        }
    }

    public function render_popup_container(): void {
        echo '<div id="pxt-social-proof-container" aria-live="polite" aria-atomic="true"></div>';
    }
}
