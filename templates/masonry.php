<?php
/**
 * Masonry Template - Renders testimonials in a masonry/pinterest-style layout
 *
 * @var array $testimonials Array of testimonial data
 * @var array $view_config View configuration
 * @var string $instance_id Unique instance ID
 *
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Template-scope render variables supplied by the shortcode renderer, not global-namespace pollution.
if (!defined('ABSPATH')) exit;

// Allowed-HTML vocabulary for the late wp_kses() escaping below. Computed once per
// render rather than per card. See includes/class-kses.php.
$pxt_kses_allowed = \PlugixaTestimonial\Core\Kses::allowed_html();

$display = $view_config['display'] ?? [];
$photo_config = $view_config['photo'] ?? [];
$photo_fallback_mode = $photo_config['fallbackMode'] ?? 'initials';
$photo_auto_color = ($photo_config['fallbackColorMode'] ?? 'single') === 'auto';
$photo_position = $photo_config['position'] ?? 'inline';
$photo_size_unit = $photo_config['sizeUnit'] ?? 'px';
$is_side_position = in_array($photo_position, ['left', 'right'], true);
$is_percent = $photo_size_unit === '%';
?>

<div class="pxt-custom-masonry">
    <?php foreach ($testimonials as $testimonial):
        $photo_url = '';
        if (!empty($testimonial['client_photo_id'])) {
            $photo_url = wp_get_attachment_image_url((int) $testimonial['client_photo_id'], 'thumbnail') ?: '';
        }

        // Build photo HTML
        $photo_html = '';
        if (!empty($display['showPhoto'])) {
            $photo_classes = 'pxt-custom-photo' . ($is_percent ? ' pxt-custom-photo--percent' : '');
            $fallback_attr = $photo_auto_color ? \PlugixaTestimonial\Core\AvatarColor::style_attribute((string) $testimonial['client_name']) : '';
            if ($photo_url) {
                $photo_html = '<div class="' . $photo_classes . '"><img src="' . esc_url($photo_url) . '" alt="' . esc_attr($testimonial['client_name']) . '" /></div>';
            } elseif ($photo_fallback_mode === 'initials') {
                $words = explode(' ', trim($testimonial['client_name']));
                $init = '';
                foreach ($words as $w) { if ($w !== '') $init .= mb_strtoupper(mb_substr($w, 0, 1)); }
                $init = mb_substr($init, 0, 2);
                $photo_html = '<div class="' . $photo_classes . '"><div class="pxt-custom-fallback"' . $fallback_attr . '>' . esc_html($init) . '</div></div>';
            } elseif ($photo_fallback_mode === 'icon') {
                $photo_html = '<div class="' . $photo_classes . '"><div class="pxt-custom-fallback"' . $fallback_attr . '><svg viewBox="0 0 24 24" fill="currentColor" style="width:40%;height:40%"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg></div></div>';
            }
        }

        // Build meta
        $meta = [];
        if (!empty($display['showRole']) && !empty($testimonial['client_role'])) {
            $meta[] = esc_html($testimonial['client_role']);
        }
        if (!empty($display['showCompany']) && !empty($testimonial['client_company'])) {
            $meta[] = esc_html($testimonial['client_company']);
        }

        // Card classes
        $card_classes = 'pxt-custom-card pxt:break-inside-avoid';
        if ($is_side_position) {
            $card_classes .= ' pxt:flex pxt:gap-5 pxt:items-start';
            if ($photo_position === 'right') {
                $card_classes .= ' pxt:flex-row-reverse';
            }
        }

        // Category IDs for JS filtering
        $cat_ids_attr = !empty($testimonial['category_ids']) ? implode(',', $testimonial['category_ids']) : '';
        $cat_orders_attr = !empty($testimonial['category_orders']) ? wp_json_encode($testimonial['category_orders']) : '';
        $global_order_attr = isset($testimonial['global_order']) ? (int) $testimonial['global_order'] : '';
    ?>
    <div class="<?php echo esc_attr($card_classes); ?>" data-pxt-rating="<?php echo esc_attr((int) $testimonial['rating']); ?>"<?php if ($cat_ids_attr): ?> data-pxt-categories="<?php echo esc_attr($cat_ids_attr); ?>"<?php endif; ?><?php if ($cat_orders_attr): ?> data-pxt-category-orders="<?php echo esc_attr($cat_orders_attr); ?>"<?php endif; ?><?php if ($global_order_attr !== ''): ?> data-pxt-global-order="<?php echo esc_attr($global_order_attr); ?>"<?php endif; ?>>
        <?php if ($photo_position === 'top' && $photo_html): ?>
            <div class="pxt:text-center pxt:mb-3"><?php echo wp_kses($photo_html, $pxt_kses_allowed); ?></div>
        <?php endif; ?>

        <?php if ($is_side_position && $photo_html): ?>
            <?php echo wp_kses($photo_html, $pxt_kses_allowed); ?>
        <?php endif; ?>

        <div<?php if ($is_side_position): ?> class="pxt-custom-card-content"<?php endif; ?>>
            <?php if (!empty($display['showQuoteIcon'])): ?>
                <div class="pxt-custom-quote">&ldquo;</div>
            <?php endif; ?>

            <?php if ($photo_position === 'inline' && $photo_html): ?>
                <div class="pxt:flex pxt:items-center pxt:gap-3 pxt:mb-3">
                    <?php echo wp_kses($photo_html, $pxt_kses_allowed); ?>
                    <div class="pxt-custom-author">
                        <strong class="pxt-custom-name"><?php echo esc_html($testimonial['client_name']); ?></strong>
                        <?php if (!empty($meta)): ?>
                            <span class="pxt-custom-meta"><?php echo wp_kses(implode(', ', $meta), $pxt_kses_allowed); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="pxt-custom-text">
                <?php echo wp_kses($testimonial['testimonial_text'], \PlugixaTestimonial\Core\Kses::allowed_html('content')); ?>
            </div>

            <?php if (!empty($display['showVideo']) && !empty($testimonial['video_url'])):
                $shortcodes_v = new \PlugixaTestimonial\Modules\Frontend\Shortcodes();
                $video_html = $shortcodes_v->render_video($testimonial);
                if ($video_html): ?>
                <div class="pxt-custom-video"><?php echo wp_kses($video_html, $pxt_kses_allowed); ?></div>
            <?php endif; endif; ?>

            <?php if (!empty($display['showRating'])): ?>
                <div class="pxt-custom-rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="pxt-custom-star <?php echo esc_attr($i <= $testimonial['rating'] ? 'pxt-custom-star--filled' : 'pxt-custom-star--empty'); ?>">&#9733;</span>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($display['showCustomFields']) && !empty($testimonial['custom_fields'])):
                $shortcodes = new \PlugixaTestimonial\Modules\Frontend\Shortcodes();
                $cf_html = $shortcodes->render_custom_fields($testimonial);
                if ($cf_html): ?>
                <div class="pxt-custom-fields"><?php echo wp_kses($cf_html, $pxt_kses_allowed); ?></div>
            <?php endif; endif; ?>

            <?php if (!empty($display['showWebsite']) && !empty($testimonial['website_url'])):
                // Link text is the bare host - a full URL wraps badly inside a card.
                $pxt_site_url  = $testimonial['website_url'];
                $pxt_site_host = (string) wp_parse_url($pxt_site_url, PHP_URL_HOST);
                if (stripos($pxt_site_host, 'www.') === 0) {
                    $pxt_site_host = substr($pxt_site_host, 4);
                }
                $pxt_site_text = $pxt_site_host !== '' ? $pxt_site_host : $pxt_site_url; ?>
                <div class="pxt-custom-website">
                    <a href="<?php echo esc_url($pxt_site_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($pxt_site_text); ?></a>
                </div>
            <?php endif; ?>

            <?php if ($photo_position !== 'inline' || !$photo_html): ?>
                <div class="pxt-custom-author">
                    <strong class="pxt-custom-name"><?php echo esc_html($testimonial['client_name']); ?></strong>
                    <?php if (!empty($meta)): ?>
                        <span class="pxt-custom-meta"><?php echo wp_kses(implode(', ', $meta), $pxt_kses_allowed); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
