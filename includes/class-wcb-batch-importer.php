<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Batch_Importer {
    const BATCH_SIZE = 1;

    public static function start($mode, $category_ids = array()) {
        $category_ids = array_values(array_filter(array_map('absint', (array) $category_ids)));
        if ('one' === $mode && $category_ids) {
            $category_ids = array_slice($category_ids, 0, 1);
        }
        $resolved_categories = WCB_Repository::list_sitemap_categories('all' === $mode ? array() : $category_ids);
        $payload = array(
            'mode' => sanitize_key($mode),
            'category_ids' => $category_ids,
            'resolved_categories' => $resolved_categories,
            'category_index' => 0,
            'queued_products' => 0,
            'skipped_products' => 0,
            'processed_categories' => 0,
        );
        return WCB_Queue::enqueue('batch_import', $payload);
    }

    public static function process($job_id, $payload) {
        $categories = self::categories_from_payload($payload);
        $total_categories = count($categories);
        $index = isset($payload['category_index']) ? (int) $payload['category_index'] : 0;
        $processed_now = 0;

        while ($index < $total_categories && $processed_now < self::BATCH_SIZE) {
            $category = $categories[$index];
            $product_urls = self::extract_product_urls($category['url']);
            foreach ($product_urls as $product_url) {
                if (WCB_Product_Importer::source_url_exists($product_url)) {
                    $payload['skipped_products'] = isset($payload['skipped_products']) ? (int) $payload['skipped_products'] + 1 : 1;
                    continue;
                }
                WCB_Queue::enqueue('scrape_product', array(
                    'product_url' => $product_url,
                    'source_category_id' => (int) $category['id'],
                    'source_category_url' => $category['url'],
                ));
                $payload['queued_products'] = isset($payload['queued_products']) ? (int) $payload['queued_products'] + 1 : 1;
            }
            $index++;
            $processed_now++;
        }

        $payload['category_index'] = $index;
        $payload['processed_categories'] = $index;
        $complete = $index >= $total_categories;
        WCB_Repository::update_import_job($job_id, array(
            'total_items' => $total_categories,
            'processed_items' => $index,
            'payload' => wp_json_encode($payload),
            'status' => $complete ? WCB_Queue::STATUS_COMPLETED : WCB_Queue::STATUS_PENDING,
            'finished_at' => $complete ? current_time('mysql') : null,
            'updated_at' => current_time('mysql'),
        ));

        if (!$complete) {
            WCB_Queue::schedule($job_id, 10);
        }
    }

    private static function categories_from_payload($payload) {
        if (!empty($payload['resolved_categories']) && is_array($payload['resolved_categories'])) {
            return $payload['resolved_categories'];
        }
        $mode = isset($payload['mode']) ? $payload['mode'] : 'all';
        $ids = isset($payload['category_ids']) ? (array) $payload['category_ids'] : array();
        return WCB_Repository::list_sitemap_categories('all' === $mode ? array() : $ids);
    }

    private static function extract_product_urls($category_url) {
        $response = wp_remote_get($category_url, array('timeout' => 30, 'redirection' => 5));
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(sprintf(__('Category request failed with HTTP %d.', 'woo-catalog-bridge'), $code));
        }
        $html = wp_remote_retrieve_body($response);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        libxml_clear_errors();
        $urls = array();
        foreach ($xpath->query('//a[@href]') as $link) {
            $href = trim($link->getAttribute('href'));
            if (!$href) {
                continue;
            }
            $url = self::absolute_url($href, $category_url);
            if (self::is_product_url($url)) {
                $urls[] = esc_url_raw(strtok($url, '#'));
            }
        }
        return array_values(array_unique($urls));
    }

    private static function is_product_url($url) {
        $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
        return false !== strpos($path, '/product/') || false !== strpos($path, '/product-');
    }

    private static function absolute_url($url, $base) {
        if (wp_http_validate_url($url)) {
            return $url;
        }
        if (0 === strpos($url, '//')) {
            return wp_parse_url($base, PHP_URL_SCHEME) . ':' . $url;
        }
        if (0 === strpos($url, '/')) {
            return wp_parse_url($base, PHP_URL_SCHEME) . '://' . wp_parse_url($base, PHP_URL_HOST) . $url;
        }
        return trailingslashit(dirname($base)) . $url;
    }
}
