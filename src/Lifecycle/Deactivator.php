<?php

declare(strict_types=1);

namespace Sazkala\ImportSync\Lifecycle;

final class Deactivator
{
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('sazkala_import_sync_run_scheduler');
        flush_rewrite_rules();
    }
}
