<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\WooCommerce\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;

/**
 * WooCommerce Content REST Controller - Products and Product Categories
 */
class WoocommerceContentController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'woocommerce';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/products', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_products'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/categories', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_categories'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);
    }

    public function get_products(WP_REST_Request $request) {
        if (!class_exists('WooCommerce')) {
            return $this->error(__('WooCommerce is not active.', 'plugixa-testimonial'), 400);
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => (int) ($request->get_param('limit') ?? 20),
            'offset'         => (int) ($request->get_param('offset') ?? 0),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        $search = $request->get_param('search');
        if (!empty($search)) {
            $args['s'] = sanitize_text_field($search);
        }

        $query = new \WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $image_id = get_post_thumbnail_id($post->ID);
            $products[] = [
                'id'          => $post->ID,
                'name'        => $post->post_title,
                'description' => wp_trim_words($post->post_excerpt ?: $post->post_content, 20),
                'image'       => $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '',
            ];
        }

        return $this->success([
            'items' => $products,
            'total' => $query->found_posts,
        ]);
    }

    public function get_categories(WP_REST_Request $request) {
        if (!class_exists('WooCommerce')) {
            return $this->error(__('WooCommerce is not active.', 'plugixa-testimonial'), 400);
        }

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $categories = [];
        foreach ($terms ?: [] as $term) {
            $categories[] = [
                'id'    => $term->term_id,
                'name'  => $term->name,
                'slug'  => $term->slug,
                'count' => $term->count,
            ];
        }

        return $this->success($categories);
    }
}
