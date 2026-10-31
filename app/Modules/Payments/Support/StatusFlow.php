<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Platform\Support\StatusFlow as SharedStatusFlow;

/** Kept for the Payments migrations that already ran with this name; the implementation is shared (Platform\Support\StatusFlow). */
final class StatusFlow
{
    /**
     * @param  array<string, list<string>>  $flow
     * @param  list<string>  $setOnce
     */
    public static function install(string $table, string $column, array $flow, array $setOnce = []): void
    {
        SharedStatusFlow::install($table, $column, $flow, $setOnce);
    }

    public static function remove(string $table): void
    {
        SharedStatusFlow::remove($table);
    }
}
