<?php
/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace PlugixaTestimonial\Modules\ImportExport\Http;

if (!defined('ABSPATH')) { exit; }

use PlugixaTestimonial\Core\Http\RestController;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaTestimonial\Core\Multilingual;

class ImportExportController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'import-export';
    }

    public function register_routes(): void {
        // Export endpoints
        register_rest_route($this->namespace, '/' . $this->rest_base . '/export/csv', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'export_csv'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/export/json', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'export_json'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        // Import endpoints
        register_rest_route($this->namespace, '/' . $this->rest_base . '/import/validate', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'import_validate'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/import/execute', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'import_execute'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);
    }

    // =========================================================================
    // EXPORT: CSV
    // =========================================================================

    public function export_csv(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $items = $this->get_export_items($request);

        if (is_wp_error($items)) {
            return $items;
        }

        if (empty($items)) {
            return $this->error(__('No testimonials to export', 'plugixa-testimonial'), 404);
        }

        $custom_field_keys = $this->get_all_custom_field_keys();

        // Build CSV
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        $output = fopen('php://temp', 'r+');

        // Headers
        $headers = [
            'id', 'lang', 'translation_group', 'client_name', 'client_role', 'client_company',
            'testimonial_text', 'rating', 'video_url', 'website_url', 'status',
            'is_featured', 'source', 'categories', 'created_at',
        ];
        $headers = array_merge($headers, $custom_field_keys);

        // $escape is passed explicitly at EVERY CSV call in this file. PHP 8.4 emits a
        // deprecation when it is omitted, and PHP 9 changes the default from "\\" to "".
        //
        // "" (RFC 4180, and PHP 9's coming default) is used rather than PHP's
        // proprietary "\\" escaping because "\\" LOSES DATA: a field ending in a
        // backslash escapes the delimiter that follows it, so ['a\', 'b', 'c'] exports
        // and re-imports as TWO fields, not three - the plugin could not correctly
        // round-trip its own export. "" round-trips cleanly, parses previously-exported
        // files correctly (verifiably better than the old reader did), and matches what
        // Excel and Google Sheets actually write.
        fputcsv($output, $headers, ',', '"', '');

        // Rows
        foreach ($items as $item) {
            $row = [
                $item['id'],
                $item['lang'] ?? '',
                $item['translation_group'] ?? '',
                $item['client_name'],
                $item['client_role'] ?? '',
                $item['client_company'] ?? '',
                $item['testimonial_text'],
                $item['rating'],
                $item['video_url'] ?? '',
                $item['website_url'] ?? '',
                $item['status'],
                $item['is_featured'] ? '1' : '0',
                $item['source'] ?? '',
                implode(', ', array_column($item['categories'] ?? [], 'name')),
                $item['created_at'],
            ];

            // Custom fields
            foreach ($custom_field_keys as $key) {
                $row[] = $item['custom_fields'][$key] ?? '';
            }

            fputcsv($output, $row, ',', '"', '');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        fclose($output);

        $filename = 'testimonials-export-' . gmdate('Y-m-d') . '.csv';

        $this->send_file_download($csv, $filename, 'text/csv');
    }

    // =========================================================================
    // EXPORT: JSON
    // =========================================================================

    public function export_json(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $items = $this->get_export_items($request);

        if (is_wp_error($items)) {
            return $items;
        }

        if (empty($items)) {
            return $this->error(__('No testimonials to export', 'plugixa-testimonial'), 404);
        }

        $export = [
            'version'     => '1.0',
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'plugin'      => 'plugixa-testimonial',
            'count'       => count($items),
            'testimonials' => array_values(array_map(function ($item) {
                return [
                    'id'                => (int) $item['id'],
                    'lang'              => $item['lang'] ?? '',
                    'translation_group' => (int) ($item['translation_group'] ?? 0),
                    'client_name'       => $item['client_name'],
                    'client_role'       => $item['client_role'] ?? '',
                    'client_company'    => $item['client_company'] ?? '',
                    'testimonial_text'  => $item['testimonial_text'],
                    'rating'            => (int) $item['rating'],
                    'video_url'         => $item['video_url'] ?? '',
                    'website_url'       => $item['website_url'] ?? '',
                    'status'            => $item['status'],
                    'is_featured'       => (bool) $item['is_featured'],
                    'source'            => $item['source'] ?? '',
                    'categories'        => $item['categories'] ?? [],
                    'custom_fields'     => $item['custom_fields'] ?? (object) [],
                    'created_at'        => $item['created_at'],
                ];
            }, $items)),
        ];

        $json = wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'testimonials-export-' . gmdate('Y-m-d') . '.json';

        $this->send_file_download($json, $filename, 'application/json');
    }

    // =========================================================================
    // IMPORT: Validate (upload + parse + preview)
    // =========================================================================

    public function import_validate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $files = $request->get_file_params();

        if (empty($files['file'])) {
            return $this->error(__('No file uploaded', 'plugixa-testimonial'), 400);
        }

        $file = $files['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'json'], true)) {
            return $this->error(__('Only CSV and JSON files are supported', 'plugixa-testimonial'), 400);
        }

        // Validate file size (max 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            return $this->error(__('File size must be under 10MB', 'plugixa-testimonial'), 400);
        }

        // Read file content
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a plugin-local file we wrote ourselves (build manifest / cached font), not a remote URL; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return $this->error(__('Failed to read uploaded file', 'plugixa-testimonial'), 500);
        }

        // Parse based on format
        if ($ext === 'csv') {
            $result = $this->parse_csv($content);
        } else {
            $result = $this->parse_json($content);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        // Store temp file for execute step
        $upload_dir = wp_upload_dir();
        $import_dir = $upload_dir['basedir'] . '/plugixatestimonial-imports';

        if (!file_exists($import_dir)) {
            wp_mkdir_p($import_dir);
            // Protect directory
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes into the plugin own uploads cache directory; WP_Filesystem would add an FTP-credentials round-trip for no gain.
            file_put_contents($import_dir . '/.htaccess', "Deny from all\n");
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes into the plugin own uploads cache directory; WP_Filesystem would add an FTP-credentials round-trip for no gain.
            file_put_contents($import_dir . '/index.php', "<?php // Silence is golden.\n");
        }

        $token = wp_generate_password(32, false);
        $stored_path = $import_dir . '/' . $token . '.' . $ext;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes into the plugin own uploads cache directory; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        file_put_contents($stored_path, $content);

        // Auto-map columns
        $suggested_mapping = $this->auto_map_columns($result['columns']);

        return $this->success([
            'format'            => $ext,
            'total_rows'        => $result['total_rows'],
            'columns'           => $result['columns'],
            'preview'           => $result['preview'],
            'suggested_mapping' => $suggested_mapping,
            'file_token'        => $token,
        ]);
    }

    // =========================================================================
    // IMPORT: Execute (batch insert)
    // =========================================================================

    public function import_execute(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $file_token = sanitize_text_field($request->get_param('file_token') ?? '');
        $format = sanitize_key($request->get_param('format') ?? '');
        $mapping = $request->get_param('mapping');
        $options = $request->get_param('options') ?? [];

        if (empty($file_token) || empty($format) || empty($mapping)) {
            return $this->error(__('Missing required parameters', 'plugixa-testimonial'), 400);
        }

        // Validate token format (alphanumeric only)
        if (!preg_match('/^[a-zA-Z0-9]+$/', $file_token)) {
            return $this->error(__('Invalid file token', 'plugixa-testimonial'), 400);
        }

        if (!in_array($format, ['csv', 'json'], true)) {
            return $this->error(__('Invalid format', 'plugixa-testimonial'), 400);
        }

        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/plugixatestimonial-imports/' . $file_token . '.' . $format;

        if (!file_exists($file_path)) {
            return $this->error(__('Import file not found. Please upload again.', 'plugixa-testimonial'), 404);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a plugin-local file we wrote ourselves (build manifest / cached font), not a remote URL; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        $content = file_get_contents($file_path);

        // Parse all rows
        if ($format === 'csv') {
            $parsed = $this->parse_csv($content, false);
        } else {
            $parsed = $this->parse_json($content, false);
        }

        if (is_wp_error($parsed)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
            @unlink($file_path);
            return $parsed;
        }

        $default_status = sanitize_key($options['default_status'] ?? 'pending');
        $duplicate_handling = sanitize_key($options['duplicate_handling'] ?? 'skip');
        $create_categories = !empty($options['create_categories']);

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $created_categories = [];

        $rows = $parsed['rows'];

        // Process in batches
        foreach (array_chunk($rows, 50, true) as $chunk) {
            foreach ($chunk as $row_index => $row) {
                $result = $this->import_single_row(
                    $row,
                    $mapping,
                    $default_status,
                    $duplicate_handling,
                    $create_categories,
                    $created_categories,
                    $row_index + 2 // +2: 1-indexed + header row
                );

                if ($result === true) {
                    $imported++;
                } elseif ($result === false) {
                    $skipped++;
                } else {
                    $errors[] = $result;
                }
            }
        }

        // Cleanup temp file
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        @unlink($file_path);

        return $this->success([
            'imported'           => $imported,
            'skipped'            => $skipped,
            'errors'             => count($errors),
            'error_details'      => array_slice($errors, 0, 50),
            'created_categories' => array_values(array_unique($created_categories)),
        ], sprintf(
            /* translators: %d: number of testimonials imported */
            __('%d testimonials imported successfully', 'plugixa-testimonial'),
            $imported
        ));
    }

    // =========================================================================
    // PRIVATE: Query builder for exports (mirrors TestimonialsController)
    // =========================================================================

    protected function get_export_items(WP_REST_Request $request): array|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';

        // If specific IDs provided, use those
        $ids = $request->get_param('ids');
        if (!empty($ids) && is_array($ids)) {
            $ids = array_map('intval', $ids);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM %i WHERE FIND_IN_SET(id, %s) ORDER BY created_at DESC", $table,
                    implode(',', $ids)
                ),
                ARRAY_A
            );
        } else {
            // Use filters (same pattern as TestimonialsController::get_items)
            $filters = $this->get_filters($request);

            // Bound gates inside one constant-literal WHERE; gate 0 disables its test.
            // The pivot is ALWAYS LEFT JOINed because a join TYPE cannot be bound as a
            // value; FIND_IN_SET fails on the NULL side, so gating it reproduces the
            // old INNER JOIN semantics exactly, and DISTINCT already collapsed the
            // fan-out before this change.
            $search_gate = !empty($filters['search']) ? 1 : 0;
            $search      = $search_gate ? '%' . $wpdb->esc_like($filters['search']) . '%' : '%';

            $status_gate = !empty($filters['status']) ? 1 : 0;
            $status_csv  = '';
            if ($status_gate) {
                $status_csv = is_array($filters['status'])
                    ? implode(',', array_map('sanitize_key', $filters['status']))
                    : sanitize_key($filters['status']);
            }

            $cat_gate = (!empty($filters['categories']) && is_array($filters['categories'])) ? 1 : 0;
            $cat_csv  = $cat_gate ? implode(',', array_map('intval', $filters['categories'])) : '';

            $rmin_gate = (isset($filters['rating_min']) && $filters['rating_min'] !== '' && $filters['rating_min'] !== null) ? 1 : 0;
            $rmin      = $rmin_gate ? (int) $filters['rating_min'] : 0;
            $rmax_gate = (isset($filters['rating_max']) && $filters['rating_max'] !== '' && $filters['rating_max'] !== null) ? 1 : 0;
            $rmax      = $rmax_gate ? (int) $filters['rating_max'] : 0;

            $feat_gate = (isset($filters['is_featured']) && $filters['is_featured'] !== '' && $filters['is_featured'] !== null) ? 1 : 0;
            $feat      = $feat_gate ? (int) $filters['is_featured'] : 0;

            // A disabled DATETIME gate still binds a VALID sentinel: MySQL in strict
            // mode aborts the whole query on '' rather than simply not matching.
            $dfrom_gate = !empty($filters['date_from']) ? 1 : 0;
            $dfrom      = $dfrom_gate ? sanitize_text_field($filters['date_from']) . ' 00:00:00' : '1000-01-01 00:00:00';
            $dto_gate   = !empty($filters['date_to']) ? 1 : 0;
            $dto        = $dto_gate ? sanitize_text_field($filters['date_to']) . ' 23:59:59' : '9999-12-31 23:59:59';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT t.*
                 FROM %i t
                 LEFT JOIN %i tc_filter ON t.id = tc_filter.testimonial_id
                 WHERE 1=1
                   AND ( %d = 0 OR ( t.client_name LIKE %s OR t.testimonial_text LIKE %s OR t.client_company LIKE %s ) )
                   AND ( %d = 0 OR FIND_IN_SET(t.status, %s) )
                   AND ( %d = 0 OR FIND_IN_SET(tc_filter.category_id, %s) )
                   AND ( %d = 0 OR t.rating >= %d )
                   AND ( %d = 0 OR t.rating <= %d )
                   AND ( %d = 0 OR t.is_featured = %d )
                   AND ( %d = 0 OR t.created_at >= %s )
                   AND ( %d = 0 OR t.created_at <= %s )
                 ORDER BY t.created_at DESC",
                $table,
                $pivot_table,
                $search_gate, $search, $search, $search,
                $status_gate, $status_csv,
                $cat_gate, $cat_csv,
                $rmin_gate, $rmin,
                $rmax_gate, $rmax,
                $feat_gate, $feat,
                $dfrom_gate, $dfrom,
                $dto_gate, $dto
            ), ARRAY_A);
        }

        if (!$items) {
            return [];
        }

        // Attach categories and custom fields
        $item_ids = array_column($items, 'id');
        $categories_map = $this->get_categories_for_items($item_ids);
        $meta_map = $this->get_meta_for_items($item_ids);

        foreach ($items as &$item) {
            $item['categories'] = $categories_map[$item['id']] ?? [];
            $item['custom_fields'] = $meta_map[$item['id']] ?? [];
        }

        return $items;
    }

    protected function get_categories_for_items(array $ids): array {
        global $wpdb;

        if (empty($ids)) {
            return [];
        }

        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $categories_table = $wpdb->prefix . 'plugixatestimonial_categories';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT tc.testimonial_id, c.id, c.name, c.slug FROM %i tc INNER JOIN %i c ON tc.category_id = c.id WHERE FIND_IN_SET(tc.testimonial_id, %s) ORDER BY c.name ASC",
            $pivot_table,
            $categories_table,
            implode(',', array_map('intval', $ids))
        ), ARRAY_A);

        $map = [];
        foreach ($results as $row) {
            $tid = $row['testimonial_id'];
            if (!isset($map[$tid])) {
                $map[$tid] = [];
            }
            $map[$tid][] = [
                'id'   => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
            ];
        }

        return $map;
    }

    protected function get_meta_for_items(array $ids): array {
        global $wpdb;

        if (empty($ids)) {
            return [];
        }

        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $results = $wpdb->get_results(
            $wpdb->prepare("SELECT testimonial_id, meta_key, meta_value FROM %i WHERE FIND_IN_SET(testimonial_id, %s)", $meta_table,
                implode(',', array_map('intval', $ids))
            ),
            ARRAY_A
        );

        $map = [];
        foreach ($results as $row) {
            $tid = (int) $row['testimonial_id'];
            if (!isset($map[$tid])) {
                $map[$tid] = [];
            }
            $map[$tid][$row['meta_key']] = $row['meta_value'];
        }

        return $map;
    }

    protected function get_all_custom_field_keys(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_custom_fields';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. The table name is bound as an identifier with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $keys = $wpdb->get_col($wpdb->prepare("SELECT field_key FROM %i ORDER BY menu_order ASC", $table));

        return $keys ?: [];
    }

    // =========================================================================
    // PRIVATE: File response helper
    // =========================================================================

    protected function send_file_download(string $content, string $filename, string $content_type): void {
        // Clear any output buffers to prevent content corruption
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // This is a file body (CSV or JSON) served under an attachment
        // Content-Disposition, not HTML rendered in a page. Any escaping here would
        // corrupt the download: HTML-encoding a CSV turns &, < and " in the data into
        // entities, and the browser never parses this response as markup. The bytes are
        // produced by build_csv()/wp_json_encode() above, and the route is behind
        // check_permission().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw file download body; see comment above.
        echo $content;
        exit;
    }

    // =========================================================================
    // PRIVATE: CSV/JSON parsing
    // =========================================================================

    private function parse_csv(string $content, bool $preview_only = true): array|WP_Error {
        $lines = str_getcsv_lines($content);

        if ($lines === false) {
            // Fallback: parse manually
            $lines = $this->csv_to_array($content);
        }

        if (empty($lines) || count($lines) < 2) {
            return $this->error(__('CSV file is empty or has no data rows', 'plugixa-testimonial'), 400);
        }

        $columns = $lines[0];
        $data_rows = array_slice($lines, 1);

        $preview = array_slice($data_rows, 0, 10);

        $result = [
            'columns'    => $columns,
            'total_rows' => count($data_rows),
            'preview'    => $preview,
        ];

        if (!$preview_only) {
            $result['rows'] = $data_rows;
        }

        return $result;
    }

    private function csv_to_array(string $content): array {
        $rows = [];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        $stream = fopen('php://temp', 'r+');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        fwrite($stream, $content);
        rewind($stream);

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        fclose($stream);
        return $rows;
    }

    private function parse_json(string $content, bool $preview_only = true): array|WP_Error {
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error(__('Invalid JSON file', 'plugixa-testimonial'), 400);
        }

        // Support our own export format (envelope with testimonials array)
        if (isset($data['testimonials']) && is_array($data['testimonials'])) {
            $items = $data['testimonials'];
        } elseif (isset($data[0]) && is_array($data[0])) {
            // Plain array of objects
            $items = $data;
        } else {
            return $this->error(__('JSON must contain a "testimonials" array or be an array of objects', 'plugixa-testimonial'), 400);
        }

        if (empty($items)) {
            return $this->error(__('No testimonials found in JSON', 'plugixa-testimonial'), 400);
        }

        // Extract column names from keys
        $columns = array_keys($items[0]);

        // Convert items to indexed arrays for consistent handling
        $data_rows = array_map(function ($item) use ($columns) {
            $row = [];
            foreach ($columns as $col) {
                $val = $item[$col] ?? '';
                // Convert arrays/objects to string for preview
                if (is_array($val)) {
                    $val = wp_json_encode($val);
                }
                $row[] = is_string($val) ? $val : (string) $val;
            }
            return $row;
        }, $items);

        $preview = array_slice($data_rows, 0, 10);

        $result = [
            'columns'    => $columns,
            'total_rows' => count($data_rows),
            'preview'    => $preview,
        ];

        if (!$preview_only) {
            // Store raw items for JSON (preserves types/nested data)
            $result['rows'] = $data_rows;
            $result['raw_items'] = $items;
        }

        return $result;
    }

    // =========================================================================
    // PRIVATE: Auto-mapping
    // =========================================================================

    private function auto_map_columns(array $columns): array {
        $mapping_dictionary = [
            // client_name
            'name'          => 'client_name',
            'client_name'   => 'client_name',
            'author'        => 'client_name',
            'customer'      => 'client_name',
            'reviewer'      => 'client_name',
            'full_name'     => 'client_name',
            'fullname'      => 'client_name',
            // client_role
            'role'          => 'client_role',
            'client_role'   => 'client_role',
            'title'         => 'client_role',
            'job_title'     => 'client_role',
            'position'      => 'client_role',
            'designation'   => 'client_role',
            // client_company
            'company'       => 'client_company',
            'client_company' => 'client_company',
            'organization'  => 'client_company',
            'business'      => 'client_company',
            // testimonial_text
            'testimonial'       => 'testimonial_text',
            'testimonial_text'  => 'testimonial_text',
            'text'              => 'testimonial_text',
            'review'            => 'testimonial_text',
            'content'           => 'testimonial_text',
            'message'           => 'testimonial_text',
            'comment'           => 'testimonial_text',
            'feedback'          => 'testimonial_text',
            // rating
            'rating'        => 'rating',
            'stars'         => 'rating',
            'score'         => 'rating',
            'star_rating'   => 'rating',
            // video_url
            'video'         => 'video_url',
            'video_url'     => 'video_url',
            'video_link'    => 'video_url',
            // website_url
            'website'       => 'website_url',
            'website_url'   => 'website_url',
            'url'           => 'website_url',
            'link'          => 'website_url',
            // status
            'status'        => 'status',
            // categories
            'categories'    => 'categories',
            'category'      => 'categories',
            'tags'          => 'categories',
            // is_featured
            'featured'      => 'is_featured',
            'is_featured'   => 'is_featured',
            // multilingual
            'lang'              => 'lang',
            'language'          => 'lang',
            'translation_group' => 'translation_group',
        ];

        $suggested = [];
        foreach ($columns as $col) {
            $normalized = strtolower(trim(str_replace([' ', '-'], '_', $col)));
            if (isset($mapping_dictionary[$normalized])) {
                $suggested[$col] = $mapping_dictionary[$normalized];
            }
        }

        return $suggested;
    }

    // =========================================================================
    // PRIVATE: Import a single row
    // =========================================================================

    private function import_single_row(
        array $row,
        array $mapping,
        string $default_status,
        string $duplicate_handling,
        bool $create_categories,
        array &$created_categories,
        int $row_number
    ): bool|array {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixatestimonial_testimonials';
        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';
        $categories_table = $wpdb->prefix . 'plugixatestimonial_categories';

        // Build mapped data from row
        $data = [];
        $categories_value = '';
        $custom_field_values = [];

        // Get defined custom field keys
        $custom_field_keys = $this->get_all_custom_field_keys();

        foreach ($mapping as $source_col => $target_field) {
            if (empty($target_field) || $target_field === '_skip') {
                continue;
            }

            // Find value by column index or name
            $value = is_numeric($source_col) ? ($row[(int) $source_col] ?? '') : ($row[$source_col] ?? '');

            if ($target_field === 'categories') {
                $categories_value = $value;
            } elseif (in_array($target_field, $custom_field_keys, true)) {
                $custom_field_values[$target_field] = sanitize_text_field($value);
            } else {
                $data[$target_field] = $value;
            }
        }

        // Validate required fields
        $client_name = sanitize_text_field($data['client_name'] ?? '');
        $testimonial_text = wp_kses_post($data['testimonial_text'] ?? '');

        if (empty($client_name)) {
            return ['row' => $row_number, 'message' => __('Missing client name', 'plugixa-testimonial')];
        }

        if (empty($testimonial_text)) {
            return ['row' => $row_number, 'message' => __('Missing testimonial text', 'plugixa-testimonial')];
        }

        // Check for duplicates
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $duplicate = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE client_name = %s AND testimonial_text = %s LIMIT 1", $table,
            $client_name,
            $testimonial_text
        ));

        if ($duplicate) {
            if ($duplicate_handling === 'skip') {
                return false; // skipped
            }
            // overwrite: update existing
            if ($duplicate_handling === 'overwrite') {
                $update_data = $this->build_testimonial_data($data, $default_status);
                $update_data['modified_at'] = current_time('mysql');
                $update_data['modified_by'] = get_current_user_id();

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->update($table, $update_data, ['id' => (int) $duplicate]);
                $testimonial_id = (int) $duplicate;

                // Handle categories + custom fields
                $this->handle_import_categories($testimonial_id, $categories_value, $create_categories, $created_categories);
                $this->handle_import_meta($testimonial_id, $custom_field_values);

                return true;
            }
        }

        // Insert new testimonial
        $insert_data = $this->build_testimonial_data($data, $default_status);
        $insert_data['source'] = 'import';
        $insert_data['created_at'] = current_time('mysql');
        $insert_data['created_by'] = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $result = $wpdb->insert($table, $insert_data);

        if ($result === false) {
            return ['row' => $row_number, 'message' => __('Database insert failed', 'plugixa-testimonial')];
        }

        $testimonial_id = $wpdb->insert_id;

        // A row imported without a translation group starts its own, as a new testimonial
        // created in the admin does: a UUID, the v2 schema's group type.
        $ml = Multilingual::instance();
        if ($ml->is_multilingual_active() && empty($insert_data['translation_group'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $wpdb->update($table, ['translation_group' => $ml->generate_translation_group()], ['id' => $testimonial_id]);
        }

        // Handle categories + custom fields
        $this->handle_import_categories($testimonial_id, $categories_value, $create_categories, $created_categories);
        $this->handle_import_meta($testimonial_id, $custom_field_values);

        return true;
    }

    private function build_testimonial_data(array $data, string $default_status): array {
        $valid_statuses = ['pending', 'approved', 'rejected', 'draft'];
        $status = isset($data['status']) && in_array(sanitize_key($data['status']), $valid_statuses, true)
            ? sanitize_key($data['status'])
            : $default_status;

        $rating = isset($data['rating']) ? max(1, min(5, (int) $data['rating'])) : 5;

        $row = [
            'client_name'      => sanitize_text_field($data['client_name'] ?? ''),
            'client_role'      => sanitize_text_field($data['client_role'] ?? ''),
            'client_company'   => sanitize_text_field($data['client_company'] ?? ''),
            'testimonial_text' => wp_kses_post($data['testimonial_text'] ?? ''),
            'rating'           => $rating,
            'video_url'        => esc_url_raw($data['video_url'] ?? ''),
            'website_url'      => esc_url_raw($data['website_url'] ?? ''),
            'status'           => $status,
            'is_featured'      => (int) (bool) ($data['is_featured'] ?? false),
            'display_order'    => 0,
        ];

        // Multilingual: the language and translation group the file carries, in the v2 schema's
        // columns. This used to write `lang` and an integer group (the v1 schema), and called a
        // method Multilingual never had, so on every site the first imported row stopped the
        // import with a fatal error. A pre-v2 file's `lang` is still read; its numeric group
        // cannot be reused as a v2 UUID group, so such a row starts a new group instead.
        $ml = Multilingual::instance();
        if ($ml->is_multilingual_active()) {
            $lang = (string) ($data['language_code'] ?? ($data['lang'] ?? ''));
            $row['language_code'] = sanitize_key($lang !== '' ? $lang : $ml->get_default_language());
            $group = sanitize_text_field((string) ($data['translation_group'] ?? ''));
            if (wp_is_uuid($group)) {
                $row['translation_group'] = $group;
            }
        }

        return $row;
    }

    private function handle_import_categories(int $testimonial_id, string $categories_value, bool $create_categories, array &$created_categories): void {
        global $wpdb;

        if (empty(trim($categories_value))) {
            return;
        }

        $pivot_table = $wpdb->prefix . 'plugixatestimonial_testimonial_categories';
        $categories_table = $wpdb->prefix . 'plugixatestimonial_categories';

        // Parse comma-separated or JSON array
        $cat_names = [];
        $decoded = json_decode($categories_value, true);
        if (is_array($decoded)) {
            // JSON array of category objects or names
            foreach ($decoded as $cat) {
                if (is_array($cat) && isset($cat['name'])) {
                    $cat_names[] = $cat['name'];
                } elseif (is_string($cat)) {
                    $cat_names[] = $cat;
                }
            }
        } else {
            $cat_names = array_map('trim', explode(',', $categories_value));
        }

        // Clear existing categories for this testimonial
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
        $wpdb->delete($pivot_table, ['testimonial_id' => $testimonial_id]);

        foreach ($cat_names as $cat_name) {
            $cat_name = sanitize_text_field(trim($cat_name));
            if (empty($cat_name)) {
                continue;
            }

            // Find existing category
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $cat_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE name = %s LIMIT 1", $categories_table,
                $cat_name
            ));

            if (!$cat_id && $create_categories) {
                $slug = sanitize_title($cat_name);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->insert($categories_table, [
                    'name'          => $cat_name,
                    'slug'          => $slug,
                    'description'   => '',
                    'display_order' => 0,
                    'created_at'    => current_time('mysql'),
                ]);
                $cat_id = $wpdb->insert_id;
                $created_categories[] = $cat_name;
            }

            if ($cat_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->insert($pivot_table, [
                    'testimonial_id' => $testimonial_id,
                    'category_id'    => (int) $cat_id,
                    'display_order'  => 0,
                ]);
            }
        }
    }

    private function handle_import_meta(int $testimonial_id, array $custom_field_values): void {
        global $wpdb;

        if (empty($custom_field_values)) {
            return;
        }

        $meta_table = $wpdb->prefix . 'plugixatestimonial_meta';

        foreach ($custom_field_values as $meta_key => $meta_value) {
            $meta_key = sanitize_key($meta_key);
            if (empty($meta_key) || $meta_value === '' || $meta_value === null) {
                continue;
            }

            // Upsert
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. Table names are bound as identifiers with %i and every value through prepare(). Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE testimonial_id = %d AND meta_key = %s", $meta_table,
                $testimonial_id,
                $meta_key
            ));

            if ($existing) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->update(
                    $meta_table,
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value itself is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    ['meta_value' => sanitize_text_field($meta_value)],
                    ['id' => (int) $existing]
                );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; WordPress core has no API for it. No SQL string is assembled here - the table name and every value are passed as arguments to $wpdb's insert()/update()/delete() helper, which escapes and prepares them internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                $wpdb->insert($meta_table, [
                    'testimonial_id' => $testimonial_id,
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    'meta_key'       => $meta_key,
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; WordPress core has no API for it. These are dedicated columns of the plugin's own meta table, not WP postmeta - the SlowDBQuery sniff targets meta_query lookups against wp_postmeta and does not apply here. The value itself is passed as an argument to the $wpdb helper, which escapes it internally. Object-cache invalidation for these tables is centralised on the REST lifecycle (see PlugixaTestimonial\Core\Cache::register()); admin CRUD reads are intentionally uncached so an editor always sees the row they just wrote.
                    'meta_value'     => sanitize_text_field($meta_value),
                ]);
            }
        }
    }
}

// Polyfill: parse full CSV string into array of rows
if (!function_exists(__NAMESPACE__ . '\\str_getcsv_lines')) {
    function str_getcsv_lines(string $content): array|false {
        $rows = [];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        $stream = fopen('php://temp', 'r+');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        fwrite($stream, $content);
        rewind($stream);

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Operating on a plugin-private temp file we just created ourselves, not on the WP content tree; WP_Filesystem would add an FTP-credentials round-trip for no gain.
        fclose($stream);
        return empty($rows) ? false : $rows;
    }
}
