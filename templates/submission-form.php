<?php
/**
 * Submission Form Template - Frontend testimonial submission form
 * Card layout with pill-button tabs, per-field validation, and compact photo upload.
 *
 * @var array  $settings              Plugin settings
 * @var string $instance_id           Unique instance ID
 * @var string $form_style            'inline' or 'modal'
 * @var bool   $photo_upload_enabled  Whether photo upload is enabled
 * @var array  $custom_fields         Custom field definitions (from shortcodes render_form)
 *
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Template-scope render variables supplied by the shortcode renderer, not global-namespace pollution.
if (!defined('ABSPATH')) exit;

$required_fields = $settings['required_fields'] ?? ['client_name', 'testimonial_text', 'rating'];
$success_message = $settings['success_message'] ?? __('Thank you for your testimonial! It will be reviewed shortly.', 'plugixa-testimonial');
$min_text_length = (int) ($settings['min_text_length'] ?? 10);
$max_text_length = (int) ($settings['max_text_length'] ?? 2000);
$form_style = $form_style ?? 'inline';
$photo_upload_enabled = $photo_upload_enabled ?? false;
$custom_fields = $custom_fields ?? [];
$has_custom_fields = !empty($custom_fields);

$api_url = rest_url('plugixatestimonial/v1/frontend/submit');
$nonce = \PlugixaTestimonial\Modules\Frontend\Shortcodes::public_rest_nonce();

$input_class = 'pxt:block pxt:w-full pxt:rounded-md pxt:bg-white pxt:px-3 pxt:py-2 pxt:text-base pxt:text-gray-900 pxt:outline-1 pxt:-outline-offset-1 pxt:outline-gray-300 placeholder:pxt:text-gray-400 focus:pxt:outline-2 focus:pxt:-outline-offset-2 focus:pxt:outline-indigo-600 sm:pxt:text-sm';

// Request token pre-fill
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form; the request token in the URL is itself the credential and is validated server-side.
$request_token = isset($_GET['pxt_request_token']) ? sanitize_text_field(wp_unslash($_GET['pxt_request_token'])) : '';
$prefill_name = '';
$prefill_email = '';
if ($request_token) {
    // Dispatched in-process, not over HTTP: same route and checks, no loopback request.
    $validate_request = new \WP_REST_Request('GET', '/plugixatestimonial/v1/requests/validate-token');
    $validate_request->set_query_params(['token' => $request_token]);
    $validate_response = rest_do_request($validate_request);
    $body = $validate_response->is_error() ? [] : (array) $validate_response->get_data();
    if (!empty($body['success']) && !empty($body['data'])) {
        $prefill_name = $body['data']['recipient_name'] ?? '';
        $prefill_email = $body['data']['recipient_email'] ?? '';
        // This copy of the page now holds one recipient's name and email: never cache it.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant page-caching plugins read; its name is theirs.
        }
    }
}
?>

<?php if ($form_style === 'modal'): ?>
<button type="button"
        class="pxt-custom-open-modal-btn pxt:rounded-md pxt:bg-indigo-600 pxt:px-3.5 pxt:py-2.5 pxt:text-sm pxt:font-semibold pxt:text-white pxt:shadow-sm hover:pxt:bg-indigo-500 focus-visible:pxt:outline focus-visible:pxt:outline-2 focus-visible:pxt:outline-offset-2 focus-visible:pxt:outline-indigo-600 pxt:cursor-pointer pxt:transition-colors">
    <?php echo esc_html__('Submit Testimonial', 'plugixa-testimonial'); ?>
</button>
<div class="pxt-custom-modal-overlay" aria-modal="true" role="dialog">
<div class="pxt-custom-modal-backdrop pxt:fixed pxt:inset-0 pxt:bg-gray-500/75 pxt:transition-opacity"></div>
<div class="pxt-custom-modal-scroll pxt:fixed pxt:inset-0 pxt:z-[99999] pxt:overflow-y-auto">
<div class="pxt:flex pxt:min-h-full pxt:items-center pxt:justify-center pxt:p-4">
<div class="pxt-custom-modal-content pxt:relative pxt:w-full pxt:max-w-lg pxt:transform pxt:rounded-xl pxt:bg-white pxt:shadow-xl pxt:transition-all">
<button type="button"
        class="pxt-custom-modal-close pxt:absolute pxt:top-4 pxt:right-4 pxt:text-gray-400 hover:pxt:text-gray-600 pxt:rounded-md focus:pxt:outline-2 focus:pxt:outline-indigo-600 pxt:bg-transparent pxt:border-0 pxt:cursor-pointer pxt:p-1 pxt:z-10"
        aria-label="Close">
    <svg class="pxt:size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
    </svg>
</button>
<?php endif; ?>

<div id="<?php echo esc_attr($instance_id); ?>"
     class="pxt-custom-form-wrapper <?php echo esc_attr($form_style !== 'modal' ? 'pxt:max-w-lg pxt:mx-auto pxt:my-8' : ''); ?>"
     data-api-url="<?php echo esc_url($api_url); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-success-message="<?php echo esc_attr($success_message); ?>"
     data-required-fields='<?php echo esc_attr(wp_json_encode($required_fields)); ?>'
     data-min-length="<?php echo esc_attr($min_text_length); ?>"
     data-max-length="<?php echo esc_attr($max_text_length); ?>"
     data-lang="<?php echo esc_attr($current_lang ?? ''); ?>">

    <div class="pxt-form-card">
        <!-- Tab Navigation -->
        <div class="pxt-form-tabs" role="tablist" aria-label="<?php echo esc_attr__('Form sections', 'plugixa-testimonial'); ?>">
            <button type="button" class="pxt-form-tab-btn active" data-tab="0" role="tab"
                    aria-selected="true" aria-controls="<?php echo esc_attr($instance_id); ?>-panel-0">
                <?php echo esc_html__('Personal Info', 'plugixa-testimonial'); ?>
            </button>
            <button type="button" class="pxt-form-tab-btn" data-tab="1" role="tab"
                    aria-selected="false" aria-controls="<?php echo esc_attr($instance_id); ?>-panel-1">
                <?php echo esc_html__('Your Review', 'plugixa-testimonial'); ?>
            </button>
            <?php if ($has_custom_fields): ?>
            <button type="button" class="pxt-form-tab-btn" data-tab="2" role="tab"
                    aria-selected="false" aria-controls="<?php echo esc_attr($instance_id); ?>-panel-2">
                <?php echo esc_html__('Additional Info', 'plugixa-testimonial'); ?>
            </button>
            <?php endif; ?>
        </div>

        <form class="plugixa-testimonial-form" novalidate<?php if ($photo_upload_enabled): ?> enctype="multipart/form-data"<?php endif; ?>>
            <?php if ($request_token): ?>
                <input type="hidden" name="pxt_request_token" value="<?php echo esc_attr($request_token); ?>" />
            <?php endif; ?>

            <!-- ==================== Tab 0: Personal Info ==================== -->
            <div class="pxt-form-tab-panel active" data-tab="0" role="tabpanel"
                 id="<?php echo esc_attr($instance_id); ?>-panel-0">

                <!-- Name -->
                <div>
                    <label for="<?php echo esc_attr($instance_id); ?>-name"
                           class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900">
                        <?php echo esc_html__('Name', 'plugixa-testimonial'); ?> <?php if (in_array('client_name', $required_fields)): ?><span class="pxt:text-red-500">*</span><?php endif; ?>
                    </label>
                    <div class="pxt:mt-1.5">
                        <input type="text"
                               id="<?php echo esc_attr($instance_id); ?>-name"
                               name="client_name"
                               <?php if (in_array('client_name', $required_fields)): ?>required<?php endif; ?>
                               <?php if ($prefill_name): ?>value="<?php echo esc_attr($prefill_name); ?>"<?php endif; ?>
                               placeholder="<?php echo esc_attr__('Your name', 'plugixa-testimonial'); ?>"
                               class="<?php echo esc_attr($input_class); ?>" />
                        <span class="pxt-field-error" data-field="client_name"></span>
                    </div>
                </div>

                <!-- Email -->
                <div>
                    <label for="<?php echo esc_attr($instance_id); ?>-email"
                           class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900">
                        <?php echo esc_html__('Email', 'plugixa-testimonial'); ?> <?php if (in_array('email', $required_fields)): ?><span class="pxt:text-red-500">*</span><?php endif; ?>
                    </label>
                    <div class="pxt:mt-1.5">
                        <input type="email"
                               id="<?php echo esc_attr($instance_id); ?>-email"
                               name="email"
                               <?php if (in_array('email', $required_fields)): ?>required<?php endif; ?>
                               <?php if ($prefill_email): ?>value="<?php echo esc_attr($prefill_email); ?>"<?php endif; ?>
                               placeholder="<?php echo esc_attr__('Your email', 'plugixa-testimonial'); ?>"
                               class="<?php echo esc_attr($input_class); ?>" />
                        <span class="pxt-field-error" data-field="email"></span>
                    </div>
                </div>

                <!-- Role + Company (2-col) -->
                <div class="pxt:grid pxt:grid-cols-2 pxt:gap-x-4 pxt:gap-y-4">
                    <div>
                        <label for="<?php echo esc_attr($instance_id); ?>-role"
                               class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900">
                            <?php echo esc_html__('Role / Title', 'plugixa-testimonial'); ?> <?php if (in_array('client_role', $required_fields)): ?><span class="pxt:text-red-500">*</span><?php endif; ?>
                        </label>
                        <div class="pxt:mt-1.5">
                            <input type="text"
                                   id="<?php echo esc_attr($instance_id); ?>-role"
                                   name="client_role"
                                   <?php if (in_array('client_role', $required_fields)): ?>required<?php endif; ?>
                                   placeholder="<?php echo esc_attr__('e.g. Marketing Manager', 'plugixa-testimonial'); ?>"
                                   class="<?php echo esc_attr($input_class); ?>" />
                            <span class="pxt-field-error" data-field="client_role"></span>
                        </div>
                    </div>
                    <div>
                        <label for="<?php echo esc_attr($instance_id); ?>-company"
                               class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900">
                            <?php echo esc_html__('Company', 'plugixa-testimonial'); ?> <?php if (in_array('client_company', $required_fields)): ?><span class="pxt:text-red-500">*</span><?php endif; ?>
                        </label>
                        <div class="pxt:mt-1.5">
                            <input type="text"
                                   id="<?php echo esc_attr($instance_id); ?>-company"
                                   name="client_company"
                                   <?php if (in_array('client_company', $required_fields)): ?>required<?php endif; ?>
                                   placeholder="<?php echo esc_attr__('Your company', 'plugixa-testimonial'); ?>"
                                   class="<?php echo esc_attr($input_class); ?>" />
                            <span class="pxt-field-error" data-field="client_company"></span>
                        </div>
                    </div>
                </div>

                <!-- Website -->
                <div>
                    <label for="<?php echo esc_attr($instance_id); ?>-website"
                           class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900">
                        <?php echo esc_html__('Website', 'plugixa-testimonial'); ?>
                    </label>
                    <div class="pxt:mt-1.5">
                        <input type="url"
                               id="<?php echo esc_attr($instance_id); ?>-website"
                               name="website_url"
                               placeholder="https://example.com"
                               class="<?php echo esc_attr($input_class); ?>" />
                        <span class="pxt-field-error" data-field="website_url"></span>
                    </div>
                </div>

                <!-- Photo Upload (compact) -->
                <?php if ($photo_upload_enabled): ?>
                <div>
                    <label class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900 pxt:mb-1.5">
                        <?php echo esc_html__('Profile Photo', 'plugixa-testimonial'); ?>
                    </label>
                    <div class="pxt-photo-compact">
                        <div class="pxt-photo-avatar">
                            <svg class="pxt:size-5 pxt:text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <button type="button" class="pxt-photo-choose-btn">
                            <?php echo esc_html__('Choose Photo', 'plugixa-testimonial'); ?>
                        </button>
                        <span class="pxt-photo-filename"></span>
                        <input type="file"
                               id="<?php echo esc_attr($instance_id); ?>-photo"
                               name="client_photo"
                               accept="image/*"
                               class="pxt-custom-photo-input pxt:sr-only" />
                    </div>
                    <span class="pxt-field-error" data-field="client_photo"></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- ==================== Tab 1: Your Review ==================== -->
            <div class="pxt-form-tab-panel" data-tab="1" role="tabpanel"
                 id="<?php echo esc_attr($instance_id); ?>-panel-1">

                <!-- Star Rating -->
                <div>
                    <label class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900">
                        <?php echo esc_html__('Rating', 'plugixa-testimonial'); ?> <?php if (in_array('rating', $required_fields)): ?><span class="pxt:text-red-500">*</span><?php endif; ?>
                    </label>
                    <div class="pxt-custom-star-input pxt:mt-1.5 pxt:flex pxt:gap-1 pxt:items-center">
                        <input type="hidden" name="rating" value="5" />
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="pxt-custom-star-btn active" data-value="<?php echo esc_attr($i); ?>">&#9733;</span>
                        <?php endfor; ?>
                    </div>
                    <span class="pxt-field-error" data-field="rating"></span>
                </div>

                <!-- Testimonial Text -->
                <div>
                    <label for="<?php echo esc_attr($instance_id); ?>-text"
                           class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900">
                        <?php echo esc_html__('Your Testimonial', 'plugixa-testimonial'); ?> <?php if (in_array('testimonial_text', $required_fields)): ?><span class="pxt:text-red-500">*</span><?php endif; ?>
                    </label>
                    <div class="pxt:mt-1.5">
                        <textarea id="<?php echo esc_attr($instance_id); ?>-text"
                                  name="testimonial_text"
                                  rows="5"
                                  <?php if (in_array('testimonial_text', $required_fields)): ?>required<?php endif; ?>
                                  placeholder="<?php echo esc_attr__('Share your experience...', 'plugixa-testimonial'); ?>"
                                  class="<?php echo esc_attr($input_class); ?>"></textarea>
                        <div class="pxt:flex pxt:justify-between pxt:items-center">
                            <span class="pxt-field-error" data-field="testimonial_text"></span>
                            <div class="pxt-char-counter">
                                <span class="pxt-char-count">0</span> / <?php echo esc_html($max_text_length); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== Tab 2: Additional Info (conditional) ==================== -->
            <?php if ($has_custom_fields): ?>
            <div class="pxt-form-tab-panel" data-tab="2" role="tabpanel"
                 id="<?php echo esc_attr($instance_id); ?>-panel-2">
                <?php foreach ($custom_fields as $cf):
                    // Raw here on purpose: every use below escapes at the echo site with
                    // esc_attr(), which is what makes the escaping statically verifiable.
                    $cf_id = $instance_id . '-cf-' . $cf['field_key'];
                    $cf_name = 'custom_fields[' . $cf['field_key'] . ']';
                    $cf_required = $cf['is_required'];
                    $cf_placeholder = $cf['placeholder'] ?? '';
                ?>
                <div>
                    <?php if ($cf['field_type'] !== 'checkbox'): ?>
                    <label for="<?php echo esc_attr($cf_id); ?>"
                           class="pxt:block pxt:text-sm pxt:font-medium pxt:text-gray-900">
                        <?php echo esc_html($cf['field_label']); ?> <?php if ($cf_required): ?><span class="pxt:text-red-500">*</span><?php endif; ?>
                    </label>
                    <?php endif; ?>
                    <div class="pxt:mt-1.5">
                        <?php switch ($cf['field_type']):
                            case 'textarea': ?>
                                <textarea id="<?php echo esc_attr($cf_id); ?>"
                                          name="<?php echo esc_attr($cf_name); ?>"
                                          rows="3"
                                          <?php if ($cf_required): ?>required<?php endif; ?>
                                          placeholder="<?php echo esc_attr($cf_placeholder); ?>"
                                          class="<?php echo esc_attr($input_class); ?>"></textarea>
                            <?php break;
                            case 'select': ?>
                                <select id="<?php echo esc_attr($cf_id); ?>"
                                        name="<?php echo esc_attr($cf_name); ?>"
                                        <?php if ($cf_required): ?>required<?php endif; ?>
                                        class="<?php echo esc_attr($input_class); ?>">
                                    <option value=""><?php echo esc_attr($cf_placeholder ?: __('Select...', 'plugixa-testimonial')); ?></option>
                                    <?php foreach ($cf['field_options'] as $option): ?>
                                        <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php break;
                            case 'checkbox': ?>
                                <label class="pxt:flex pxt:items-center pxt:gap-2 pxt:cursor-pointer">
                                    <input type="checkbox"
                                           id="<?php echo esc_attr($cf_id); ?>"
                                           name="<?php echo esc_attr($cf_name); ?>"
                                           value="1"
                                           <?php if ($cf_required): ?>required<?php endif; ?>
                                           class="pxt:size-4 pxt:rounded pxt:border-gray-300 pxt:text-indigo-600 focus:pxt:ring-indigo-600" />
                                    <span class="pxt:text-sm pxt:font-medium pxt:text-gray-900">
                                        <?php echo esc_html($cf['field_label']); ?> <?php if ($cf_required): ?><span class="pxt:text-red-500">*</span><?php endif; ?>
                                    </span>
                                </label>
                            <?php break;
                            default: ?>
                                <input type="<?php echo esc_attr($cf['field_type']); ?>"
                                       id="<?php echo esc_attr($cf_id); ?>"
                                       name="<?php echo esc_attr($cf_name); ?>"
                                       <?php if ($cf_required): ?>required<?php endif; ?>
                                       placeholder="<?php echo esc_attr($cf_placeholder); ?>"
                                       class="<?php echo esc_attr($input_class); ?>" />
                        <?php endswitch; ?>
                        <span class="pxt-field-error" data-field="<?php echo esc_attr($cf['field_key']); ?>"></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Message (for server errors) -->
            <div class="pxt-custom-form-message" style="display: none;"></div>

            <!-- Card Footer: Navigation -->
            <div class="pxt-form-card-footer">
                <button type="button" class="pxt-form-prev-btn pxt:rounded-md pxt:px-3.5 pxt:py-2 pxt:text-sm pxt:font-medium pxt:text-gray-700 pxt:border pxt:border-gray-300 pxt:bg-white hover:pxt:bg-gray-50 pxt:cursor-pointer pxt:transition-colors" style="display: none;">
                    <?php echo esc_html__('← Previous', 'plugixa-testimonial'); ?>
                </button>
                <div class="pxt:ml-auto pxt:flex pxt:gap-3">
                    <button type="button" class="pxt-form-next-btn pxt:rounded-md pxt:bg-indigo-600 pxt:px-4 pxt:py-2 pxt:text-sm pxt:font-semibold pxt:text-white pxt:shadow-sm hover:pxt:bg-indigo-500 pxt:cursor-pointer pxt:transition-colors pxt:border-0">
                        <?php echo esc_html__('Next →', 'plugixa-testimonial'); ?>
                    </button>
                    <button type="submit" class="pxt-form-submit-btn pxt:rounded-md pxt:bg-indigo-600 pxt:px-4 pxt:py-2 pxt:text-sm pxt:font-semibold pxt:text-white pxt:shadow-sm hover:pxt:bg-indigo-500 disabled:pxt:opacity-50 disabled:pxt:cursor-not-allowed pxt:cursor-pointer pxt:transition-colors pxt:border-0" style="display: none;">
                        <span class="pxt-btn-text"><?php echo esc_html__('Submit Testimonial', 'plugixa-testimonial'); ?></span>
                        <span class="pxt-btn-spinner" style="display: none;"></span>
                    </button>
                </div>
            </div>

            <!-- Success State -->
            <div class="pxt-form-success" style="display: none;">
                <div class="pxt-form-success-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <h3><?php echo esc_html__('Thank You!', 'plugixa-testimonial'); ?></h3>
                <p class="pxt-form-success-message"><?php echo esc_html($success_message); ?></p>
                <button type="button" class="pxt-form-success-again">
                    <?php echo esc_html__('Submit another testimonial', 'plugixa-testimonial'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($form_style === 'modal'): ?>
</div><!-- .pxt-custom-modal-content -->
</div><!-- flex centering -->
</div><!-- .pxt-custom-modal-scroll -->
</div><!-- .pxt-custom-modal-overlay -->
<?php endif; ?>
