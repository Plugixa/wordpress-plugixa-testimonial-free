<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\GoogleFonts;

if (!defined('ABSPATH')) { exit; }

/**
 * Font Manager — self-hosts Google Fonts.
 *
 * WordPress.org forbids loading fonts from third-party CDNs on the public
 * frontend (it leaks visitor IPs to Google). When an administrator selects a
 * Google font for a view's typography, this class downloads that font's
 * stylesheet and woff2 files from Google to the uploads directory (an
 * admin-initiated, disclosed request — see readme "External services"), then
 * the frontend serves the cached copy locally. No visitor data ever reaches
 * Google when the frontend is viewed.
 *
 * Saving a view queues the download in the background (wp-cron) so the REST
 * save stays fast — a single webfont can resolve to dozens of woff2 files
 * (weights x language subsets). The frontend only ever reads the cached copy;
 * if a family was never localized (e.g. a view saved before this feature, a
 * non-Google/system font, a failed download, or the cron has not run yet) the
 * frontend simply enqueues nothing and the CSS font-family fallback applies —
 * it never calls Google.
 */
class FontManager {

    /** Sub-directory created under wp-content/uploads/. */
    const DIR_NAME = 'plugixatestimonial-fonts';

    /** Weights requested from the Google CSS2 API (matches the typography UI). */
    const WEIGHTS = '300;400;500;600;700';

    /**
     * A modern-browser User-Agent. Google's CSS2 API serves woff2 only to
     * browsers it recognises; with the default WordPress UA it returns older
     * formats (or ttf). This guarantees woff2.
     */
    const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** Background cron hook that performs the (slow) download out of band. */
    const CRON_HOOK = 'plugixatestimonial_localize_fonts';

    /**
     * Wire up the background download handler. Call once during plugin boot so
     * wp-cron can run it on its loopback request.
     */
    public static function register(): void {
        add_action(self::CRON_HOOK, [self::class, 'localize_families']);
    }

    /**
     * Queue a background download for any families in a view config that are
     * not already cached. Used at save time so the REST request stays fast — a
     * Google webfont can resolve to dozens of woff2 files (weights x language
     * subsets), which is far too slow to fetch synchronously.
     */
    public static function schedule_config(array $config): void {
        $pending = array_values(array_filter(
            self::get_font_families_from_config($config),
            static fn($family) => self::get_local_css_url($family) === null
        ));

        if (empty($pending)) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK, [$pending])) {
            wp_schedule_single_event(time(), self::CRON_HOOK, [$pending]);
        }
    }

    /**
     * Cron callback: download + cache each family.
     *
     * @param string[] $families
     */
    public static function localize_families(array $families): void {
        foreach ($families as $family) {
            self::localize($family);
        }
    }

    /**
     * Resolve the uploads dir + url base for cached fonts.
     *
     * @return array{dir:string,url:string}|null Null if uploads is unavailable.
     */
    private static function paths(): ?array {
        $up = wp_upload_dir();
        if (!empty($up['error'])) {
            return null;
        }
        return [
            'dir' => trailingslashit($up['basedir']) . self::DIR_NAME,
            'url' => trailingslashit($up['baseurl']) . self::DIR_NAME,
        ];
    }

    /**
     * Stable directory/handle slug for a font family ("Open Sans" -> "open-sans").
     */
    public static function slug(string $family): string {
        return sanitize_title(self::clean_family($family));
    }

    /**
     * Strip any CSS fallback list/quotes from a stored family value so only the
     * bare family name is sent to Google ("\"Open Sans\", sans-serif" -> "Open Sans").
     */
    private static function clean_family(string $family): string {
        $first = explode(',', $family)[0];
        return trim($first, " \t\n\r\0\x0B\"'");
    }

    /**
     * Pull the non-`inherit` Google-candidate families out of a view config.
     *
     * @return string[] Unique bare family names.
     */
    public static function get_font_families_from_config(array $config): array {
        $families = [];

        $typo = $config['typography'] ?? [];
        foreach (['textFontFamily', 'nameFontFamily', 'roleFontFamily'] as $key) {
            $val = $typo[$key] ?? 'inherit';
            if ($val !== 'inherit' && $val !== '') {
                $families[] = $val;
            }
        }

        $cat = $config['categories']['typography']['fontFamily'] ?? 'inherit';
        if ($cat !== 'inherit' && $cat !== '') {
            $families[] = $cat;
        }

        return array_values(array_unique($families));
    }

    /**
     * Return the local CSS URL for a family if it has already been cached,
     * else null. Safe to call on the frontend — never hits the network.
     */
    public static function get_local_css_url(string $family): ?string {
        $slug = self::slug($family);
        if ($slug === '') {
            return null;
        }

        $paths = self::paths();
        if ($paths === null) {
            return null;
        }

        $css = $paths['dir'] . '/' . $slug . '/font.css';
        if (file_exists($css)) {
            return $paths['url'] . '/' . $slug . '/font.css';
        }

        return null;
    }

    /**
     * Download + cache a single family if not already cached.
     *
     * @return string|null Local CSS URL, or null if it could not be self-hosted
     *                     (network error, non-Google/system font, or no woff2).
     */
    public static function localize(string $family): ?string {
        $existing = self::get_local_css_url($family);
        if ($existing !== null) {
            return $existing;
        }

        $slug = self::slug($family);
        $paths = self::paths();
        if ($slug === '' || $paths === null) {
            return null;
        }

        $bare = self::clean_family($family);

        // 1. Fetch the @font-face stylesheet from Google (admin-initiated).
        $api = 'https://fonts.googleapis.com/css2?family=' . rawurlencode($bare)
            . ':wght@' . self::WEIGHTS . '&display=swap';
        $resp = wp_remote_get($api, [
            'timeout'    => 20,
            'user-agent' => self::UA,
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        $css = wp_remote_retrieve_body($resp);
        if ($css === '') {
            return null;
        }

        // 2. Collect the gstatic woff2 URLs referenced by the stylesheet.
        if (!preg_match_all('#https://fonts\.gstatic\.com/[^)\'"\s]+\.woff2#i', $css, $matches)) {
            return null; // Not a Google webfont (e.g. a system font) — nothing to host.
        }

        if (!self::init_fs()) {
            return null;
        }
        global $wp_filesystem;

        $font_dir = $paths['dir'] . '/' . $slug;
        $font_url = $paths['url'] . '/' . $slug;
        if (!$wp_filesystem->is_dir($font_dir) && !wp_mkdir_p($font_dir)) {
            return null;
        }

        // 3. Download each woff2 locally and map remote -> local URL.
        $map = [];
        foreach (array_unique($matches[0]) as $remote) {
            $local_name = md5($remote) . '.woff2';
            $local_path = $font_dir . '/' . $local_name;

            if (!$wp_filesystem->exists($local_path)) {
                $bin = wp_remote_get($remote, [
                    'timeout'    => 20,
                    'user-agent' => self::UA,
                ]);
                if (is_wp_error($bin) || (int) wp_remote_retrieve_response_code($bin) !== 200) {
                    continue;
                }
                $wp_filesystem->put_contents($local_path, wp_remote_retrieve_body($bin), FS_CHMOD_FILE);
            }

            $map[$remote] = $font_url . '/' . $local_name;
        }

        if (empty($map)) {
            return null;
        }

        // 4. Rewrite the stylesheet to point at the local files and cache it.
        $css = strtr($css, $map);
        if (!$wp_filesystem->put_contents($font_dir . '/font.css', $css, FS_CHMOD_FILE)) {
            return null;
        }

        return $font_url . '/font.css';
    }

    /**
     * Remove the entire cached-fonts directory. Called on uninstall.
     */
    public static function cleanup(): void {
        $paths = self::paths();
        if ($paths === null || !self::init_fs()) {
            return;
        }
        global $wp_filesystem;
        if ($wp_filesystem->is_dir($paths['dir'])) {
            $wp_filesystem->delete($paths['dir'], true);
        }
    }

    /**
     * Boot WP_Filesystem (direct method on typical hosts). Returns false if the
     * host requires interactive credentials — callers degrade gracefully.
     */
    private static function init_fs(): bool {
        global $wp_filesystem;
        if ($wp_filesystem) {
            return true;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        return WP_Filesystem();
    }
}
