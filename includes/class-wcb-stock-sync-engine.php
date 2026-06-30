<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Stock_Sync_Engine {
    const BATCH_SIZE = 20;

    public static function enqueue($mode, $comparison_ids = array()) {
        $comparison_ids = array_values(array_filter(array_map('absint', (array) $comparison_ids)));
        if ('one' === $mode && $comparison_ids) {
            $comparison_ids = array_slice($comparison_ids, 0, 1);
        }
        return WCB_Queue::enqueue('stock_sync', array(
            'mode' => sanitize_key($mode),
            'comparison_ids' => $comparison_ids,
            'offset' => 0,
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
        ));
    }

    public static function process($job_id, $payload) {
        if (!function_exists('wc_get_product')) {
            throw new RuntimeException(__('WooCommerce is required for stock synchronization.', 'woo-catalog-bridge'));
        }
        $mode = isset($payload['mode']) ? $payload['mode'] : 'all';
        $ids = isset($payload['comparison_ids']) ? (array) $payload['comparison_ids'] : array();
        $offset = isset($payload['offset']) ? (int) $payload['offset'] : 0;
        $rows = WCB_Repository::stock_sync_candidates('all' === $mode ? array() : $ids, self::BATCH_SIZE, $offset);
        $total = WCB_Repository::count_stock_sync_candidates('all' === $mode ? array() : $ids);

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
        $new_stock = self::normalize_stock($row['source_stock']);
        if (!$product || !$new_stock) {
            WCB_Repository::log('warning', __('Stock sync skipped because product or source stock is missing.', 'woo-catalog-bridge'), array('comparison_id' => (int) $row['id']));
            return 'skipped';
        }
        $old_stock = (string) $product->get_stock_status();
        if ($old_stock === $new_stock) {
            WCB_Repository::log('info', __('Stock sync skipped because stock is already equal.', 'woo-catalog-bridge'), array('product_id' => $product->get_id(), 'stock' => $new_stock));
            return 'skipped';
        }
        $product->set_stock_status($new_stock);
        $product->save();
        $change = array(
            'comparison_id' => (int) $row['id'],
            'product_id' => $product->get_id(),
            'name' => $row['name'],
            'sku' => $row['sku'],
            'old_stock' => $old_stock,
            'new_stock' => $new_stock,
        );
        WCB_Repository::record_stock_sync_change($change);
        WCB_Repository::log('info', __('WooCommerce product stock synchronized from source.', 'woo-catalog-bridge'), $change);
        return 'synced';
    }

    private static function normalize_stock($stock) {
        $stock = strtolower(trim((string) $stock));
        if (in_array($stock, array('instock', 'in stock', 'available', 'موجود'), true)) {
            return 'instock';
        }
        if (in_array($stock, array('outofstock', 'out of stock', 'unavailable', 'ناموجود'), true)) {
            return 'outofstock';
        }
        if ('onbackorder' === $stock) {
            return 'onbackorder';
        }
        return in_array($stock, array('instock', 'outofstock', 'onbackorder'), true) ? $stock : '';
    }
}
