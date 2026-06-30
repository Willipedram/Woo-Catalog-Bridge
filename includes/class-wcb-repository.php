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

        dbDelta("CREATE TABLE " . self::table('sitemap_scans') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sitemap_index_url text NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'running',
            total_sitemaps int(11) unsigned NOT NULL DEFAULT 0,
            processed_sitemaps int(11) unsigned NOT NULL DEFAULT 0,
            total_items int(11) unsigned NOT NULL DEFAULT 0,
            processed_items int(11) unsigned NOT NULL DEFAULT 0,
            progress longtext NULL,
            last_error text NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('sitemap_items') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL DEFAULT '',
            url varchar(700) NOT NULL,
            type varchar(30) NOT NULL,
            discovered_at datetime NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'discovered',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY url_type (url(191), type),
            KEY type (type),
            KEY status (status),
            KEY discovered_at (discovered_at)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('import_jobs') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            job_type varchar(60) NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'Pending',
            total_items int(11) unsigned NOT NULL DEFAULT 0,
            processed_items int(11) unsigned NOT NULL DEFAULT 0,
            failed_items int(11) unsigned NOT NULL DEFAULT 0,
            attempts int(11) unsigned NOT NULL DEFAULT 0,
            max_attempts int(11) unsigned NOT NULL DEFAULT 3,
            payload longtext NULL,
            action_id bigint(20) unsigned NULL,
            last_error text NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY source_id (source_id),
            KEY status (status),
            KEY job_type (job_type),
            KEY action_id (action_id),
            KEY created_at (created_at)
        ) $charset;");

        add_option('wcb_settings', array(
            'sitemap_url' => 'https://sazkala.com/sitemap_index.xml',
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
            'sitemap_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table('sitemap_items')),
            'sitemap_products' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('sitemap_items') . " WHERE type = %s", 'product')),
            'sitemap_categories' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('sitemap_items') . " WHERE type = %s", 'category')),
            'jobs_pending' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('import_jobs') . " WHERE status = %s", 'Pending')),
            'jobs_running' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('import_jobs') . " WHERE status = %s", 'Running')),
            'jobs_failed' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('import_jobs') . " WHERE status = %s", 'Failed')),
            'imported' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $products WHERE status = %s", 'imported')),
            'errors' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logs WHERE level = %s", 'error')),
            'active' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('import_jobs') . " WHERE status IN (%s,%s)", 'Pending', 'Running')),
        );
    }

    public static function create_import_job($job_type, $payload = array(), $source_id = 0, $max_attempts = 3) {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(self::table('import_jobs'), array(
            'source_id' => (int) $source_id,
            'job_type' => sanitize_key($job_type),
            'status' => 'Pending',
            'total_items' => 0,
            'processed_items' => 0,
            'failed_items' => 0,
            'attempts' => 0,
            'max_attempts' => max(1, (int) $max_attempts),
            'payload' => wp_json_encode($payload),
            'action_id' => null,
            'last_error' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        return (int) $wpdb->insert_id;
    }

    public static function get_import_job($job_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('import_jobs') . " WHERE id = %d", (int) $job_id), ARRAY_A);
    }

    public static function update_import_job($job_id, $data) {
        global $wpdb;
        $wpdb->update(self::table('import_jobs'), $data, array('id' => (int) $job_id));
    }

    public static function update_import_job_payload($job_id, $payload) {
        self::update_import_job($job_id, array(
            'payload' => wp_json_encode($payload),
            'updated_at' => current_time('mysql'),
        ));
    }

    public static function complete_import_job($job_id) {
        self::update_import_job($job_id, array(
            'status' => 'Completed',
            'finished_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ));
    }

    public static function list_import_jobs($number = 10, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('import_jobs') . " ORDER BY created_at DESC LIMIT %d OFFSET %d", (int) $number, (int) $offset), ARRAY_A);
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
