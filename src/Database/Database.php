<?php

declare(strict_types=1);

namespace Sazkala\ImportSync\Database;

use wpdb;

final class Database
{
    public const SOURCES = 'sk_sources';
    public const CATEGORIES = 'sk_categories';
    public const PRODUCT_MAP = 'sk_product_map';
    public const IMPORT_JOBS = 'sk_import_jobs';
    public const IMPORT_LOGS = 'sk_import_logs';
    public const SYNC_HISTORY = 'sk_sync_history';
    public const SCHEDULER = 'sk_scheduler';

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(string $name): string
    {
        return $this->wpdb->prefix . $name;
    }

    public function charsetCollate(): string
    {
        return $this->wpdb->get_charset_collate();
    }
}
