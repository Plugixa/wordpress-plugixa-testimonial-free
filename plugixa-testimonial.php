<?php

/**
 * Plugin Name: Plugixa Testimonial – Customer Reviews, Star Ratings & Testimonial Slider
 * Plugin URI: https://www.plugixa.com/plugins/plugixa-testimonial
 * Description: A modern testimonial management plugin with React admin panel, star ratings, client photos, approval workflow, and multiple display layouts.
 * Version: 1.1.1
 * Author: Plugixa
 * Author URI: https://www.plugixa.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: plugixa-testimonial
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.2
 *
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * This plugin is licensed under the GNU General Public License v2 or later.
 *
 * All premium code and dependencies live under pro/: PHP in pro/includes/, the premium
 * libraries (mPDF) in pro/vendor/ with their own autoloader,
 * and the compiled pro admin bundle in pro/assets/backend/build/.
 *
 * Both premium paths are listed below so that Freemius's OWN auto-generated free build
 * excludes them too, not just our release script.
 *
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Plugin constants
define( 'PLUGIXA_TESTIMONIAL_VERSION', '1.1.1' );
define( 'PLUGIXA_TESTIMONIAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLUGIXA_TESTIMONIAL_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIXA_TESTIMONIAL_BASENAME', plugin_basename( __FILE__ ) );
// Core Composer autoloader (Freemius SDK). Ships in both builds.
if ( file_exists( PLUGIXA_TESTIMONIAL_PATH . 'vendor/autoload.php' ) ) {
    require_once PLUGIXA_TESTIMONIAL_PATH . 'vendor/autoload.php';
}
// Premium dependencies (mPDF) live in pro/vendor/ and are
// used only by pro/ code. Freemius strips /pro/ from the free build via the premium-only
// path marker in this file's header, so the free ZIP ships none of them - load them only
// when the pro overlay is actually present.
if ( file_exists( PLUGIXA_TESTIMONIAL_PATH . 'pro/vendor/autoload.php' ) ) {
    require_once PLUGIXA_TESTIMONIAL_PATH . 'pro/vendor/autoload.php';
}
if ( !function_exists( 'plugixa_testimonial_fs_pxf' ) ) {
    // Create a helper function for easy SDK access.
    function plugixa_testimonial_fs_pxf() {
        global $plugixa_testimonial_fs_pxf;
        if ( !isset( $plugixa_testimonial_fs_pxf ) ) {
            // Activate multisite network integration.
            if ( !defined( 'WP_FS__PRODUCT_19217_MULTISITE' ) ) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The name is mandated by the Freemius SDK, which looks up WP_FS__PRODUCT_<id>_MULTISITE verbatim; prefixing it would simply stop the SDK finding it.
                define( 'WP_FS__PRODUCT_19217_MULTISITE', true );
            }
            // Include Freemius SDK.
            // SDK is auto-loaded through Composer
            $plugixa_testimonial_fs_pxf = fs_dynamic_init( [
                'id'               => '19217',
                'slug'             => 'plugixa-testimonial',
                'type'             => 'plugin',
                'public_key'       => 'pk_8010580d139692742409f56332031',
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => [
                    'slug'    => 'plugixa-testimonial',
                    'support' => false,
                ],
                'is_live'          => true,
            ] );
            // Belt and braces: suppress every Freemius trial promotion the SDK can still
            // surface on its own (the "Start free trial" admin banner, the "Start Trial"
            // menu item, the trial CTA on the pricing page), so the wp.org build only ever
            // offers "Upgrade".
            $plugixa_testimonial_fs_pxf->add_filter( 'show_trial', '__return_false' );
        }
        return $plugixa_testimonial_fs_pxf;
    }

    // Init Freemius.
    plugixa_testimonial_fs_pxf();
    // Signal that SDK was initiated.
    do_action( 'plugixa_testimonial_fs_pxf_loaded' );
    // Uninstall cleanup runs via Freemius's after_uninstall action instead of a root
    // uninstall.php: WordPress only runs uninstall.php from the active plugin folder,
    // which breaks with Freemius's free/premium version switching, and Freemius rejects
    // plugins that ship one. Freemius loads this file during uninstall, so the autoloader
    // is registered and \PlugixaTestimonial\Core\Uninstaller is available.
    plugixa_testimonial_fs_pxf()->add_action( 'after_uninstall', function () {
        \PlugixaTestimonial\Core\Uninstaller::uninstall();
    } );
}
/**
 * PSR-4 autoloader, in the layout shared with plugixa-inventory.
 *
 * Every class lives under includes/Core/, includes/Modules/<Name>/ or, in the premium build,
 * pro/includes/Modules/<Name>/; the namespace is the path and the class name is the file
 * name. The /pro/ directory is excluded from the free ZIP by the premium-only path marker in
 * this file's header, so the premium branch finds nothing on a free site.
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'PlugixaTestimonial\\';
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }
    $relative_class = substr( $class, $len );
    // The module layout shared with plugixa-inventory, PSR-4 proper: the namespace is the
    // path and the class name is the file name. PlugixaTestimonial\Modules\Categories\Module
    // lives at includes/Modules/Categories/Module.php, and the premium overlay's modules
    // (PlugixaTestimonial\Pro\Modules\...) at pro/includes/Modules/..., which the free
    // build does not contain.
    foreach ( [
        'Core\\'         => 'includes/Core/',
        'Modules\\'      => 'includes/Modules/',
        'Pro\\Modules\\' => 'pro/includes/Modules/',
    ] as $psr4_prefix => $psr4_dir ) {
        if ( strncmp( $relative_class, $psr4_prefix, strlen( $psr4_prefix ) ) === 0 ) {
            $file = PLUGIXA_TESTIMONIAL_PATH . $psr4_dir . str_replace( '\\', '/', substr( $relative_class, strlen( $psr4_prefix ) ) ) . '.php';
            if ( is_readable( $file ) ) {
                require $file;
            }
            return;
        }
    }
    // The names classes had before the Oct 2026 move, kept working as aliases. A theme can
    // override the templates (TemplateLoader), and its copy still calls
    // \PlugixaTestimonial\Kses and \PlugixaTestimonial\Frontend\Shortcodes; a site's own code
    // may hook a class by its old name. Each alias is made only when the old name is asked for.
    $plugixa_testimonial_old_names = [
        'Plugin'                                 => 'Core\\Plugin',
        'Activator'                              => 'Core\\Activator',
        'Deactivator'                            => 'Core\\Deactivator',
        'Uninstaller'                            => 'Core\\Uninstaller',
        'Cache'                                  => 'Core\\Cache',
        'Kses'                                   => 'Core\\Kses',
        'Multilingual'                           => 'Core\\Multilingual',
        'TemplateLoader'                         => 'Core\\TemplateLoader',
        'Admin\\Admin'                           => 'Core\\Admin\\Admin',
        'Admin\\Assets'                          => 'Core\\Admin\\Assets',
        'Email\\EmailNotifier'                   => 'Core\\Mail\\EmailNotifier',
        'Fonts\\FontManager'                     => 'Modules\\GoogleFonts\\FontManager',
        'Frontend\\Schema'                       => 'Modules\\Frontend\\Schema',
        'Frontend\\Shortcodes'                   => 'Modules\\Frontend\\Shortcodes',
        'Frontend\\SocialProof'                  => 'Modules\\Frontend\\SocialProof',
        'Blocks\\TestimonialBlock'               => 'Modules\\Blocks\\TestimonialBlock',
        'Integrations\\Elementor\\Elementor'     => 'Modules\\Elementor\\Elementor',
        'Integrations\\WooCommerce\\WooCommerce' => 'Modules\\WooCommerce\\WooCommerce',
    ];
    if ( isset( $plugixa_testimonial_old_names[$relative_class] ) ) {
        $new_class = $prefix . $plugixa_testimonial_old_names[$relative_class];
        if ( class_exists( $new_class ) ) {
            class_alias( $new_class, $class );
        }
    }
} );
/**
 * Plugin activation hook
 */
register_activation_hook( __FILE__, function () {
    \PlugixaTestimonial\Core\Activator::activate();
} );
/**
 * Plugin deactivation hook
 */
register_deactivation_hook( __FILE__, function () {
    \PlugixaTestimonial\Core\Deactivator::deactivate();
} );
/**
 * Initialize the plugin
 */
add_action( 'plugins_loaded', function () {
    \PlugixaTestimonial\Core\Plugin::instance();
} );