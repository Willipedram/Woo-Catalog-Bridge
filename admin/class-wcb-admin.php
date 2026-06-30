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
            array('wcb-sitemap', __('Sitemap Scan', 'woo-catalog-bridge'), __('Sitemap Scan', 'woo-catalog-bridge'), 'sitemap'),
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
        echo '</div><div class="wcb-panel"><h2>' . esc_html__('Quick actions', 'woo-catalog-bridge') . '</h2><button class="button button-primary wcb-ajax-action wcb-sitemap-action" data-task="scan_sitemap">' . esc_html__('Scan Sitemap', 'woo-catalog-bridge') . '</button> <button class="button wcb-ajax-action" data-task="scrape_product">' . esc_html__('Start Scraping', 'woo-catalog-bridge') . '</button> <button class="button wcb-ajax-action" data-task="sync_products">' . esc_html__('Run Sync', 'woo-catalog-bridge') . '</button><div class="wcb-progress" data-progress-wrap style="display:none"><div class="wcb-progress-bar"><span data-progress-bar></span></div><p data-progress-text></p></div></div>';
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
        $this->footer();
    }
    public function scrape() { $this->action_page(__('Product Scrape', 'woo-catalog-bridge'), 'scrape_product', __('Scrape queued product pages and normalize product data.', 'woo-catalog-bridge')); }
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

    public function categories() {
        $this->header(__('Categories', 'woo-catalog-bridge'));
        echo '<div class="wcb-panel"><p>' . esc_html__('Map discovered source categories to WooCommerce product categories before synchronization.', 'woo-catalog-bridge') . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__('Source Category', 'woo-catalog-bridge') . '</th><th>' . esc_html__('WooCommerce Category', 'woo-catalog-bridge') . '</th></tr></thead><tbody><tr><td>Uncategorized</td><td><select><option>' . esc_html__('Uncategorized', 'woo-catalog-bridge') . '</option></select></td></tr></tbody></table></div>';
        $this->footer();
    }

    public function schedule() {
        $this->header(__('Scheduling', 'woo-catalog-bridge'));
        $settings = get_option('wcb_settings', array());
        echo '<div class="wcb-panel"><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__('Enable automation', 'woo-catalog-bridge') . '</th><td><label><input type="checkbox" ' . checked(!empty($settings['schedule_enabled']), true, false) . ' /> ' . esc_html__('Run scheduled catalog operations', 'woo-catalog-bridge') . '</label></td></tr><tr><th scope="row">' . esc_html__('Interval', 'woo-catalog-bridge') . '</th><td><select><option>hourly</option><option>wcb_15_minutes</option><option>twicedaily</option><option>daily</option></select></td></tr></table></div>';
        $this->footer();
    }

    public function settings() {
        $this->header(__('Settings', 'woo-catalog-bridge'));
        $settings = get_option('wcb_settings', array());
        echo '<div class="wcb-panel"><table class="form-table" role="presentation"><tr><th scope="row"><label for="wcb-sitemap-url">' . esc_html__('Sitemap URL', 'woo-catalog-bridge') . '</label></th><td><input id="wcb-sitemap-url" type="url" class="regular-text" value="' . esc_attr(isset($settings['sitemap_url']) ? $settings['sitemap_url'] : '') . '" /></td></tr><tr><th scope="row"><label for="wcb-batch-size">' . esc_html__('Sync batch size', 'woo-catalog-bridge') . '</label></th><td><input id="wcb-batch-size" type="number" min="1" max="200" value="' . esc_attr(isset($settings['sync_batch_size']) ? $settings['sync_batch_size'] : 20) . '" /></td></tr></table><p class="description">' . esc_html__('This phase provides the professional admin shell; persistence for settings can be extended in the next phase.', 'woo-catalog-bridge') . '</p></div>';
        $this->footer();
    }
}
