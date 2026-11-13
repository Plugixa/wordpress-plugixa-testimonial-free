<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core\Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * Assets Class - Handles React app assets (HMR dev / production build)
 */
class Assets {

    /**
     * wp-admin stylesheets this screen does not render, taken off it.
     *
     * `forms` is not merely unused here - it is actively wrong. WordPress styles bare form
     * elements (`input[type=text]` = specificity 0,1,1) while every MUI rule is a single
     * Emotion class (0,1,0), so WordPress wins on min-height, border, border-radius,
     * font-size and margin on every field in the app, by construction.
     *
     * That cannot be fixed with a reset: any selector specific enough to beat (0,1,1) is also
     * more specific than MUI's (0,1,0), so a rule that neutralises WordPress's padding
     * clobbers MUI's in the same breath and the field ends up with neither. There is no
     * specificity between the two - the stylesheet has to go.
     *
     * DELIBERATELY NOT LISTED, unlike the family template this came from:
     *   media, media-views  the client-photo picker opens wp.media (see TestimonialForm.tsx)
     *   edit, editor-buttons  the testimonial body uses wp.editor/TinyMCE, whose toolbar,
     *                         Visual/Code tabs and "Add media" button these style
     * Dropping those would break shipped features. Verify in the browser before adding any.
     *
     * @var string[]
     */
    private const DROP_STYLES = [
        'forms',
        'dashboard',
        'list-tables',
        'revisions',
        'themes',
        'about',
        'nav-menus',
        'widgets',
        'wp-pointer',
    ];

    /**
     * Marks this screen's <body> so a rule can reach the app's PORTALS. See mark_body().
     */
    private const BODY_CLASS = 'plugixa-testimonial-screen';

    private string $plugin_path;
    private string $plugin_url;
    private string $version;

    /** Set by enqueue_app_assets(), which only runs on this plugin's own screens. */
    private bool $on_plugin_screen = false;

    public function __construct(string $plugin_path, string $plugin_url, string $version) {
        $this->plugin_path = $plugin_path;
        $this->plugin_url = $plugin_url;
        $this->version = $version;
    }

    public function enqueue_app_assets(): void {
        // Admin::enqueue_assets() only calls this for hook suffixes in its own allowlist, so
        // reaching here means we are on a plugin screen. That allowlist is an exact match on
        // the suffixes add_menu_page()/add_submenu_page() returned - deliberately not a slug
        // prefix test, because Freemius's auto-generated pages share our slug.
        $this->on_plugin_screen = true;
        add_filter('print_styles_array', [$this, 'strip_unused_admin_styles']);
        add_filter('admin_body_class', [$this, 'mark_body']);

        $hmr_file = $this->plugin_path . 'includes/Core/Admin/Hmr.php';

        if (file_exists($hmr_file)) {
            require_once $hmr_file;
            $hmr = new Hmr($this->plugin_path);
            $hmr->enqueue_assets();
        } else {
            $this->enqueue_prod_assets();
        }

        $this->enqueue_admin_fixes();
        $this->enqueue_wp_media_editor();
        $this->inject_config();
    }

    /**
     * Take wp-admin's unused stylesheets off this one screen.
     *
     * This has to happen HERE, not with wp_dequeue_style(). `wp-admin` is a meta handle that
     * DEPENDS on `forms`, so dequeuing `forms` silently does nothing - dependency resolution
     * puts it straight back. `print_styles_array` fires inside WP_Styles::all_deps(), AFTER
     * resolution, on the final list of handles about to print, so a handle removed here is
     * removed even though something depends on it, and no core object is mutated to do it.
     *
     * @param string[] $handles Handles WordPress is about to print.
     * @return string[]
     */
    public function strip_unused_admin_styles(array $handles): array {
        if (!$this->on_plugin_screen) {
            return $handles;
        }

        return array_values(array_diff($handles, self::DROP_STYLES));
    }

    /**
     * Put a class on this screen's <body>.
     *
     * Used by admin-fixes.css for the footer rule and the focus-ring reset. Body scope
     * rather than #plugixatestimonial-root: the footer lives outside the mount point
     * anyway, and it keeps the focus reset independent of MUI's portal-container config.
     * (This app's Emotion CacheProvider currently keeps portals inside the mount point,
     * but a family-wide default of body scope is one selector either way.)
     *
     * admin_body_class runs after admin_enqueue_scripts, so the flag is already set.
     *
     * @param string $classes Existing body classes.
     * @return string
     */
    public function mark_body($classes) {
        return $this->on_plugin_screen ? trim($classes . ' ' . self::BODY_CLASS) : $classes;
    }

    private function enqueue_wp_media_editor(): void {
        $this->remove_elementor_media_hooks();
        wp_enqueue_media();
        wp_enqueue_editor();
        wp_enqueue_style('editor-buttons');
    }

    /**
     * Remove Elementor AI's wp_enqueue_media hook to prevent ~1.9MB of unnecessary scripts.
     * Elementor loads ui.min.js (886KB), ai-media-library.min.js (619KB), icons.min.js (366KB)
     * on every page that calls wp_enqueue_media() — none of these are needed in our admin.
     */
    private function remove_elementor_media_hooks(): void {
        global $wp_filter;

        if (!isset($wp_filter['wp_enqueue_media'])) {
            return;
        }

        foreach ($wp_filter['wp_enqueue_media']->callbacks as $priority => $hooks) {
            foreach ($hooks as $key => $hook) {
                if (is_array($hook['function']) &&
                    is_object($hook['function'][0]) &&
                    get_class($hook['function'][0]) === 'Elementor\Modules\Ai\Module') {
                    remove_action('wp_enqueue_media', $hook['function'], $priority);
                }
            }
        }
    }

    private function enqueue_admin_fixes(): void {
        $css_file = $this->plugin_path . 'assets/backend/css/admin-fixes.css';

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'plugixa-testimonial-admin-fixes',
                $this->plugin_url . 'assets/backend/css/admin-fixes.css',
                [],
                $this->version
            );
        }
    }

    private function enqueue_prod_assets(): void {
        // The premium overlay ships its own admin bundle (built with the pro modules
        // registered into the @pro registry); when present it supersedes the free one.
        // Freemius strips pro/ from the free build, so the free ZIP falls back to
        // assets/backend/build automatically.
        $build_rel = is_dir($this->plugin_path . 'pro/assets/backend/build')
            ? 'pro/assets/backend/build/'
            : 'assets/backend/build/';

        $build_path = $this->plugin_path . $build_rel;
        $build_url = $this->plugin_url . $build_rel;

        $manifest_file = $build_path . '.vite/manifest.json';
        $entry_file = 'js/main.js';
        $chunks = [];

        if (file_exists($manifest_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a plugin-local file we wrote ourselves (build manifest / cached font), not a remote URL; WP_Filesystem would add an FTP-credentials round-trip for no gain.
            $manifest = json_decode(file_get_contents($manifest_file), true);

            $entry_key = isset($manifest['src/main.tsx']) ? 'src/main.tsx' : 'src/main.jsx';

            // Read hashed entry filename from manifest
            if (isset($manifest[$entry_key]['file'])) {
                $entry_file = $manifest[$entry_key]['file'];
            }

            if (isset($manifest[$entry_key]['imports'])) {
                foreach ($manifest[$entry_key]['imports'] as $import) {
                    if (isset($manifest[$import]['file'])) {
                        $chunks[] = $manifest[$import]['file'];
                    }
                }
            }
        }

        // Each chunk is a STATIC import of the entry module, so registering the chunks as
        // dependencies is an accurate description of the graph rather than a workaround.
        // Core then emits the <link rel="modulepreload"> hints itself, from
        // WP_Script_Modules::print_script_module_preloads(). Hand-building those link tags
        // here instead is what the wp.org "use the built-in functions to include JS/CSS"
        // review flag is about.
        $chunk_deps = [];
        foreach ($chunks as $chunk) {
            $chunk_id = 'plugixa-testimonial-' . sanitize_key(pathinfo($chunk, PATHINFO_FILENAME));
            wp_register_script_module($chunk_id, $build_url . $chunk, [], null);
            $chunk_deps[] = $chunk_id;
        }

        // Admin app CSS (Tailwind pxt utilities + preview styles). Vite emits it
        // as a fixed un-hashed name (cssCodeSplit:false) and the manifest entry
        // does not reference it, so it must be enqueued explicitly. The dev
        // server injects this CSS via JS, which is why HMR mode doesn't need it.
        $css_file = $build_path . 'css/main.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'plugixa-testimonial-app',
                $build_url . 'css/main.css',
                [],
                (string) filemtime($css_file)
            );
        }

        // Use null version — the hashed filename provides cache busting
        wp_enqueue_script_module(
            'plugixa-testimonial-app',
            $build_url . $entry_file,
            $chunk_deps,
            null
        );
    }

    private function get_initial_route(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; no state change.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'plugixa-testimonial';

        $routes = [
            'plugixa-testimonial'               => '/dashboard',
            'plugixa-testimonial-testimonials'   => '/testimonials',
            'plugixa-testimonial-categories'     => '/categories',
            'plugixa-testimonial-views'          => '/views',
            'plugixa-testimonial-shortcodes'     => '/shortcodes',
            'plugixa-testimonial-settings'       => '/settings',
        ];

        return $routes[$page] ?? '/dashboard';
    }

    private function inject_config(): void {
        $current_user = wp_get_current_user();

        $initial_route = $this->get_initial_route();

        $config = [
            'initialRoute'  => $initial_route,
            'apiUrl'        => esc_url_raw(rest_url('plugixatestimonial/v1/')),
            'adminUrl'      => esc_url(admin_url()),
            'homeUrl'       => esc_url_raw(home_url('/')),
            // For the account menu. Carries its own nonce, as every WordPress logout link does.
            'logoutUrl'     => esc_url_raw(wp_logout_url(admin_url())),
            'pluginUrl'     => esc_url($this->plugin_url),
            'nonce'         => wp_create_nonce('wp_rest'),
            'userId'        => get_current_user_id(),
            'siteName'      => get_bloginfo('name'),
            'permissions'   => $this->get_user_permissions(),
            'settings'      => $this->get_plugin_settings(),
            'i18n'          => $this->get_translations(),
            'preloadedData' => $this->get_preloaded_data($initial_route),
            'currentUser'   => [
                'id'           => $current_user->ID,
                'display_name' => $current_user->display_name,
                'email'        => $current_user->user_email,
                'login'        => $current_user->user_login,
            ],
            // Presence of the pro/ overlay is the single source of truth for "is this the
            // premium build". There is no runtime licence object: the free build ships no
            // pro UI to gate, so this flag is purely informational (it lets a screen skip
            // a query whose route does not exist in free).
            'isPro'         => is_dir(PLUGIXA_TESTIMONIAL_PATH . 'pro/'),
        ];

        add_action('admin_head', function() use ($config) {
            // Emitted through the core inline-script API rather than a literal <script>
            // tag: wp_print_inline_script_tag() applies the script filters and the CSP
            // nonce handling that a hand-written tag bypasses.
            //
            // Early-fetch: start API calls based on the hash route BEFORE React loads,
            // which buys roughly a 2s head start on the first screen.
            $early_fetch = <<<'JS'
(function(){
  var c = window.PlugixaTestimonialConfig, h = location.hash.replace('#','');
  if (!h || !c.apiUrl || !c.nonce) return;
  var hdr = {'X-WP-Nonce': c.nonce}, url;
  var edit = h.match(/^\/(testimonials|categories|custom-fields|views|shortcodes|requests)\/(\d+)$/);
  if (edit) {
    url = c.apiUrl + edit[1] + '/' + edit[2];
  } else if (!(c.preloadedData && c.preloadedData[h])) {
    var lists = {
      '/testimonials': 'testimonials?limit=20&offset=0&group_by_translation=1',
      '/categories': 'categories?limit=20&offset=0&group_by_translation=1',
      '/custom-fields': 'custom-fields?limit=20&offset=0&group_by_translation=1',
      '/views': 'views?limit=20&offset=0',
      '/shortcodes': 'shortcodes?limit=100&offset=0',
      '/requests': 'requests?limit=20&offset=0',
      '/dashboard': 'settings/dashboard'
    };
    if (lists[h]) url = c.apiUrl + lists[h];
  }
  if (url) {
    window.plugixaTestimonialPrefetch = fetch(url, {headers: hdr}).then(function(r){ return r.json() });
    window.plugixaTestimonialPrefetchRoute = h;
  }
})();
JS;

            wp_print_inline_script_tag(
                'window.PlugixaTestimonialConfig = ' . wp_json_encode($config) . ';' . $early_fetch
            );
        });
    }

    /**
     * Preload initial page data to eliminate the API waterfall on first render.
     * Uses rest_do_request() to internally call our REST endpoints.
     */
    private function get_preloaded_data(string $route): array {
        $preload_map = [
            '/testimonials'  => ['/plugixatestimonial/v1/testimonials', ['limit' => 20, 'offset' => 0, 'group_by_translation' => '1']],
            '/categories'    => ['/plugixatestimonial/v1/categories', ['limit' => 20, 'offset' => 0, 'group_by_translation' => '1']],
            '/custom-fields' => ['/plugixatestimonial/v1/custom-fields', ['limit' => 20, 'offset' => 0, 'group_by_translation' => '1']],
            '/views'         => ['/plugixatestimonial/v1/views', ['limit' => 20, 'offset' => 0]],
            '/shortcodes'    => ['/plugixatestimonial/v1/shortcodes', ['limit' => 100, 'offset' => 0]],
            '/requests'      => ['/plugixatestimonial/v1/requests', ['limit' => 20, 'offset' => 0]],
            '/dashboard'     => ['/plugixatestimonial/v1/settings/dashboard', []],
        ];

        if (!isset($preload_map[$route])) {
            return [];
        }

        [$endpoint, $params] = $preload_map[$route];

        $request = new \WP_REST_Request('GET', $endpoint);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);

        if ($response->is_error()) {
            return [];
        }

        return [$route => $response->get_data()];
    }

    /**
     * Capabilities the React admin uses to decide which controls to render.
     *
     * The three core capabilities at the bottom are not decoration: RestController::can()
     * accepts either the custom capability OR a core fallback, so without them the client
     * cannot reproduce the decision the REST layer is about to make. An Editor, who passes
     * every delete gate server-side through delete_others_posts, would otherwise compute
     * canDelete() === false and be shown a UI more restricted than their actual access.
     *
     * Keep this list in step with RestController's gates and with usePermissions.ts.
     */
    private function get_user_permissions(): array {
        return [
            'manage_options'              => current_user_can('manage_options'),
            'plugixa_testimonial_manage'  => current_user_can('plugixa_testimonial_manage'),
            'plugixa_testimonial_edit'    => current_user_can('plugixa_testimonial_edit'),
            'plugixa_testimonial_delete'  => current_user_can('plugixa_testimonial_delete'),
            'plugixa_testimonial_approve' => current_user_can('plugixa_testimonial_approve'),
            'plugixa_testimonial_settings'=> current_user_can('plugixa_testimonial_settings'),
            // Core fallbacks mirrored from RestController::can().
            'edit_posts'                  => current_user_can('edit_posts'),
            'delete_others_posts'         => current_user_can('delete_others_posts'),
            'publish_posts'               => current_user_can('publish_posts'),
        ];
    }

    private function get_plugin_settings(): array {
        $defaults = [
            'items_per_page'   => 20,
            'default_status'   => 'pending',
            'auto_approve'     => false,
            'default_rating'   => 5,
            'show_empty_stars' => true,
            'date_format'      => 'M j, Y',
            'excerpt_length'   => 150,
        ];

        return array_merge($defaults, (array) get_option('plugixatestimonial_settings', []));
    }

    private function get_translations(): array {
        $strings = [
            'save'               => __('Save', 'plugixa-testimonial'),
            'cancel'             => __('Cancel', 'plugixa-testimonial'),
            'delete'             => __('Delete', 'plugixa-testimonial'),
            'edit'               => __('Edit', 'plugixa-testimonial'),
            'add'                => __('Add', 'plugixa-testimonial'),
            'search'             => __('Search', 'plugixa-testimonial'),
            'loading'            => __('Loading...', 'plugixa-testimonial'),
            'error'              => __('An error occurred', 'plugixa-testimonial'),
            'success'            => __('Success', 'plugixa-testimonial'),
            'confirm_delete'     => __('Are you sure you want to delete?', 'plugixa-testimonial'),
            'no_results'         => __('No results found', 'plugixa-testimonial'),
            'dashboard'          => __('Dashboard', 'plugixa-testimonial'),
            'testimonials'       => __('Testimonials', 'plugixa-testimonial'),
            'categories'         => __('Categories', 'plugixa-testimonial'),
            'settings'           => __('Settings', 'plugixa-testimonial'),
            'add_testimonial'    => __('Add Testimonial', 'plugixa-testimonial'),
            'edit_testimonial'   => __('Edit Testimonial', 'plugixa-testimonial'),
            'client_name'        => __('Client Name', 'plugixa-testimonial'),
            'testimonial_text'   => __('Testimonial Text', 'plugixa-testimonial'),
            'rating'             => __('Rating', 'plugixa-testimonial'),
            'status'             => __('Status', 'plugixa-testimonial'),
            'pending'            => __('Pending', 'plugixa-testimonial'),
            'approved'           => __('Approved', 'plugixa-testimonial'),
            'rejected'           => __('Rejected', 'plugixa-testimonial'),
            'draft'              => __('Draft', 'plugixa-testimonial'),
            'featured'           => __('Featured', 'plugixa-testimonial'),
            'views'              => __('Views', 'plugixa-testimonial'),
            'shortcodes'         => __('Shortcodes', 'plugixa-testimonial'),
            'add_category'       => __('Add Category', 'plugixa-testimonial'),
            'edit_category'      => __('Edit Category', 'plugixa-testimonial'),
            'category_name'      => __('Category Name', 'plugixa-testimonial'),
            'description'        => __('Description', 'plugixa-testimonial'),
        ];

        /**
         * Admin i18n seam.
         *
         * The premium overlay adds the labels for its own screens (Google Reviews, PDF
         * export, demo data) here — the keys match the `labelKey` values its React modules
         * register into the proRegistry. The free build therefore ships neither the keys
         * nor the strings.
         *
         * @param array $strings Key => translated label.
         */
        return apply_filters('plugixatestimonial_admin_i18n_strings', $strings);
    }
}
