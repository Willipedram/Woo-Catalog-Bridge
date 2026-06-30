<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Sitemap_Scanner {
    const DEFAULT_SITEMAP_URL = 'https://sazkala.com/sitemap_index.xml';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public static function start($sitemap_url = '') {
        global $wpdb;
        $sitemap_url = esc_url_raw($sitemap_url ? $sitemap_url : self::DEFAULT_SITEMAP_URL);
        $now = current_time('mysql');

        $scan_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . WCB_Repository::table('sitemap_scans') . " WHERE sitemap_index_url = %s AND status = %s ORDER BY id DESC LIMIT 1",
            $sitemap_url,
            self::STATUS_RUNNING
        ));

        if ($scan_id) {
            return $scan_id;
        }

        $wpdb->insert(WCB_Repository::table('sitemap_scans'), array(
            'sitemap_index_url' => $sitemap_url,
            'status' => self::STATUS_RUNNING,
            'total_sitemaps' => 0,
            'processed_sitemaps' => 0,
            'total_items' => 0,
            'processed_items' => 0,
            'progress' => wp_json_encode(array('stage' => 'index', 'sitemaps' => array(), 'current' => 0)),
            'last_error' => null,
            'started_at' => $now,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function run_batch($scan_id = 0, $batch_size = 2) {
        global $wpdb;
        $scan_id = $scan_id ? (int) $scan_id : self::start();
        $scan = self::get_scan($scan_id);
        if (!$scan) {
            return array('complete' => true, 'message' => __('Sitemap scan not found.', 'woo-catalog-bridge'));
        }
        if (self::STATUS_COMPLETED === $scan['status']) {
            return self::response($scan, __('Sitemap scan is already completed.', 'woo-catalog-bridge'));
        }

        $progress = self::decode_progress($scan['progress']);
        try {
            if ('index' === $progress['stage']) {
                $sitemaps = self::read_sitemap_index($scan['sitemap_index_url']);
                $progress = array('stage' => 'children', 'sitemaps' => $sitemaps, 'current' => 0);
                self::update_scan($scan_id, array(
                    'total_sitemaps' => count($sitemaps),
                    'progress' => wp_json_encode($progress),
                    'updated_at' => current_time('mysql'),
                ));
                $scan = self::get_scan($scan_id);
            }

            $processed_sitemaps = 0;
            $processed_items = 0;
            $sitemaps = isset($progress['sitemaps']) && is_array($progress['sitemaps']) ? $progress['sitemaps'] : array();
            while ($processed_sitemaps < $batch_size && $progress['current'] < count($sitemaps)) {
                $child = $sitemaps[$progress['current']];
                $processed_items += self::process_child_sitemap($child['url'], $child['type']);
                $progress['current']++;
                $processed_sitemaps++;
            }

            $complete = $progress['current'] >= count($sitemaps);
            self::update_scan($scan_id, array(
                'status' => $complete ? self::STATUS_COMPLETED : self::STATUS_RUNNING,
                'processed_sitemaps' => (int) $progress['current'],
                'total_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . WCB_Repository::table('sitemap_items')),
                'processed_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . WCB_Repository::table('sitemap_items')),
                'progress' => wp_json_encode($progress),
                'finished_at' => $complete ? current_time('mysql') : null,
                'updated_at' => current_time('mysql'),
            ));
            $scan = self::get_scan($scan_id);
            $message = $complete ? __('Sitemap scan completed.', 'woo-catalog-bridge') : __('Sitemap batch processed. Continue to scan the next batch.', 'woo-catalog-bridge');
            WCB_Repository::log('info', $message, array('scan_id' => $scan_id, 'processed_items' => $processed_items));
            return self::response($scan, $message);
        } catch (Exception $e) {
            self::update_scan($scan_id, array('status' => self::STATUS_FAILED, 'last_error' => $e->getMessage(), 'updated_at' => current_time('mysql')));
            WCB_Repository::log('error', __('Sitemap scan failed.', 'woo-catalog-bridge'), array('scan_id' => $scan_id, 'error' => $e->getMessage()));
            return self::response(self::get_scan($scan_id), $e->getMessage());
        }
    }

    public static function get_latest_scan() {
        global $wpdb;
        return $wpdb->get_row("SELECT * FROM " . WCB_Repository::table('sitemap_scans') . " ORDER BY id DESC LIMIT 1", ARRAY_A);
    }

    public static function resume_scan($scan_id) {
        $scan = self::get_scan((int) $scan_id);
        if (!$scan || self::STATUS_COMPLETED === $scan['status']) {
            return false;
        }
        if (self::STATUS_FAILED === $scan['status']) {
            self::update_scan($scan_id, array(
                'status' => self::STATUS_RUNNING,
                'last_error' => null,
                'updated_at' => current_time('mysql'),
            ));
        }
        return true;
    }

    private static function get_scan($scan_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . WCB_Repository::table('sitemap_scans') . " WHERE id = %d", $scan_id), ARRAY_A);
    }

    private static function update_scan($scan_id, $data) {
        global $wpdb;
        $wpdb->update(WCB_Repository::table('sitemap_scans'), $data, array('id' => (int) $scan_id));
    }

    private static function read_sitemap_index($url) {
        $file = self::download_to_temp_file($url);
        $items = array();
        $reader = new XMLReader();
        if (!$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT)) {
            @unlink($file);
            throw new RuntimeException(__('Unable to open sitemap index XML.', 'woo-catalog-bridge'));
        }
        while ($reader->read()) {
            if (XMLReader::ELEMENT === $reader->nodeType && 'sitemap' === $reader->localName) {
                $node = simplexml_import_dom($reader->expand());
                $loc = isset($node->loc) ? esc_url_raw((string) $node->loc) : '';
                $type = self::detect_sitemap_type($loc);
                if ($loc && in_array($type, array('product', 'category'), true)) {
                    $items[] = array('url' => $loc, 'type' => $type);
                }
            }
        }
        $reader->close();
        @unlink($file);
        return $items;
    }

    private static function process_child_sitemap($url, $type) {
        global $wpdb;
        $file = self::download_to_temp_file($url);
        $count = 0;
        $reader = new XMLReader();
        if (!$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT)) {
            @unlink($file);
            throw new RuntimeException(__('Unable to open child sitemap XML.', 'woo-catalog-bridge'));
        }
        while ($reader->read()) {
            if (XMLReader::ELEMENT === $reader->nodeType && 'url' === $reader->localName) {
                $node = simplexml_import_dom($reader->expand());
                $loc = isset($node->loc) ? esc_url_raw((string) $node->loc) : '';
                if (!$loc) {
                    continue;
                }
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO " . WCB_Repository::table('sitemap_items') . " (name, url, type, discovered_at, status, updated_at) VALUES (%s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), updated_at = VALUES(updated_at)",
                    self::name_from_url($loc),
                    $loc,
                    $type,
                    current_time('mysql'),
                    'discovered',
                    current_time('mysql')
                ));
                $count++;
            }
        }
        $reader->close();
        @unlink($file);
        return $count;
    }

    private static function download_to_temp_file($url) {
        $response = wp_remote_get($url, array('timeout' => 30, 'stream' => true, 'filename' => wp_tempnam($url), 'redirection' => 3));
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $file = isset($response['filename']) ? $response['filename'] : '';
            if ($file) {
                @unlink($file);
            }
            throw new RuntimeException(sprintf(__('Sitemap request failed with HTTP %d.', 'woo-catalog-bridge'), $code));
        }
        return $response['filename'];
    }

    private static function detect_sitemap_type($url) {
        $path = strtolower(wp_parse_url($url, PHP_URL_PATH));
        if (false !== strpos($path, 'product_cat') || false !== strpos($path, 'category') || false !== strpos($path, 'categories')) {
            return 'category';
        }
        if (false !== strpos($path, 'product')) {
            return 'product';
        }
        return 'other';
    }

    private static function name_from_url($url) {
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        $slug = basename($path ? $path : $url);
        $name = urldecode(str_replace(array('-', '_'), ' ', $slug));
        return sanitize_text_field($name ? $name : $url);
    }

    private static function decode_progress($progress) {
        $decoded = json_decode((string) $progress, true);
        if (!is_array($decoded)) {
            return array('stage' => 'index', 'sitemaps' => array(), 'current' => 0);
        }
        $decoded['stage'] = isset($decoded['stage']) ? $decoded['stage'] : 'index';
        $decoded['sitemaps'] = isset($decoded['sitemaps']) && is_array($decoded['sitemaps']) ? $decoded['sitemaps'] : array();
        $decoded['current'] = isset($decoded['current']) ? (int) $decoded['current'] : 0;
        return $decoded;
    }

    private static function response($scan, $message) {
        $scan = is_array($scan) ? $scan : array();
        return array(
            'scan_id' => isset($scan['id']) ? (int) $scan['id'] : 0,
            'complete' => isset($scan['status']) && self::STATUS_COMPLETED === $scan['status'],
            'status' => isset($scan['status']) ? $scan['status'] : '',
            'message' => $message,
            'progress' => self::public_progress($scan),
            'stats' => WCB_Repository::stats(),
        );
    }

    private static function public_progress($scan) {
        $total = isset($scan['total_sitemaps']) ? (int) $scan['total_sitemaps'] : 0;
        $processed = isset($scan['processed_sitemaps']) ? (int) $scan['processed_sitemaps'] : 0;
        return array(
            'total_sitemaps' => $total,
            'processed_sitemaps' => $processed,
            'percent' => $total > 0 ? min(100, round(($processed / $total) * 100, 2)) : 0,
            'total_items' => isset($scan['total_items']) ? (int) $scan['total_items'] : 0,
            'last_error' => isset($scan['last_error']) ? $scan['last_error'] : '',
        );
    }
}
