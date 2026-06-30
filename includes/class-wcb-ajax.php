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
                wp_send_json_success(WCB_Sitemap_Scanner::run_batch($scan_id, 2));
                break;
            case 'scrape_product':
                WCB_Repository::log('info', __('Product scraping job started.', 'woo-catalog-bridge'));
                $message = __('Product scraping job started.', 'woo-catalog-bridge');
                break;
            case 'sync_products':
                WCB_Repository::log('info', __('Synchronization job started.', 'woo-catalog-bridge'));
                $message = __('Synchronization job started.', 'woo-catalog-bridge');
                break;
            default:
                wp_send_json_error(array('message' => __('Unknown task.', 'woo-catalog-bridge')), 400);
        }
        wp_send_json_success(array('message' => $message, 'stats' => WCB_Repository::stats()));
    }
}
