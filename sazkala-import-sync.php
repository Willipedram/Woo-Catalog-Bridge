<?php
/**
 * Plugin Name: Sazkala Import & Sync
 * Plugin URI:  https://sazkala.example
 * Description: Professional WooCommerce catalog import and synchronization foundation for Sazkala.
 * Version:     1.0.0
 * Author:      Sazkala
 * Text Domain: sazkala-import-sync
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 *
 * @package Sazkala\ImportSync
 */

declare(strict_types=1);

use Sazkala\ImportSync\Admin\Menu;
use Sazkala\ImportSync\Lifecycle\Activator;
use Sazkala\ImportSync\Lifecycle\Deactivator;

if (! defined('ABSPATH')) {
    exit;
}

define('SAZKALA_IMPORT_SYNC_VERSION', '1.0.0');
define('SAZKALA_IMPORT_SYNC_FILE', __FILE__);
define('SAZKALA_IMPORT_SYNC_PATH', plugin_dir_path(__FILE__));
define('SAZKALA_IMPORT_SYNC_URL', plugin_dir_url(__FILE__));

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Sazkala\\ImportSync\\';
        $baseDir = __DIR__ . '/src/';

        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    });
}

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Sazkala Import & Sync requires WooCommerce to be installed and active.', 'sazkala-import-sync') . '</p></div>';
        });
    }

    (new Menu())->register();
});
