<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WCB_Products_List_Table extends WP_List_Table {
    public function get_columns() {
        return array(
            'cb' => '<input type="checkbox" />',
            'title' => __('Product', 'woo-catalog-bridge'),
            'source_url' => __('Source URL', 'woo-catalog-bridge'),
            'category' => __('Category', 'woo-catalog-bridge'),
            'status' => __('Status', 'woo-catalog-bridge'),
            'woo_product_id' => __('Woo ID', 'woo-catalog-bridge'),
            'updated_at' => __('Updated', 'woo-catalog-bridge'),
        );
    }
    protected function get_sortable_columns() {
        return array('title' => array('title', false), 'category' => array('category', false), 'status' => array('status', false), 'updated_at' => array('updated_at', true));
    }
    protected function column_cb($item) { return '<input type="checkbox" name="product_ids[]" value="' . esc_attr($item['id']) . '" />'; }
    protected function column_default($item, $column_name) { return esc_html(isset($item[$column_name]) ? $item[$column_name] : ''); }
    protected function column_title($item) {
        return '<strong>' . esc_html($item['title'] ?: __('Untitled product', 'woo-catalog-bridge')) . '</strong><div class="row-actions"><span><a href="#" class="wcb-ajax-action" data-task="scrape_product">' . esc_html__('Scrape', 'woo-catalog-bridge') . '</a></span></div>';
    }
    protected function column_source_url($item) { return '<a href="' . esc_url($item['source_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html(wp_trim_words($item['source_url'], 8, '...')) . '</a>'; }
    protected function column_status($item) { return '<span class="wcb-status wcb-status-' . esc_attr($item['status']) . '">' . esc_html(ucfirst($item['status'])) . '</span>'; }
    public function prepare_items() {
        $per_page = 20;
        $page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'updated_at';
        $order = isset($_REQUEST['order']) ? sanitize_key(wp_unslash($_REQUEST['order'])) : 'DESC';
        $args = array('number' => $per_page, 'offset' => ($page - 1) * $per_page, 'search' => $search, 'orderby' => $orderby, 'order' => $order);
        $this->items = WCB_Repository::list_products($args);
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());
        $this->set_pagination_args(array('total_items' => WCB_Repository::count_products($args), 'per_page' => $per_page));
    }
}

class WCB_Logs_List_Table extends WP_List_Table {
    public function get_columns() { return array('level' => __('Level', 'woo-catalog-bridge'), 'message' => __('Message', 'woo-catalog-bridge'), 'created_at' => __('Date', 'woo-catalog-bridge')); }
    protected function column_default($item, $column_name) { return esc_html(isset($item[$column_name]) ? $item[$column_name] : ''); }
    protected function column_level($item) { return '<span class="wcb-log-level wcb-log-' . esc_attr($item['level']) . '">' . esc_html(strtoupper($item['level'])) . '</span>'; }
    public function prepare_items() {
        $per_page = 20;
        $page = $this->get_pagenum();
        $this->items = WCB_Repository::list_logs($per_page, ($page - 1) * $per_page);
        $this->_column_headers = array($this->get_columns(), array(), array());
        $this->set_pagination_args(array('total_items' => WCB_Repository::count_logs(), 'per_page' => $per_page));
    }
}

class WCB_Comparisons_List_Table extends WP_List_Table {
    public function get_columns() {
        return array(
            'name' => __('Name', 'woo-catalog-bridge'),
            'sku' => __('SKU', 'woo-catalog-bridge'),
            'source_price' => __('Source Price', 'woo-catalog-bridge'),
            'destination_price' => __('Destination Price', 'woo-catalog-bridge'),
            'source_stock' => __('Source Stock', 'woo-catalog-bridge'),
            'destination_stock' => __('Destination Stock', 'woo-catalog-bridge'),
            'status' => __('Status', 'woo-catalog-bridge'),
        );
    }

    protected function get_sortable_columns() {
        return array(
            'name' => array('name', false),
            'sku' => array('sku', false),
            'source_price' => array('source_price', false),
            'destination_price' => array('destination_price', false),
            'source_stock' => array('source_stock', false),
            'destination_stock' => array('destination_stock', false),
            'status' => array('status', false),
        );
    }

    protected function column_default($item, $column_name) {
        return esc_html(isset($item[$column_name]) ? $item[$column_name] : '');
    }

    protected function column_name($item) {
        $name = '<strong>' . esc_html($item['name'] ? $item['name'] : __('Untitled product', 'woo-catalog-bridge')) . '</strong>';
        if (!empty($item['source_url'])) {
            $name .= '<div class="row-actions"><span><a href="' . esc_url($item['source_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Source', 'woo-catalog-bridge') . '</a></span></div>';
        }
        return $name;
    }

    protected function column_status($item) {
        return '<span class="wcb-status wcb-status-' . esc_attr(sanitize_title($item['status'])) . '">' . esc_html($item['status']) . '</span>';
    }

    public function prepare_items() {
        $per_page = 20;
        $page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $status = isset($_REQUEST['comparison_status']) ? sanitize_text_field(wp_unslash($_REQUEST['comparison_status'])) : '';
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'compared_at';
        $order = isset($_REQUEST['order']) ? sanitize_key(wp_unslash($_REQUEST['order'])) : 'DESC';
        $args = array('number' => $per_page, 'offset' => ($page - 1) * $per_page, 'search' => $search, 'status' => $status, 'orderby' => $orderby, 'order' => $order);
        $this->items = WCB_Repository::list_comparisons($args);
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());
        $this->set_pagination_args(array('total_items' => WCB_Repository::count_comparisons($args), 'per_page' => $per_page));
    }
}
