<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core\Mail;

if (!defined('ABSPATH')) { exit; }

/**
 * Shared email notification class for admin alerts on new testimonial submissions.
 */
class EmailNotifier {

    /**
     * Send notification email to admin(s) when a new testimonial is submitted.
     */
    public static function send_new_submission_notification(int $testimonial_id, array $data): void {
        $settings = get_option('plugixatestimonial_settings', []);

        if (empty($settings['notification_enabled'])) {
            return;
        }

        $recipients = self::get_recipients($settings);
        if (empty($recipients)) {
            return;
        }

        $site_name = get_bloginfo('name');
        $admin_url = admin_url('admin.php?page=plugixa-testimonial#/testimonials');

        $vars = [
            '{client_name}'      => $data['client_name'] ?? __('Anonymous', 'plugixa-testimonial'),
            '{client_role}'      => $data['client_role'] ?? '',
            '{client_company}'   => $data['client_company'] ?? '',
            '{testimonial_text}' => mb_strimwidth($data['testimonial_text'] ?? '', 0, 200, '...'),
            '{rating}'           => $data['rating'] ?? '0',
            '{site_name}'        => $site_name,
            '{admin_url}'        => $admin_url,
        ];

        $subject_template = $settings['notification_subject']
            ?? __('New testimonial from {client_name}', 'plugixa-testimonial');
        $subject = self::replace_template_vars($subject_template, $vars);

        $message_template = $settings['notification_message'] ?? self::get_default_message();
        $message_body = self::replace_template_vars($message_template, $vars);

        // Build HTML version with a "Review" button
        $message_html = nl2br(esc_html($message_body));
        $button_html = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 24px auto;"><tr><td style="border-radius: 6px; background-color: #4f46e5;"><a href="' . esc_url($admin_url) . '" target="_blank" style="display: inline-block; padding: 14px 32px; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 6px;">' . esc_html__('Review Testimonial', 'plugixa-testimonial') . '</a></td></tr></table>';
        $message_html = str_replace(esc_html($admin_url), $button_html, $message_html);

        $html = self::build_email_html($message_html, $site_name);

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($recipients, $subject, $html, $headers);
    }

    /**
     * Build the indigo-branded HTML email wrapper.
     * Reusable by RequestsController and other senders.
     */
    public static function build_email_html(string $body_html, string $site_name): string {
        return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f3f4f6;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f3f4f6;">
<tr><td style="padding: 40px 20px;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
<!-- Header -->
<tr><td style="background-color: #4f46e5; padding: 24px 32px; text-align: center;">
<h1 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 600;">' . esc_html($site_name) . '</h1>
</td></tr>
<!-- Body -->
<tr><td style="padding: 32px;">
<div style="font-size: 15px; line-height: 1.7; color: #374151;">' . $body_html . '</div>
</td></tr>
<!-- Footer -->
<tr><td style="padding: 16px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb; text-align: center;">
<p style="margin: 0; font-size: 12px; color: #9ca3af;">' . esc_html(sprintf(/* translators: %s: site name */ __('Sent by %s', 'plugixa-testimonial'), $site_name)) . '</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
    }

    /**
     * Replace template variables in a string.
     */
    public static function replace_template_vars(string $template, array $vars): string {
        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    /**
     * Default notification message template.
     */
    public static function get_default_message(): string {
        return "Hi,\n\nA new testimonial has been submitted on {site_name}.\n\nFrom: {client_name}\nRating: {rating}/5\n\n\"{testimonial_text}\"\n\nReview it here: {admin_url}";
    }

    /**
     * Parse recipients from settings; fall back to admin email.
     */
    private static function get_recipients(array $settings): array {
        $raw = $settings['notification_recipients'] ?? '';

        if (empty(trim($raw))) {
            return [get_option('admin_email')];
        }

        $emails = array_map('trim', explode(',', $raw));
        return array_filter($emails, 'is_email');
    }
}
