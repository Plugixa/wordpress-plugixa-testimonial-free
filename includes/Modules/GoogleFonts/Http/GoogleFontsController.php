<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\GoogleFonts\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use PlugixaTestimonial\Modules\GoogleFonts\FontManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Google Fonts REST Controller - Proxy to Google Fonts API
 */
class GoogleFontsController extends RestController {

    private const TRANSIENT_KEY = 'plugixatestimonial_google_fonts';
    private const CACHE_DURATION = 7 * DAY_IN_SECONDS;

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'google-fonts';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_fonts'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        // Self-hosted stylesheet URL for a single family, used by the admin live
        // preview. wp.org forbids off-site assets, so the admin never links to
        // Google's font CDN: FontManager downloads the family server-side and we
        // serve it from wp-content/uploads instead.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/css', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_font_css'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'family' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Resolve a family to a locally-hosted stylesheet URL, downloading it on first
     * use. Returns url => null for families that cannot be self-hosted (system
     * fonts, no woff2, network error) so the admin can fall back to the UI font.
     */
    public function get_font_css($request) {
        $family = (string) $request->get_param('family');

        if ($family === '' || $family === 'inherit') {
            return $this->success(['family' => $family, 'url' => null]);
        }

        return $this->success([
            'family' => $family,
            'url'    => FontManager::localize($family),
        ]);
    }

    public function get_fonts($request) {
        // Check for cached response
        $cached = get_transient(self::TRANSIENT_KEY);
        if ($cached !== false) {
            return $this->success($cached);
        }

        // Get API key from settings
        $settings = get_option('plugixatestimonial_settings', []);
        $api_key = $settings['google_fonts_api_key'] ?? '';

        if (empty($api_key)) {
            return $this->error(
                __('Google Fonts API key is not configured. Please add it in Settings > General.', 'plugixa-testimonial'),
                400
            );
        }

        // Fetch from Google Fonts API
        $response = wp_remote_get(
            'https://www.googleapis.com/webfonts/v1/webfonts?key=' . rawurlencode($api_key) . '&sort=popularity',
            ['timeout' => 15]
        );

        if (is_wp_error($response)) {
            return $this->error(
                __('Failed to fetch Google Fonts: ', 'plugixa-testimonial') . $response->get_error_message(),
                502
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $message = $body['error']['message'] ?? __('Google Fonts API returned an error.', 'plugixa-testimonial');
            return $this->error($message, $status_code);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $items = $body['items'] ?? [];

        // Simplify the response
        $fonts = array_map(function ($item) {
            return [
                'family'   => $item['family'],
                'category' => $item['category'],
                'variants' => $item['variants'],
            ];
        }, $items);

        // Cache for 7 days
        set_transient(self::TRANSIENT_KEY, $fonts, self::CACHE_DURATION);

        return $this->success($fonts);
    }
}
