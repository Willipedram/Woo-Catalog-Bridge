<?php

declare(strict_types=1);

namespace Sazkala\ImportSync\Database;

final class Installer
{
    public function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $database = new Database($wpdb);
        $charset = $database->charsetCollate();

        foreach ($this->schemas($database, $charset) as $schema) {
            dbDelta($schema);
        }
    }

    /**
     * @return string[]
     */
    private function schemas(Database $database, string $charset): array
    {
        $sources = $database->table(Database::SOURCES);
        $categories = $database->table(Database::CATEGORIES);
        $productMap = $database->table(Database::PRODUCT_MAP);
        $importJobs = $database->table(Database::IMPORT_JOBS);
        $importLogs = $database->table(Database::IMPORT_LOGS);
        $syncHistory = $database->table(Database::SYNC_HISTORY);
        $scheduler = $database->table(Database::SCHEDULER);

        return [
            "CREATE TABLE {$sources} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(191) NOT NULL,
                type varchar(60) NOT NULL DEFAULT 'api',
                endpoint_url text NULL,
                credentials longtext NULL,
                settings longtext NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                last_synced_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY type (type),
                KEY last_synced_at (last_synced_at)
            ) {$charset};",
            "CREATE TABLE {$categories} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                source_id bigint(20) unsigned NOT NULL,
                external_id varchar(191) NOT NULL,
                external_parent_id varchar(191) NULL,
                woo_category_id bigint(20) unsigned NULL,
                name varchar(191) NOT NULL,
                slug varchar(191) NULL,
                path text NULL,
                metadata longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_external (source_id, external_id),
                KEY source_id (source_id),
                KEY woo_category_id (woo_category_id),
                KEY external_parent_id (external_parent_id)
            ) {$charset};",
            "CREATE TABLE {$productMap} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                source_id bigint(20) unsigned NOT NULL,
                external_product_id varchar(191) NOT NULL,
                external_sku varchar(191) NULL,
                product_id bigint(20) unsigned NOT NULL,
                variation_id bigint(20) unsigned NULL,
                checksum varchar(64) NULL,
                sync_status varchar(30) NOT NULL DEFAULT 'pending',
                last_imported_at datetime NULL,
                last_synced_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_external_product (source_id, external_product_id),
                KEY product_id (product_id),
                KEY variation_id (variation_id),
                KEY external_sku (external_sku),
                KEY sync_status (sync_status)
            ) {$charset};",
            "CREATE TABLE {$importJobs} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                source_id bigint(20) unsigned NOT NULL,
                job_type varchar(60) NOT NULL DEFAULT 'import',
                status varchar(30) NOT NULL DEFAULT 'queued',
                total_items int(11) unsigned NOT NULL DEFAULT 0,
                processed_items int(11) unsigned NOT NULL DEFAULT 0,
                failed_items int(11) unsigned NOT NULL DEFAULT 0,
                payload longtext NULL,
                started_at datetime NULL,
                finished_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY source_id (source_id),
                KEY status (status),
                KEY job_type (job_type),
                KEY created_at (created_at)
            ) {$charset};",
            "CREATE TABLE {$importLogs} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_id bigint(20) unsigned NULL,
                source_id bigint(20) unsigned NULL,
                level varchar(20) NOT NULL DEFAULT 'info',
                message text NOT NULL,
                context longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY job_id (job_id),
                KEY source_id (source_id),
                KEY level (level),
                KEY created_at (created_at)
            ) {$charset};",
            "CREATE TABLE {$syncHistory} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                source_id bigint(20) unsigned NOT NULL,
                product_map_id bigint(20) unsigned NULL,
                action varchar(60) NOT NULL,
                status varchar(30) NOT NULL,
                old_hash varchar(64) NULL,
                new_hash varchar(64) NULL,
                changes longtext NULL,
                synced_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY source_id (source_id),
                KEY product_map_id (product_map_id),
                KEY action (action),
                KEY status (status),
                KEY synced_at (synced_at)
            ) {$charset};",
            "CREATE TABLE {$scheduler} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                source_id bigint(20) unsigned NOT NULL,
                hook varchar(191) NOT NULL,
                recurrence varchar(60) NOT NULL DEFAULT 'hourly',
                status varchar(30) NOT NULL DEFAULT 'active',
                next_run_at datetime NULL,
                last_run_at datetime NULL,
                locked_until datetime NULL,
                settings longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY source_id (source_id),
                KEY hook (hook),
                KEY status (status),
                KEY next_run_at (next_run_at),
                KEY locked_until (locked_until)
            ) {$charset};",
        ];
    }
}
