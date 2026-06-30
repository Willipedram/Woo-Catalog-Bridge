<?php
/**
 * Plugin Name: Woo Catalog Bridge
 * Description: Discovers products from sitemaps, scrapes product data, and synchronizes catalog items with WooCommerce.
 * Version: 0.2.0
 * Author: Woo Catalog Bridge
 * Text Domain: woo-catalog-bridge
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCB_VERSION', '0.2.0');
define('WCB_FILE', __FILE__);
define('WCB_PATH', plugin_dir_path(__FILE__));
define('WCB_URL', plugin_dir_url(__FILE__));

require_once WCB_PATH . 'includes/class-wcb-plugin.php';

register_activation_hook(__FILE__, array('WCB_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('WCB_Plugin', 'deactivate'));

WCB_Plugin::instance();
