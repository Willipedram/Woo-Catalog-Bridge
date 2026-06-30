<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Ajax {
    private static function guard() {
        check_ajax_referer('wcb_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Access denied.', 'woo-catalog-bridge')), 403);
        }
    }

    public static function dashboard_stats() {
        self::guard();
        wp_send_json_success(WCB_Repository::stats());
    }

    public static function run_action() {
        self::guard();
        $task = isset($_POST['task']) ? sanitize_key(wp_unslash($_POST['task'])) : '';
        switch ($task) {
            case 'scan_sitemap':
                $scan_id = WCB_Sitemap_Scanner::start(WCB_Sitemap_Scanner::DEFAULT_SITEMAP_URL);
                $job_id = WCB_Queue::enqueue('scan_sitemap', array('scan_id' => $scan_id));
                wp_send_json_success(array(
                    'message' => __('Sitemap scan job was queued and will continue in the background.', 'woo-catalog-bridge'),
                    'job_id' => $job_id,
                    'scan_id' => $scan_id,
                    'background' => true,
                    'complete' => false,
                    'stats' => WCB_Repository::stats(),
                ));
                break;
            case 'scrape_product':
                $product_url = isset($_POST['product_url']) ? esc_url_raw(wp_unslash($_POST['product_url'])) : '';
                if (!$product_url) {
                    wp_send_json_error(array('message' => __('Product URL is required.', 'woo-catalog-bridge')), 400);
                }
                $job_id = WCB_Queue::enqueue('scrape_product', array('product_url' => $product_url));
                $message = sprintf(__('Product scraping job #%d was queued.', 'woo-catalog-bridge'), $job_id);
                break;
            case 'sync_products':
                $job_id = WCB_Queue::enqueue('sync_products');
                $message = sprintf(__('Synchronization job #%d was queued.', 'woo-catalog-bridge'), $job_id);
                break;
            case 'resume_job':
                $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
                if (!$job_id || !WCB_Queue::resume($job_id)) {
                    wp_send_json_error(array('message' => __('Job could not be resumed.', 'woo-catalog-bridge')), 400);
                }
                $message = sprintf(__('Job #%d was resumed.', 'woo-catalog-bridge'), $job_id);
                break;
            case 'cancel_job':
                $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
                if (!$job_id || !WCB_Queue::cancel($job_id)) {
                    wp_send_json_error(array('message' => __('Job could not be canceled.', 'woo-catalog-bridge')), 400);
                }
                $message = sprintf(__('Job #%d was canceled.', 'woo-catalog-bridge'), $job_id);
                break;
            default:
                wp_send_json_error(array('message' => __('Unknown task.', 'woo-catalog-bridge')), 400);
        }
        wp_send_json_success(array('message' => $message, 'stats' => WCB_Repository::stats()));
    }
}
