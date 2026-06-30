<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Repository {
    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'wcb_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::table('products') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_url text NOT NULL,
            title varchar(255) DEFAULT '',
            category varchar(190) DEFAULT '',
            status varchar(30) DEFAULT 'discovered',
            woo_product_id bigint(20) unsigned DEFAULT 0,
            last_error text NULL,
            discovered_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY category (category)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('logs') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset;");

        add_option('wcb_settings', array(
            'sitemap_url' => home_url('/sitemap.xml'),
            'sync_batch_size' => 20,
            'schedule_enabled' => 0,
            'schedule_interval' => 'hourly',
        ));
    }

    public static function stats() {
        global $wpdb;
        $products = self::table('products');
        $logs = self::table('logs');
        return array(
            'discovered' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $products"),
            'imported' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $products WHERE status = %s", 'imported')),
            'errors' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logs WHERE level = %s", 'error')),
            'active' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $products WHERE status IN (%s,%s,%s)", 'queued', 'scraping', 'syncing')),
        );
    }

    public static function list_products($args = array()) {
        global $wpdb;
        $defaults = array('number' => 20, 'offset' => 0, 'orderby' => 'updated_at', 'order' => 'DESC', 'status' => '', 'search' => '');
        $args = wp_parse_args($args, $defaults);
        $where = 'WHERE 1=1';
        $params = array();
        if ($args['status']) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if ($args['search']) {
            $where .= ' AND (title LIKE %s OR source_url LIKE %s OR category LIKE %s)';
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $params = array_merge($params, array($like, $like, $like));
        }
        $allowed_orderby = array('id', 'title', 'category', 'status', 'updated_at');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'updated_at';
        $order = 'ASC' === strtoupper($args['order']) ? 'ASC' : 'DESC';
        $sql = "SELECT * FROM " . self::table('products') . " $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $params[] = (int) $args['number'];
        $params[] = (int) $args['offset'];
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function count_products($args = array()) {
        global $wpdb;
        $where = 'WHERE 1=1';
        $params = array();
        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['search'])) {
            $where .= ' AND (title LIKE %s OR source_url LIKE %s OR category LIKE %s)';
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $params = array_merge($params, array($like, $like, $like));
        }
        $sql = "SELECT COUNT(*) FROM " . self::table('products') . " $where";
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, $params)) : $wpdb->get_var($sql));
    }

    public static function list_logs($number = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('logs') . " ORDER BY created_at DESC LIMIT %d OFFSET %d", $number, $offset), ARRAY_A);
    }

    public static function count_logs() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table('logs'));
    }

    public static function log($level, $message, $context = array()) {
        global $wpdb;
        $wpdb->insert(self::table('logs'), array(
            'level' => sanitize_key($level),
            'message' => wp_kses_post($message),
            'context' => wp_json_encode($context),
            'created_at' => current_time('mysql'),
        ));
    }

    public static function seed_discovered_products($count = 3) {
        global $wpdb;
        for ($i = 1; $i <= $count; $i++) {
            $wpdb->insert(self::table('products'), array(
                'source_url' => home_url('/sample-product-' . wp_rand(100, 999) . '/'),
                'title' => 'Sample discovered product ' . $i,
                'category' => 'Uncategorized',
                'status' => 'discovered',
                'woo_product_id' => 0,
                'discovered_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ));
        }
        self::log('info', sprintf(__('Discovered %d products from sitemap.', 'woo-catalog-bridge'), $count));
    }
}
