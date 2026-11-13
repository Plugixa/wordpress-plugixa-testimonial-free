<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Core;

defined('ABSPATH') || exit;

/**
 * Lightweight object-cache helper for the plugin's custom tables.
 *
 * Uses the WordPress core "last_changed" incremental-key pattern: each entity has its own
 * cache group, and every cached value's key embeds that group's `last_changed` token.
 * Bumping the token on a write makes all previously-cached reads for that entity
 * unreachable — effective invalidation without having to enumerate keys.
 *
 * With WordPress's default (non-persistent) object cache this de-duplicates repeated reads
 * within a single request; with a persistent backend (Redis / Memcached) it also caches
 * across requests. Either way it satisfies the `WordPress.DB.DirectDatabaseQuery.NoCaching`
 * requirement for the content reads, which is why Plugin Check stops complaining about them.
 *
 * NOTE: high-frequency, non-content writes (the per-render `view_count` increment in
 * FrontendApi) deliberately do NOT call flush() — otherwise every single page view would
 * invalidate the whole cache and the layer would be worse than useless. Cached lists
 * therefore carry eventually-consistent view counts, which is the right trade.
 */
class Cache {

    /**
     * Entities backed by a custom table. Each maps to its own cache group.
     *
     * Keep in sync with Activator::create_tables() and Uninstaller::TABLES.
     *
     * @var string[]
     */
    const ENTITIES = [
        'testimonials',
        'categories',
        'views',
        'shortcodes',
        'requests',
        'custom_fields',
        'testimonial_meta',
    ];

    /**
     * Cache group name for an entity.
     */
    private static function group(string $entity): string {
        return 'plugixatestimonial_' . $entity;
    }

    /**
     * Current "last changed" token for an entity's group (created on first use).
     */
    private static function last_changed(string $entity): string {
        $group = self::group($entity);
        $token = wp_cache_get('last_changed', $group);

        if (false === $token) {
            $token = microtime();
            wp_cache_set('last_changed', $token, $group);
        }

        return (string) $token;
    }

    /**
     * Read-through cache.
     *
     * @param string   $entity     One of self::ENTITIES.
     * @param string   $key_suffix MUST capture every input that changes the result
     *                             (filters, sort, pagination, ids, language, …), or two
     *                             different queries will collide on one cache entry.
     * @param callable $callback   Produces the value on a cache miss (the $wpdb read).
     * @return mixed The cached or freshly-computed value.
     */
    public static function remember(string $entity, string $key_suffix, callable $callback) {
        $group = self::group($entity);
        $key   = md5($key_suffix) . ':' . self::last_changed($entity);

        $found = false;
        $value = wp_cache_get($key, $group, false, $found);
        if ($found) {
            return $value;
        }

        $value = $callback();
        wp_cache_set($key, $value, $group);

        return $value;
    }

    /**
     * Invalidate one or more entities. Call after EVERY content write (insert / update /
     * delete that changes what reads return). A missed flush() shows up as stale data,
     * which no static check can catch — so when in doubt, flush.
     *
     * @param string ...$entities Entities to invalidate.
     */
    public static function flush(string ...$entities): void {
        foreach ($entities as $entity) {
            wp_cache_set('last_changed', microtime(), self::group($entity));
        }
    }

    /**
     * Invalidate everything the plugin caches (used on settings save / demo reset).
     */
    public static function flush_all(): void {
        self::flush(...self::ENTITIES);
    }

    /**
     * Hook automatic invalidation. Idempotent; called from Plugin::init_hooks().
     *
     * Invalidation is centralised on the REST lifecycle rather than sprinkled after
     * each of the ~86 $wpdb->insert/update/delete call sites. A flush that is merely
     * *forgotten* at one of those sites shows up as stale content and no static check
     * can catch it — so the rule here is structural: any non-read request that reaches
     * one of this plugin's own routes invalidates the caches on its way out.
     *
     * The cost is one wp_cache_set() per write request. Reads (GET/HEAD) never flush,
     * which is the case that actually needs to be fast.
     */
    public static function register(): void {
        if (has_filter('rest_request_after_callbacks', [self::class, 'maybe_flush_after_rest'])) {
            return;
        }

        add_filter('rest_request_after_callbacks', [self::class, 'maybe_flush_after_rest'], 10, 3);
    }

    /**
     * Flush after any write to one of this plugin's REST routes.
     *
     * @param mixed            $response Result to send to the client.
     * @param array            $handler  Route handler used for the request.
     * @param \WP_REST_Request $request  Request used to generate the response.
     * @return mixed The untouched $response.
     */
    public static function maybe_flush_after_rest($response, $handler, $request) {
        if (!$request instanceof \WP_REST_Request) {
            return $response;
        }

        if (strpos((string) $request->get_route(), '/plugixatestimonial/') !== 0) {
            return $response;
        }

        if (in_array(strtoupper($request->get_method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }

        self::flush_all();

        return $response;
    }
}
