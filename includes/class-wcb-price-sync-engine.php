<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Price_Sync_Engine {
    const BATCH_SIZE = 20;

    public static function enqueue($mode, $comparison_ids = array()) {
        $comparison_ids = array_values(array_filter(array_map('absint', (array) $comparison_ids)));
        if ('one' === $mode && $comparison_ids) {
            $comparison_ids = array_slice($comparison_ids, 0, 1);
        }
        $payload = array(
            'mode' => sanitize_key($mode),
            'comparison_ids' => $comparison_ids,
            'offset' => 0,
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
        );
        return WCB_Queue::enqueue('price_sync', $payload);
    }

    public static function process($job_id, $payload) {
        if (!function_exists('wc_get_product')) {
            throw new RuntimeException(__('WooCommerce is required for price synchronization.', 'woo-catalog-bridge'));
        }
        $mode = isset($payload['mode']) ? $payload['mode'] : 'all';
        $ids = isset($payload['comparison_ids']) ? (array) $payload['comparison_ids'] : array();
        $offset = isset($payload['offset']) ? (int) $payload['offset'] : 0;
        $rows = WCB_Repository::price_sync_candidates('all' === $mode ? array() : $ids, self::BATCH_SIZE, $offset);
        $total = WCB_Repository::count_price_sync_candidates('all' === $mode ? array() : $ids);

        foreach ($rows as $row) {
            $result = self::sync_row($row);
            $payload[$result] = isset($payload[$result]) ? (int) $payload[$result] + 1 : 1;
        }

        $offset += count($rows);
        $complete = $offset >= $total || !$rows;
        $payload['offset'] = $offset;
        WCB_Repository::update_import_job($job_id, array(
            'total_items' => $total,
            'processed_items' => min($offset, $total),
            'failed_items' => isset($payload['failed']) ? (int) $payload['failed'] : 0,
            'payload' => wp_json_encode($payload),
            'status' => $complete ? WCB_Queue::STATUS_COMPLETED : WCB_Queue::STATUS_PENDING,
            'finished_at' => $complete ? current_time('mysql') : null,
            'updated_at' => current_time('mysql'),
        ));
        if (!$complete) {
            WCB_Queue::schedule($job_id, 10);
        }
    }

    private static function sync_row($row) {
        $product = wc_get_product((int) $row['destination_product_id']);
        if (!$product || '' === (string) $row['source_price']) {
            WCB_Repository::log('warning', __('Price sync skipped because product or source price is missing.', 'woo-catalog-bridge'), array('comparison_id' => (int) $row['id']));
            return 'skipped';
        }
        $old_price = (string) $product->get_regular_price();
        $new_price = wc_format_decimal($row['source_price']);
        if (wc_format_decimal($old_price) === $new_price) {
            WCB_Repository::log('info', __('Price sync skipped because price is already equal.', 'woo-catalog-bridge'), array('product_id' => $product->get_id(), 'price' => $new_price));
            return 'skipped';
        }
        $product->set_regular_price($new_price);
        $product->set_price($new_price);
        $product->save();
        WCB_Repository::log('info', __('WooCommerce product price synchronized from source.', 'woo-catalog-bridge'), array(
            'comparison_id' => (int) $row['id'],
            'product_id' => $product->get_id(),
            'name' => $row['name'],
            'sku' => $row['sku'],
            'old_price' => $old_price,
            'new_price' => $new_price,
        ));
        return 'synced';
    }
}
