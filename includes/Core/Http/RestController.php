<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core\Http;

if (!defined('ABSPATH')) { exit; }

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Base REST Controller
 */
abstract class RestController extends WP_REST_Controller {

    public function __construct() {
        $this->namespace = 'plugixatestimonial/v1';
    }


    /**
     * Custom capability first, core capability as the fallback.
     *
     * The five plugixa_testimonial_* capabilities are granted to administrator by
     * Activator::add_capabilities(), and a site owner can grant them to a custom role with
     * any role editor. The core fallback is what keeps an untouched install working: it
     * maps each gate onto the nearest core capability with the same blast radius, so
     * Editors and Administrators keep every ability they had before this existed.
     *
     * The gates are deliberately NOT all the same. Before this split, every write route -
     * including DELETE and the approval switch - sat behind edit_posts, which Contributors
     * hold. The capabilities existed and were shipped to the React admin, but PHP never
     * checked them, so authorisation was effectively client-side only.
     */
    protected function can(string $cap, string $core): bool {
        return current_user_can($cap) || current_user_can($core);
    }

    private function forbid(string $message) {
        return new WP_Error('rest_forbidden', $message, ['status' => 403]);
    }

    /** Edit-level: create, read and update content. Contributor and up, via edit_posts. */
    public function check_permission($request) {
        return $this->can('plugixa_testimonial_edit', 'edit_posts')
            ? true
            : $this->forbid(__('You do not have permission to perform this action.', 'plugixa-testimonial'));
    }

    /** Delete-level: destroys rows. Editor and up, via delete_others_posts. */
    public function check_delete_permission($request) {
        return $this->can('plugixa_testimonial_delete', 'delete_others_posts')
            ? true
            : $this->forbid(__('You do not have permission to delete this item.', 'plugixa-testimonial'));
    }

    /** Approve-level: publishes a testimonial to the public frontend. Author and up, via publish_posts. */
    public function check_approve_permission($request) {
        return $this->can('plugixa_testimonial_approve', 'publish_posts')
            ? true
            : $this->forbid(__('You do not have permission to change approval status.', 'plugixa-testimonial'));
    }

    /**
     * Admin-level: API secrets, recipient PII, mail sending and bulk export.
     *
     * Intentionally manage_options ONLY - no custom-capability fallback. Adding one would
     * widen this gate rather than narrow it, turning a single grantable capability into
     * access to the reCAPTCHA secret, the AI provider and Google Places keys, the full
     * recipient email list, and the demo-data reset that truncates every plugin table.
     */
    public function check_admin_permission($request) {
        return current_user_can('manage_options')
            ? true
            : $this->forbid(__('Administrator access required.', 'plugixa-testimonial'));
    }

    protected function success($data, $message = '') {
        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], 200);
    }

    protected function error($message, $status = 400, $errors = []) {
        return new WP_Error(
            'rest_error',
            $message,
            [
                'status' => $status,
                'errors' => $errors,
            ]
        );
    }

    protected function get_pagination($request) {
        $limit = $request->get_param('limit') ?? 20;
        // -1 means "no limit" — use a large number for the SQL LIMIT clause
        if ((int) $limit <= 0) {
            $limit = 999999;
        }
        return [
            'limit'  => (int) $limit,
            'offset' => (int) ($request->get_param('offset') ?? 0),
        ];
    }

    protected function get_filters($request) {
        $filters = $request->get_param('filter');

        if (!is_array($filters)) {
            $filters = [];
        }

        if (empty($filters['search']) && $request->get_param('search')) {
            $filters['search'] = $request->get_param('search');
        }
        if (empty($filters['status']) && $request->get_param('status')) {
            $filters['status'] = $request->get_param('status');
        }
        if (empty($filters['category_id']) && $request->get_param('category_id')) {
            $filters['category_id'] = $request->get_param('category_id');
        }

        return $filters;
    }

    protected function get_sort($request) {
        return [
            'orderby' => $request->get_param('orderby') ?? 'created_at',
            'order'   => strtoupper($request->get_param('order') ?? 'DESC'),
        ];
    }

    public function get_collection_params() {
        return [
            'limit' => [
                'type'              => 'integer',
                'default'           => 20,
                'minimum'           => -1,
                'sanitize_callback' => function($value) {
                    return (int) $value;
                },
            ],
            'offset' => [
                'type'              => 'integer',
                'default'           => 0,
                'minimum'           => 0,
                'sanitize_callback' => 'absint',
            ],
            'orderby' => [
                'type'    => 'string',
                'default' => 'created_at',
            ],
            'order' => [
                'type'    => 'string',
                'default' => 'DESC',
                'enum'    => ['ASC', 'DESC'],
            ],
            'filter' => [
                'type'    => 'object',
                'default' => [],
            ],
            'lang' => [
                'type'    => 'string',
                'default' => '',
            ],
            'group_by_translation' => [
                'type'    => 'string',
                'default' => '',
            ],
        ];
    }
}
