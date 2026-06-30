<?php

declare(strict_types=1);

namespace Sazkala\ImportSync\Admin;

final class Menu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Sazkala Import & Sync', 'sazkala-import-sync'),
            __('Sazkala Sync', 'sazkala-import-sync'),
            'manage_woocommerce',
            'sazkala-import-sync',
            [$this, 'renderPage'],
            'dashicons-update-alt',
            56
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'sazkala-import-sync'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Sazkala Import & Sync', 'sazkala-import-sync') . '</h1>';
        echo '<p>' . esc_html__('The import and synchronization foundation is installed and ready for WooCommerce catalog integrations.', 'sazkala-import-sync') . '</p>';
        echo '</div>';
    }
}
