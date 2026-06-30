<?php

declare(strict_types=1);

namespace Sazkala\ImportSync\Lifecycle;

use Sazkala\ImportSync\Database\Installer;

final class Activator
{
    public static function activate(): void
    {
        (new Installer())->install();
        update_option('sazkala_import_sync_version', SAZKALA_IMPORT_SYNC_VERSION, false);
        flush_rewrite_rules();
    }
}
