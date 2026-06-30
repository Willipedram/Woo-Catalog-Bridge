<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Comparison_Engine {
    const STATUS_EQUAL = 'Equal';
    const STATUS_PRICE_CHANGED = 'Price Changed';
    const STATUS_STOCK_CHANGED = 'Stock Changed';
    const STATUS_ONLY_SOURCE = 'Only Source';
    const STATUS_ONLY_DESTINATION = 'Only Destination';

    public static function run() {
        if (!function_exists('wc_get_products')) {
            throw new RuntimeException(__('WooCommerce is required for comparison.', 'woo-catalog-bridge'));
        }
        WCB_Repository::clear_comparisons();
        $matched_destination_ids = array();
        $created = 0;

        foreach (WCB_Repository::list_all_source_products() as $source) {
            $destination_id = self::find_destination_product($source);
            $destination = $destination_id ? wc_get_product($destination_id) : null;
            if ($destination) {
                $matched_destination_ids[] = (int) $destination_id;
                $status = self::compare_status($source, $destination);
                WCB_Repository::insert_comparison(self::row($source, $destination, $status));
            } else {
                WCB_Repository::insert_comparison(self::row($source, null, self::STATUS_ONLY_SOURCE));
            }
            $created++;
        }

        $destination_ids = wc_get_products(array('status' => array('publish', 'draft', 'pending', 'private'), 'limit' => -1, 'return' => 'ids', 'type' => array('simple', 'variable')));
        foreach ($destination_ids as $destination_id) {
            if (in_array((int) $destination_id, $matched_destination_ids, true)) {
                continue;
            }
            $destination = wc_get_product($destination_id);
            if (!$destination) {
                continue;
            }
            WCB_Repository::insert_comparison(self::row(null, $destination, self::STATUS_ONLY_DESTINATION));
            $created++;
        }

        WCB_Repository::log('info', sprintf(__('Comparison generated with %d rows.', 'woo-catalog-bridge'), $created));
        return $created;
    }

    private static function find_destination_product($source) {
        if (!empty($source['sku'])) {
            $id = wc_get_product_id_by_sku($source['sku']);
            if ($id) {
                return (int) $id;
            }
        }
        if (!empty($source['source_url'])) {
            $id = self::find_product_by_meta(WCB_Product_Importer::SOURCE_URL_META, $source['source_url']);
            if ($id) {
                return $id;
            }
        }
        if (!empty($source['title'])) {
            $post = get_page_by_title($source['title'], OBJECT, 'product');
            if ($post && !empty($post->ID)) {
                return (int) $post->ID;
            }
        }
        return 0;
    }

    private static function compare_status($source, WC_Product $destination) {
        $price_changed = '' !== (string) $source['source_price'] && self::normalize_price($source['source_price']) !== self::normalize_price($destination->get_price());
        $stock_changed = '' !== (string) $source['source_stock'] && strtolower((string) $source['source_stock']) !== strtolower((string) $destination->get_stock_status());
        if ($price_changed) {
            return self::STATUS_PRICE_CHANGED;
        }
        if ($stock_changed) {
            return self::STATUS_STOCK_CHANGED;
        }
        return self::STATUS_EQUAL;
    }

    private static function row($source, $destination, $status) {
        $destination = $destination instanceof WC_Product ? $destination : null;
        return array(
            'name' => $source && !empty($source['title']) ? $source['title'] : ($destination ? $destination->get_name() : ''),
            'sku' => $source && !empty($source['sku']) ? $source['sku'] : ($destination ? $destination->get_sku() : ''),
            'source_price' => $source ? (string) $source['source_price'] : '',
            'destination_price' => $destination ? (string) $destination->get_price() : '',
            'source_stock' => $source ? (string) $source['source_stock'] : '',
            'destination_stock' => $destination ? (string) $destination->get_stock_status() : '',
            'status' => $status,
            'source_product_id' => $source ? (int) $source['id'] : 0,
            'destination_product_id' => $destination ? (int) $destination->get_id() : 0,
            'source_url' => $source ? (string) $source['source_url'] : '',
        );
    }

    private static function find_product_by_meta($key, $value) {
        $query = new WP_Query(array('post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => $key, 'meta_value' => $value));
        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private static function normalize_price($price) {
        return wc_format_decimal($price, wc_get_price_decimals());
    }
}
