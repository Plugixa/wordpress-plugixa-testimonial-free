<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\Requests\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaTestimonial\Core\Mail\EmailNotifier;

/**
 * Requests REST Controller - CRUD + send email for testimonial requests
 */
class RequestsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'requests';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_list'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_request'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_single'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_request'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/resend', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'resend_request'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'bulk_send'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-delete', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'bulk_delete'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        // Public by design: the recipient of a testimonial-request email follows a link
        // carrying a single-use token, and the form calls this to pre-fill their name
        // and email. The token IS the credential - the recipient is anonymous and has
        // no account, so there is no capability to check. The token is 64 hex chars
        // generated with wp_generate_password(); anything not matching that shape is
        // rejected before the query runs, and the response carries only the two
        // pre-fill fields, never the row.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/validate-token', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'validate_token'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'token' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static function ($value) {
                            return is_string($value) && preg_match('/^[A-Za-z0-9]{16,64}$/', $value) === 1;
                        },
                    ],
                ],
            ],
        ]);
    }

    /**
     * Get paginated list of requests
     */
    public function get_list(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request);

        // Bound gates inside one constant-literal WHERE; gate 0 disables its test.
        $status = $request->get_param('status');
        $status_gate = ($status && in_array($status, ['pending', 'submitted', 'expired'], true)) ? 1 : 0;
        $status_val  = $status_gate ? $status : '';

        $search = $request->get_param('search');
        $search_gate = $search ? 1 : 0;
        $like        = $search_gate ? '%' . $wpdb->esc_like($search) . '%' : '%';

        $where_values = [$status_gate, $status_val, $search_gate, $like, $like];

        // Count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE 1=1
               AND ( %d = 0 OR status = %s )
               AND ( %d = 0 OR ( recipient_email LIKE %s OR recipient_name LIKE %s ) )",
            $table,
            ...$where_values
        ));

        // Allowed orderby columns
        $allowed_orderby = ['created_at', 'recipient_email', 'recipient_name', 'status', 'sent_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby, true) ? $sort['orderby'] : 'created_at';
        $order = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // $orderby is allowlisted above and bound as an identifier with %i, but a sort
        // DIRECTION cannot be a placeholder -- so ASC and DESC are separate literals.
        if ($order === 'ASC') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i
                 WHERE 1=1
                   AND ( %d = 0 OR status = %s )
                   AND ( %d = 0 OR ( recipient_email LIKE %s OR recipient_name LIKE %s ) )
                 ORDER BY %i ASC
                 LIMIT %d OFFSET %d",
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A) ?: [];
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(); the replacement count is passed by spread, which the sniff cannot resolve statically. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i
                 WHERE 1=1
                   AND ( %d = 0 OR status = %s )
                   AND ( %d = 0 OR ( recipient_email LIKE %s OR recipient_name LIKE %s ) )
                 ORDER BY %i DESC
                 LIMIT %d OFFSET %d",
                $table,
                ...array_merge($where_values, [$orderby, $pagination['limit'], $pagination['offset']])
            ), ARRAY_A) ?: [];
        }

        // Auto-expire old pending requests
        $this->auto_expire_requests();

        return $this->success([
            'items' => $items,
            'total' => $total,
        ]);
    }

    /**
     * Get single request
     */
    public function get_single(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        $id = (int) $request->get_param('id');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$item) {
            return $this->error(__('Request not found.', 'plugixa-testimonial'), 404);
        }

        return $this->success($item);
    }

    /**
     * Create and send a new request
     */
    public function create_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        $settings = get_option('plugixatestimonial_settings', []);

        $email = sanitize_email($request->get_param('recipient_email') ?? '');
        $name = sanitize_text_field($request->get_param('recipient_name') ?? '');
        $subject = sanitize_text_field($request->get_param('subject') ?? '');
        $message = sanitize_textarea_field($request->get_param('message') ?? '');

        if (empty($email) || !is_email($email)) {
            return $this->error(__('A valid email address is required.', 'plugixa-testimonial'), 400);
        }

        // Use defaults from settings if not provided
        if (empty($subject)) {
            $subject = $settings['request_subject'] ?? 'We\'d love your feedback!';
        }
        if (empty($message)) {
            $message = $settings['request_message'] ?? '';
        }

        $token = wp_generate_password(48, false, false);
        $expiry_days = (int) ($settings['request_expiry_days'] ?? 30);
        $now = current_time('mysql');

        $data = [
            'recipient_email' => $email,
            'recipient_name'  => $name,
            'subject'         => $subject,
            'message'         => $message,
            'token'           => $token,
            'status'          => 'pending',
            'expires_at'      => gmdate('Y-m-d H:i:s', strtotime("+{$expiry_days} days")),
            'created_by'      => get_current_user_id(),
            'created_at'      => $now,
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            return $this->error(__('Failed to create request.', 'plugixa-testimonial'), 500);
        }

        $request_id = $wpdb->insert_id;

        // Send email
        $sent = $this->send_request_email($request_id, $data, $settings);

        if ($sent) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->update($table, ['sent_at' => current_time('mysql')], ['id' => $request_id]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $request_id),
            ARRAY_A
        );

        return $this->success($item, $sent
            ? __('Request sent successfully.', 'plugixa-testimonial')
            : __('Request created but email could not be sent.', 'plugixa-testimonial')
        );
    }

    /**
     * Resend an existing request
     */
    public function resend_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        $id = (int) $request->get_param('id');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        if (!$item) {
            return $this->error(__('Request not found.', 'plugixa-testimonial'), 404);
        }

        if ($item['status'] === 'submitted') {
            return $this->error(__('This request has already been submitted.', 'plugixa-testimonial'), 400);
        }

        $settings = get_option('plugixatestimonial_settings', []);

        // Refresh expiry
        $expiry_days = (int) ($settings['request_expiry_days'] ?? 30);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->update($table, [
            'status'     => 'pending',
            'expires_at' => gmdate('Y-m-d H:i:s', strtotime("+{$expiry_days} days")),
        ], ['id' => $id]);

        $sent = $this->send_request_email($id, $item, $settings);

        if ($sent) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->update($table, ['sent_at' => current_time('mysql')], ['id' => $id]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $updated = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id),
            ARRAY_A
        );

        return $this->success($updated, $sent
            ? __('Request resent successfully.', 'plugixa-testimonial')
            : __('Email could not be sent.', 'plugixa-testimonial')
        );
    }

    /**
     * Bulk send requests to multiple recipients
     */
    public function bulk_send(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $recipients = $request->get_param('recipients');
        if (!is_array($recipients) || empty($recipients)) {
            return $this->error(__('Recipients list is required.', 'plugixa-testimonial'), 400);
        }

        $subject = sanitize_text_field($request->get_param('subject') ?? '');
        $message = sanitize_textarea_field($request->get_param('message') ?? '');

        $sent_count = 0;
        $failed_count = 0;

        foreach ($recipients as $recipient) {
            $email = sanitize_email($recipient['email'] ?? '');
            $name = sanitize_text_field($recipient['name'] ?? '');

            if (empty($email) || !is_email($email)) {
                $failed_count++;
                continue;
            }

            // Create a fake WP_REST_Request to reuse create logic
            $sub_request = new WP_REST_Request('POST');
            $sub_request->set_param('recipient_email', $email);
            $sub_request->set_param('recipient_name', $name);
            $sub_request->set_param('subject', $subject);
            $sub_request->set_param('message', $message);

            $result = $this->create_request($sub_request);
            if (is_wp_error($result)) {
                $failed_count++;
            } else {
                $sent_count++;
            }
        }

        return $this->success([
            'sent'   => $sent_count,
            'failed' => $failed_count,
        ],
            /* translators: 1: number of requests sent, 2: number that failed */
            sprintf(__('%1$d requests sent, %2$d failed.', 'plugixa-testimonial'), $sent_count, $failed_count));
    }

    /**
     * Delete a single request
     */
    public function delete_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        $id = (int) $request->get_param('id');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->delete($table, ['id' => $id]);
        if ($result === false) {
            return $this->error(__('Failed to delete request.', 'plugixa-testimonial'), 500);
        }

        return $this->success(null, __('Request deleted.', 'plugixa-testimonial'));
    }

    /**
     * Bulk delete requests
     */
    public function bulk_delete(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        $ids = $request->get_param('ids');

        if (!is_array($ids) || empty($ids)) {
            return $this->error(__('IDs are required.', 'plugixa-testimonial'), 400);
        }

        $ids = array_map('intval', $ids);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). This is a write; Cache::register() flushes the group on the REST write lifecycle.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE FIND_IN_SET(id, %s)", $table, implode(',', $ids)));

        return $this->success(null, __('Requests deleted.', 'plugixa-testimonial'));
    }

    /**
     * Validate a request token (public endpoint)
     */
    public function validate_token(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        $token = sanitize_text_field($request->get_param('token'));

        // Shape check before touching the database, so a probe with a malformed token
        // costs nothing. validate_callback already enforces this; keep it here too in
        // case the method is ever reached by another caller.
        if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
            return $this->error(__('Invalid or expired request.', 'plugixa-testimonial'), 404);
        }

        // Explicit columns: this is an unauthenticated route, so the row's subject and
        // message body have no business leaving the server.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; core has no API for it. Reads are served through Cache::remember() and invalidated by Cache::flush() on write (see includes/class-cache.php).
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT id, recipient_name, recipient_email, token, status, expires_at FROM %i WHERE token = %s",
            $table,
            $token
        ), ARRAY_A);

        if (!$item) {
            return $this->error(__('Invalid or expired request.', 'plugixa-testimonial'), 404);
        }

        if ($item['status'] === 'submitted') {
            return $this->error(__('This testimonial has already been submitted.', 'plugixa-testimonial'), 410);
        }

        if ($item['status'] === 'expired' || (
            !empty($item['expires_at']) && strtotime($item['expires_at']) < time()
        )) {
            // Mark as expired if not already
            if ($item['status'] !== 'expired') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->update($table, ['status' => 'expired'], ['id' => $item['id']]);
            }
            return $this->error(__('This request has expired.', 'plugixa-testimonial'), 410);
        }

        return $this->success([
            'recipient_name'  => $item['recipient_name'] ?? '',
            'recipient_email' => $item['recipient_email'] ?? '',
            'token'           => $item['token'],
        ]);
    }

    /**
     * Send the request email using wp_mail
     */
    private function send_request_email(int $request_id, array $data, array $settings): bool {
        $site_name = get_bloginfo('name');
        $from_name = !empty($settings['request_from_name']) ? $settings['request_from_name'] : $site_name;
        $from_email = !empty($settings['request_from_email']) ? $settings['request_from_email'] : get_option('admin_email');

        // Build submission link
        $submission_url = $this->get_submission_url($data['token']);

        // Process message template
        $message_body = $data['message'] ?? ($settings['request_message'] ?? '');
        $message_body = str_replace(
            ['{recipient_name}', '{site_name}', '{submission_link}'],
            [$data['recipient_name'] ?: __('Customer', 'plugixa-testimonial'), $site_name, $submission_url],
            $message_body
        );

        // Build HTML email
        $html = $this->build_email_html($data['subject'] ?? '', $message_body, $submission_url, $site_name);

        $headers = [
            "Content-Type: text/html; charset=UTF-8",
            "From: {$from_name} <{$from_email}>",
        ];

        return wp_mail(
            $data['recipient_email'],
            $data['subject'] ?? __('We\'d love your feedback!', 'plugixa-testimonial'),
            $html,
            $headers
        );
    }

    /**
     * Get the submission form URL with token
     */
    private function get_submission_url(string $token): string {
        $settings = get_option('plugixatestimonial_settings', []);
        $page_id = (int) ($settings['request_submission_page_id'] ?? 0);

        if ($page_id > 0) {
            $url = get_permalink($page_id);
        } else {
            $url = home_url('/');
        }

        return add_query_arg('pxt_request_token', $token, $url);
    }

    /**
     * Build HTML email body with inline styles — delegates to shared EmailNotifier.
     */
    private function build_email_html(string $subject, string $message_body, string $submission_url, string $site_name): string {
        $message_html = nl2br(esc_html($message_body));

        // Replace the plain-text URL in the message with a styled button
        $button_html = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 24px auto;"><tr><td style="border-radius: 6px; background-color: #4f46e5;"><a href="' . esc_url($submission_url) . '" target="_blank" style="display: inline-block; padding: 14px 32px; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 6px;">' . esc_html__('Leave Your Testimonial', 'plugixa-testimonial') . '</a></td></tr></table>';

        // Replace the submission_link placeholder in the HTML
        $message_html = str_replace(esc_html($submission_url), $button_html, $message_html);

        return EmailNotifier::build_email_html($message_html, $site_name);
    }

    /**
     * Auto-expire pending requests that have passed their expiry date
     */
    private function auto_expire_requests(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->query(
            $wpdb->prepare("UPDATE %i SET status = 'expired' WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < %s", $table,
                current_time('mysql', true)
            )
        );
    }

    /**
     * Mark a request as submitted (called from FrontendApi when a testimonial is submitted with a token)
     */
    public static function mark_submitted(string $token, int $testimonial_id): void {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_requests';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->update($table, [
            'status'         => 'submitted',
            'testimonial_id' => $testimonial_id,
            'submitted_at'   => current_time('mysql'),
        ], ['token' => $token]);
    }
}
