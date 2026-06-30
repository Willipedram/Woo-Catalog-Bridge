<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WCB_Plugin {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('wp_ajax_wcb_dashboard_stats', array('WCB_Ajax', 'dashboard_stats'));
        add_action('wp_ajax_wcb_run_action', array('WCB_Ajax', 'run_action'));
        add_action(WCB_Queue::HOOK, array('WCB_Queue', 'process'), 10, 1);
        add_filter('cron_schedules', array($this, 'cron_schedules'));
    }

    private function includes() {
        require_once WCB_PATH . 'includes/class-wcb-repository.php';
        require_once WCB_PATH . 'includes/class-wcb-sitemap-scanner.php';
        require_once WCB_PATH . 'includes/class-wcb-content-cleaner.php';
        require_once WCB_PATH . 'includes/class-wcb-product-importer.php';
        require_once WCB_PATH . 'includes/class-wcb-batch-importer.php';
        require_once WCB_PATH . 'includes/class-wcb-comparison-engine.php';
        require_once WCB_PATH . 'includes/class-wcb-price-sync-engine.php';
        require_once WCB_PATH . 'includes/class-wcb-queue.php';
        require_once WCB_PATH . 'includes/class-wcb-ajax.php';
        require_once WCB_PATH . 'admin/class-wcb-admin.php';
        require_once WCB_PATH . 'admin/class-wcb-list-tables.php';
    }

    public static function activate() {
        require_once WCB_PATH . 'includes/class-wcb-repository.php';
        WCB_Repository::install();
        if (!wp_next_scheduled('wcb_scheduled_sync')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'wcb_scheduled_sync');
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled('wcb_scheduled_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wcb_scheduled_sync');
        }
    }

    public function cron_schedules($schedules) {
        $schedules['wcb_15_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 minutes', 'woo-catalog-bridge'),
        );
        return $schedules;
    }

    public function admin_menu() {
        $admin = new WCB_Admin();
        $admin->register_menu();
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'wcb')) {
            return;
        }
        wp_enqueue_style('wcb-admin', WCB_URL . 'assets/css/admin.css', array(), WCB_VERSION);
        wp_enqueue_script('wcb-admin', WCB_URL . 'assets/js/admin.js', array('jquery'), WCB_VERSION, true);
        wp_localize_script('wcb-admin', 'WCBAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wcb_admin_nonce'),
            'i18n'    => array(
                'working' => __('Working...', 'woo-catalog-bridge'),
                'done'    => __('Done', 'woo-catalog-bridge'),
                'failed'  => __('Request failed', 'woo-catalog-bridge'),
            ),
        ));
    }
}
