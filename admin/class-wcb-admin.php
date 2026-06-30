<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Admin {
    private $slug = 'wcb-dashboard';

    public function register_menu() {
        add_menu_page(__('Woo Catalog Bridge', 'woo-catalog-bridge'), __('Catalog Bridge', 'woo-catalog-bridge'), 'manage_options', $this->slug, array($this, 'dashboard'), 'dashicons-update-alt', 56);
        $pages = array(
            array($this->slug, __('Dashboard', 'woo-catalog-bridge'), __('Dashboard', 'woo-catalog-bridge'), 'dashboard'),
            array('wcb-monitor', __('Live Monitoring', 'woo-catalog-bridge'), __('Live Monitoring', 'woo-catalog-bridge'), 'monitor'),
            array('wcb-sitemap', __('Sitemap Scan', 'woo-catalog-bridge'), __('Sitemap Scan', 'woo-catalog-bridge'), 'sitemap'),
            array('wcb-batch-import', __('Batch Import', 'woo-catalog-bridge'), __('Batch Import', 'woo-catalog-bridge'), 'batch_import'),
            array('wcb-comparison', __('Comparison', 'woo-catalog-bridge'), __('Comparison', 'woo-catalog-bridge'), 'comparison'),
            array('wcb-price-sync', __('Price Sync', 'woo-catalog-bridge'), __('Price Sync', 'woo-catalog-bridge'), 'price_sync'),
            array('wcb-stock-sync', __('Stock Sync', 'woo-catalog-bridge'), __('Stock Sync', 'woo-catalog-bridge'), 'stock_sync'),
            array('wcb-products', __('Discovered Products', 'woo-catalog-bridge'), __('Discovered Products', 'woo-catalog-bridge'), 'products'),
            array('wcb-categories', __('Categories', 'woo-catalog-bridge'), __('Categories', 'woo-catalog-bridge'), 'categories'),
            array('wcb-scrape', __('Product Scrape', 'woo-catalog-bridge'), __('Product Scrape', 'woo-catalog-bridge'), 'scrape'),
            array('wcb-sync', __('Synchronization', 'woo-catalog-bridge'), __('Synchronization', 'woo-catalog-bridge'), 'sync'),
            array('wcb-schedule', __('Scheduling', 'woo-catalog-bridge'), __('Scheduling', 'woo-catalog-bridge'), 'schedule'),
            array('wcb-logs', __('Logs', 'woo-catalog-bridge'), __('Logs', 'woo-catalog-bridge'), 'logs'),
            array('wcb-settings', __('Settings', 'woo-catalog-bridge'), __('Settings', 'woo-catalog-bridge'), 'settings'),
        );
        foreach ($pages as $page) {
            add_submenu_page($this->slug, $page[1], $page[2], 'manage_options', $page[0], array($this, $page[3]));
        }
    }

    private function header($title) {
        echo '<div class="wrap wcb-wrap"><h1>' . esc_html($title) . '</h1><div id="wcb-admin-notice" class="notice is-dismissible" style="display:none"><p></p></div>';
    }
    private function footer() { echo '</div>'; }

    public function dashboard() {
        $this->header(__('Woo Catalog Bridge Dashboard', 'woo-catalog-bridge'));
        $stats = WCB_Repository::stats();
        echo '<div class="wcb-grid wcb-stats">';
        $cards = array('discovered' => __('Discovered Products', 'woo-catalog-bridge'), 'imported' => __('Imported Products', 'woo-catalog-bridge'), 'errors' => __('Errors', 'woo-catalog-bridge'), 'active' => __('Active Operations', 'woo-catalog-bridge'));
        foreach ($cards as $key => $label) {
            echo '<div class="wcb-card"><span class="wcb-card-label">' . esc_html($label) . '</span><strong data-stat="' . esc_attr($key) . '">' . esc_html($stats[$key]) . '</strong></div>';
        }
        echo '</div><div class="wcb-panel"><h2>' . esc_html__('Quick actions', 'woo-catalog-bridge') . '</h2><button class="button button-primary wcb-ajax-action wcb-sitemap-action" data-task="scan_sitemap">' . esc_html__('Scan Sitemap', 'woo-catalog-bridge') . '</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=wcb-scrape')) . '">' . esc_html__('Import Product', 'woo-catalog-bridge') . '</a> <button class="button wcb-ajax-action" data-task="compare_products">' . esc_html__('Run Comparison', 'woo-catalog-bridge') . '</button><div class="wcb-progress" data-progress-wrap style="display:none"><div class="wcb-progress-bar"><span data-progress-bar></span></div><p data-progress-text></p></div></div>';
        $this->footer();
    }

    public function monitor() {
        $this->header(__('Live Monitoring', 'woo-catalog-bridge'));
        echo '<div class="wcb-panel" data-wcb-monitor><p>' . esc_html__('Live job status, progress, logs, timing, and duration are loaded from persisted queue records so the current state is restored after leaving and returning to this page.', 'woo-catalog-bridge') . '</p>';
        echo '<h2>' . esc_html__('Jobs', 'woo-catalog-bridge') . '</h2><div data-wcb-monitor-jobs><p>' . esc_html__('Loading jobs...', 'woo-catalog-bridge') . '</p></div>';
        echo '<h2>' . esc_html__('Live Logs', 'woo-catalog-bridge') . '</h2><div data-wcb-monitor-logs><p>' . esc_html__('Loading logs...', 'woo-catalog-bridge') . '</p></div></div>';
        $this->footer();
    }

    public function sitemap() {
        $this->header(__('Sitemap Scan', 'woo-catalog-bridge'));
        $scan = WCB_Sitemap_Scanner::get_latest_scan();
        echo '<div class="wcb-panel"><p>' . esc_html__('Reads the source sitemap index, extracts product and category sitemaps, and stores discovered URLs in dedicated tables. Processing runs in resumable batches so large sites do not require loading all URLs into memory.', 'woo-catalog-bridge') . '</p>';
        echo '<p><strong>' . esc_html__('Source:', 'woo-catalog-bridge') . '</strong> ' . esc_html(WCB_Sitemap_Scanner::DEFAULT_SITEMAP_URL) . '</p>';
        if ($scan) {
            echo '<p><strong>' . esc_html__('Last scan:', 'woo-catalog-bridge') . '</strong> ' . esc_html($scan['status']) . ' — ' . esc_html((int) $scan['processed_sitemaps'] . '/' . (int) $scan['total_sitemaps']) . ' ' . esc_html__('sitemaps processed', 'woo-catalog-bridge') . '</p>';
        }
        echo '<button class="button button-primary wcb-ajax-action wcb-sitemap-action" data-task="scan_sitemap">' . esc_html__('Run / Resume Scan', 'woo-catalog-bridge') . '</button><span class="spinner"></span><div class="wcb-progress" data-progress-wrap style="display:none"><div class="wcb-progress-bar"><span data-progress-bar></span></div><p data-progress-text></p></div></div>';
        $stats = WCB_Repository::stats();
        echo '<div class="wcb-grid wcb-stats"><div class="wcb-card"><span class="wcb-card-label">' . esc_html__('Sitemap Items', 'woo-catalog-bridge') . '</span><strong data-stat="sitemap_items">' . esc_html($stats['sitemap_items']) . '</strong></div><div class="wcb-card"><span class="wcb-card-label">' . esc_html__('Product URLs', 'woo-catalog-bridge') . '</span><strong data-stat="sitemap_products">' . esc_html($stats['sitemap_products']) . '</strong></div><div class="wcb-card"><span class="wcb-card-label">' . esc_html__('Category URLs', 'woo-catalog-bridge') . '</span><strong data-stat="sitemap_categories">' . esc_html($stats['sitemap_categories']) . '</strong></div></div>';
        $this->render_jobs_panel();
        $this->footer();
    }
    public function scrape() {
        $this->header(__('Product Import', 'woo-catalog-bridge'));
        echo '<div class="wcb-panel"><p>' . esc_html__('Enter a Sazkala product URL. The queued importer extracts product data, stores images in the Media Library, creates a WooCommerce product, and stops if a duplicate is found by SKU, source URL, or product name.', 'woo-catalog-bridge') . '</p>';
        echo '<p><label for="wcb-product-url"><strong>' . esc_html__('Product URL', 'woo-catalog-bridge') . '</strong></label></p>';
        echo '<input id="wcb-product-url" data-wcb-product-url type="url" class="large-text" placeholder="https://sazkala.com/product/..." />';
        echo '<p><button class="button button-primary wcb-ajax-action" data-task="scrape_product">' . esc_html__('Queue Product Import', 'woo-catalog-bridge') . '</button><span class="spinner"></span></p></div>';
        $this->render_jobs_panel();
        $this->footer();
    }
    public function batch_import() {
        $this->header(__('Batch Import', 'woo-catalog-bridge'));
        $categories = WCB_Repository::list_sitemap_categories();
        echo '<div class="wcb-panel"><p>' . esc_html__('Queue product imports from one category, multiple categories, or all discovered categories. Existing products are skipped by source URL before product jobs are queued; each discovered product is added as its own queue job for retry/resume handling.', 'woo-catalog-bridge') . '</p>';
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Batch mode', 'woo-catalog-bridge') . '</legend>';
        echo '<label><input type="radio" name="wcb_batch_mode" value="one" data-wcb-batch-mode /> ' . esc_html__('One category', 'woo-catalog-bridge') . '</label><br />';
        echo '<label><input type="radio" name="wcb_batch_mode" value="multiple" data-wcb-batch-mode /> ' . esc_html__('Multiple categories', 'woo-catalog-bridge') . '</label><br />';
        echo '<label><input type="radio" name="wcb_batch_mode" value="all" data-wcb-batch-mode checked /> ' . esc_html__('All categories', 'woo-catalog-bridge') . '</label></fieldset>';
        echo '<select data-wcb-category-ids multiple size="10" class="large-text">';
        foreach ($categories as $category) {
            echo '<option value="' . esc_attr($category['id']) . '">' . esc_html($category['name'] ? $category['name'] : $category['url']) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Run Sitemap Scan first if no categories are listed.', 'woo-catalog-bridge') . '</p>';
        echo '<p><button class="button button-primary wcb-ajax-action" data-task="batch_import">' . esc_html__('Queue Batch Import', 'woo-catalog-bridge') . '</button><span class="spinner"></span></p></div>';
        $this->render_jobs_panel();
        $this->footer();
    }
    public function comparison() {
        $this->header(__('Comparison Engine', 'woo-catalog-bridge'));
        echo '<div class="wcb-panel"><p>' . esc_html__('Compare source products with WooCommerce destination products by SKU, source URL, then product name. The comparison table shows source/destination price, source/destination stock, and status.', 'woo-catalog-bridge') . '</p>';
        echo '<button class="button button-primary wcb-ajax-action" data-task="compare_products">' . esc_html__('Run Comparison', 'woo-catalog-bridge') . '</button><span class="spinner"></span></div>';
        $table = new WCB_Comparisons_List_Table();
        $table->prepare_items();
        echo '<form method="get"><input type="hidden" name="page" value="wcb-comparison" />';
        $table->search_box(__('Search comparisons', 'woo-catalog-bridge'), 'wcb-comparison');
        $table->display();
        echo '</form>';
        $this->footer();
    }
    public function price_sync() {
        $this->header(__('Price Sync Engine', 'woo-catalog-bridge'));
        $candidates = WCB_Repository::price_sync_candidates(array(), 200, 0);
        echo '<div class="wcb-panel"><p>' . esc_html__('Synchronize WooCommerce prices from the source price stored in comparison rows. Run Comparison first, then sync one product, multiple products, or all products. Every price change is logged.', 'woo-catalog-bridge') . '</p>';
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Price sync mode', 'woo-catalog-bridge') . '</legend>';
        echo '<label><input type="radio" name="wcb_price_sync_mode" value="one" data-wcb-price-sync-mode /> ' . esc_html__('One product', 'woo-catalog-bridge') . '</label><br />';
        echo '<label><input type="radio" name="wcb_price_sync_mode" value="multiple" data-wcb-price-sync-mode /> ' . esc_html__('Multiple products', 'woo-catalog-bridge') . '</label><br />';
        echo '<label><input type="radio" name="wcb_price_sync_mode" value="all" data-wcb-price-sync-mode checked /> ' . esc_html__('All products', 'woo-catalog-bridge') . '</label></fieldset>';
        echo '<select data-wcb-comparison-ids multiple size="10" class="large-text">';
        foreach ($candidates as $row) {
            echo '<option value="' . esc_attr($row['id']) . '">#' . esc_html($row['id']) . ' — ' . esc_html($row['name']) . ' — ' . esc_html($row['source_price']) . ' → ' . esc_html($row['destination_price']) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Rows without a destination product or source price are excluded.', 'woo-catalog-bridge') . '</p>';
        echo '<p><button class="button button-primary wcb-ajax-action" data-task="price_sync">' . esc_html__('Queue Price Sync', 'woo-catalog-bridge') . '</button><span class="spinner"></span></p></div>';
        $this->render_jobs_panel();
        $this->footer();
    }
    public function stock_sync() {
        $this->header(__('Stock Sync Engine', 'woo-catalog-bridge'));
        $candidates = WCB_Repository::stock_sync_candidates(array(), 200, 0);
        echo '<div class="wcb-panel"><p>' . esc_html__('Synchronize WooCommerce stock status from the source stock stored in comparison rows. Run Comparison first, then sync one product, multiple products, or all products. Every stock change is saved and logged.', 'woo-catalog-bridge') . '</p>';
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Stock sync mode', 'woo-catalog-bridge') . '</legend>';
        echo '<label><input type="radio" name="wcb_stock_sync_mode" value="one" data-wcb-stock-sync-mode /> ' . esc_html__('Sync One', 'woo-catalog-bridge') . '</label><br />';
        echo '<label><input type="radio" name="wcb_stock_sync_mode" value="multiple" data-wcb-stock-sync-mode /> ' . esc_html__('Sync Bulk', 'woo-catalog-bridge') . '</label><br />';
        echo '<label><input type="radio" name="wcb_stock_sync_mode" value="all" data-wcb-stock-sync-mode checked /> ' . esc_html__('Sync All', 'woo-catalog-bridge') . '</label></fieldset>';
        echo '<select data-wcb-comparison-ids multiple size="10" class="large-text">';
        foreach ($candidates as $row) {
            echo '<option value="' . esc_attr($row['id']) . '">#' . esc_html($row['id']) . ' — ' . esc_html($row['name']) . ' — ' . esc_html($row['source_stock']) . ' → ' . esc_html($row['destination_stock']) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Rows without a destination product or source stock are excluded.', 'woo-catalog-bridge') . '</p>';
        echo '<p><button class="button button-primary wcb-ajax-action" data-task="stock_sync">' . esc_html__('Queue Stock Sync', 'woo-catalog-bridge') . '</button><span class="spinner"></span></p></div>';
        $this->render_jobs_panel();
        $this->footer();
    }
    public function sync() { $this->action_page(__('Synchronization', 'woo-catalog-bridge'), 'sync_products', __('Import or update scraped products in WooCommerce.', 'woo-catalog-bridge')); }

    private function action_page($title, $task, $description) {
        $this->header($title);
        echo '<div class="wcb-panel"><p>' . esc_html($description) . '</p><button class="button button-primary wcb-ajax-action" data-task="' . esc_attr($task) . '">' . esc_html__('Run Now', 'woo-catalog-bridge') . '</button><span class="spinner"></span></div>';
        $this->footer();
    }

    public function products() {
        $this->header(__('Discovered Products', 'woo-catalog-bridge'));
        $table = new WCB_Products_List_Table();
        $table->prepare_items();
        echo '<form method="get"><input type="hidden" name="page" value="wcb-products" />';
        $table->search_box(__('Search products', 'woo-catalog-bridge'), 'wcb-products');
        $table->display();
        echo '</form>';
        $this->footer();
    }

    public function logs() {
        $this->header(__('Logs', 'woo-catalog-bridge'));
        $table = new WCB_Logs_List_Table();
        $table->prepare_items();
        $table->display();
        $this->footer();
    }

    private function render_jobs_panel() {
        $jobs = WCB_Repository::list_import_jobs(10);
        echo '<div class="wcb-panel"><h2>' . esc_html__('Import Jobs', 'woo-catalog-bridge') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('ID', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Operation', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Status', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Progress', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Attempts', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Last Error', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Actions', 'woo-catalog-bridge') . '</th></tr></thead><tbody>';
        if (!$jobs) {
            echo '<tr><td colspan="7">' . esc_html__('No jobs have been queued yet.', 'woo-catalog-bridge') . '</td></tr>';
        }
        foreach ($jobs as $job) {
            echo '<tr><td>#' . esc_html($job['id']) . '</td><td>' . esc_html($job['job_type']) . '</td><td><span class="wcb-status wcb-status-' . esc_attr(strtolower($job['status'])) . '">' . esc_html($job['status']) . '</span></td><td>' . esc_html((int) $job['processed_items'] . '/' . (int) $job['total_items']) . '</td><td>' . esc_html((int) $job['attempts'] . '/' . (int) $job['max_attempts']) . '</td><td>' . esc_html($job['last_error']) . '</td><td>';
            if (in_array($job['status'], array(WCB_Queue::STATUS_PENDING, WCB_Queue::STATUS_RUNNING, WCB_Queue::STATUS_FAILED), true)) {
                echo '<button class="button wcb-ajax-action" data-task="resume_job" data-job-id="' . esc_attr($job['id']) . '">' . esc_html__('Resume', 'woo-catalog-bridge') . '</button> ';
            }
            if (in_array($job['status'], array(WCB_Queue::STATUS_PENDING, WCB_Queue::STATUS_RUNNING), true)) {
                echo '<button class="button wcb-ajax-action" data-task="cancel_job" data-job-id="' . esc_attr($job['id']) . '">' . esc_html__('Cancel', 'woo-catalog-bridge') . '</button>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function categories() {
        $this->header(__('Categories', 'woo-catalog-bridge'));
        echo '<div class="wcb-panel"><p>' . esc_html__('Map discovered source categories to WooCommerce product categories before synchronization.', 'woo-catalog-bridge') . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__('Source Category', 'woo-catalog-bridge') . '</th><th>' . esc_html__('WooCommerce Category', 'woo-catalog-bridge') . '</th></tr></thead><tbody><tr><td>Uncategorized</td><td><select><option>' . esc_html__('Uncategorized', 'woo-catalog-bridge') . '</option></select></td></tr></tbody></table></div>';
        $this->footer();
    }

    public function schedule() {
        $this->header(__('Scheduling', 'woo-catalog-bridge'));
        $settings = WCB_Scheduler::settings();
        $intervals = WCB_Scheduler::intervals();
        echo '<div class="wcb-panel"><p>' . esc_html__('Schedule catalog operations with WP Cron and Action Scheduler. Custom interval values are in minutes.', 'woo-catalog-bridge') . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__('Operation', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Enabled', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Interval', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Custom minutes', 'woo-catalog-bridge') . '</th><th>' . esc_html__('Next run', 'woo-catalog-bridge') . '</th></tr></thead><tbody>';
        foreach (WCB_Scheduler::operations() as $operation => $label) {
            $config = $settings[$operation];
            echo '<tr data-wcb-scheduler-row="' . esc_attr($operation) . '"><td>' . esc_html($label) . '</td><td><label><input type="checkbox" data-wcb-scheduler-enabled ' . checked(!empty($config['enabled']), true, false) . ' /> ' . esc_html__('Enabled', 'woo-catalog-bridge') . '</label></td><td><select data-wcb-scheduler-interval>';
            foreach ($intervals as $key => $interval_label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($config['interval'], $key, false) . '>' . esc_html($interval_label) . '</option>';
            }
            echo '</select></td><td><input type="number" min="1" data-wcb-scheduler-custom value="' . esc_attr($config['custom_minutes']) . '" /></td><td>' . esc_html($config['next_run']) . '</td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary wcb-ajax-action" data-task="save_scheduler">' . esc_html__('Save Scheduler', 'woo-catalog-bridge') . '</button><span class="spinner"></span></p></div>';
        $this->footer();
    }

    public function settings() {
        $this->header(__('Settings', 'woo-catalog-bridge'));
        $settings = get_option('wcb_settings', array());
        $cleaner = WCB_Content_Cleaner::settings();
        echo '<div class="wcb-panel"><table class="form-table" role="presentation"><tr><th scope="row"><label for="wcb-sitemap-url">' . esc_html__('Sitemap URL', 'woo-catalog-bridge') . '</label></th><td><input id="wcb-sitemap-url" type="url" class="regular-text" value="' . esc_attr(isset($settings['sitemap_url']) ? $settings['sitemap_url'] : '') . '" /></td></tr><tr><th scope="row"><label for="wcb-batch-size">' . esc_html__('Sync batch size', 'woo-catalog-bridge') . '</label></th><td><input id="wcb-batch-size" type="number" min="1" max="200" value="' . esc_attr(isset($settings['sync_batch_size']) ? $settings['sync_batch_size'] : 20) . '" /></td></tr>';
        echo '<tr><th scope="row"><label for="wcb-content-cleaner-replacement">' . esc_html__('Content replacement value', 'woo-catalog-bridge') . '</label></th><td><input id="wcb-content-cleaner-replacement" data-wcb-setting="content_cleaner_replacement" type="text" class="regular-text" value="' . esc_attr($cleaner['replacement']) . '" /><p class="description">' . esc_html__('Default replacement used for Sazkala brand phrases.', 'woo-catalog-bridge') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcb-content-cleaner-rules">' . esc_html__('Replace rules', 'woo-catalog-bridge') . '</label></th><td><textarea id="wcb-content-cleaner-rules" data-wcb-setting="content_cleaner_rules" class="large-text code" rows="8">' . esc_textarea(WCB_Content_Cleaner::rules_to_text($cleaner['rules'])) . '</textarea><p class="description">' . esc_html__('Define one rule per line in this format: source => replacement. If replacement is omitted, the default replacement value is used.', 'woo-catalog-bridge') . '</p></td></tr></table>';
        echo '<p><button class="button button-primary wcb-ajax-action" data-task="save_settings">' . esc_html__('Save Settings', 'woo-catalog-bridge') . '</button><span class="spinner"></span></p></div>';
        $this->footer();
    }
}
