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
 * Schema Class - Generates Review + AggregateRating JSON-LD for SEO
 */
class Schema {

    /**
     * Build the Review + AggregateRating JSON-LD payload.
     *
     * Returns bare JSON with no <script> wrapper: callers emit it through
     * wp_print_inline_script_tag(), so the tag itself goes through core APIs rather
     * than being concatenated into shortcode markup.
     *
     * @param array $testimonials Array of testimonial items
     * @return string JSON string, or '' when schema is disabled or there are none
     */
    public function get_testimonial_schema_json(array $testimonials): string {
        $settings = get_option('plugixatestimonial_settings', []);

        if (empty($settings['enable_schema']) || empty($testimonials)) {
            return '';
        }

        // Every string that lands in the graph is tag-stripped, not just the body.
        // A raw client name is as much an injection vector as a raw review is.
        $schema_type = $this->strip_content_for_schema($settings['schema_type'] ?? 'LocalBusiness');
        $schema_name = $this->strip_content_for_schema(
            !empty($settings['schema_name']) ? $settings['schema_name'] : get_bloginfo('name')
        );

        // Compute aggregate rating
        $total_ratings = 0;
        $rating_sum = 0;
        $reviews = [];

        foreach ($testimonials as $testimonial) {
            $rating = (int) ($testimonial['rating'] ?? 0);
            if ($rating > 0) {
                $total_ratings++;
                $rating_sum += $rating;
            }

            $review = [
                '@type'        => 'Review',
                'author'       => [
                    '@type' => 'Person',
                    'name'  => $this->strip_content_for_schema($testimonial['client_name'] ?? ''),
                ],
                'reviewBody'   => $this->strip_content_for_schema($testimonial['testimonial_text'] ?? ''),
            ];

            if ($rating > 0) {
                $review['reviewRating'] = [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) $rating,
                    'bestRating'  => '5',
                ];
            }

            if (!empty($testimonial['created_at'])) {
                $review['datePublished'] = gmdate('Y-m-d', strtotime($testimonial['created_at']));
            }

            $reviews[] = $review;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => $schema_type,
            'name'     => $schema_name,
        ];

        if ($total_ratings > 0) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) round($rating_sum / $total_ratings, 1),
                'bestRating'  => '5',
                'ratingCount' => (string) $total_ratings,
            ];
        }

        $schema['review'] = $reviews;

        // JSON_UNESCAPED_SLASHES is deliberately NOT set: escaping "/" as "\/" is what
        // stops a "</script>" sequence inside any value from closing the tag early.
        $json = wp_json_encode($schema, JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
    }

    /**
     * Strip shortcodes, HTML, and block comments from content for schema
     *
     * @param string $content The content to clean
     * @return string Cleaned content safe for JSON-LD schema
     */
    public function strip_content_for_schema(string $content): string {
        $content = strip_shortcodes($content);
        $content = preg_replace('/\[[^\]]*\]/', '', $content);
        $content = preg_replace('/<!--\s*\/?wp:[^>]*-->/s', '', $content);
        return wp_strip_all_tags($content);
    }
}
