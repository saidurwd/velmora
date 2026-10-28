<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Events\StockLow;
use App\Modules\Platform\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/** Emits StockLow exactly when availability crosses down through the threshold (not on every later change). */
final class StockAlerts
{
    public function __construct(private readonly Outbox $outbox) {}

    public function afterChange(int $variantId, int $locationId, int $availableBefore): void
    {
        $level = DB::table('stock_levels')->where('variant_id', $variantId)->where('location_id', $locationId)->first();

        if ($level === null || $level->low_stock_threshold === null) {
            return;
        }

        $after = (int) $level->on_hand - (int) $level->reserved;
        $threshold = (int) $level->low_stock_threshold;

        if ($availableBefore > $threshold && $after <= $threshold) {
            $this->outbox->record(new StockLow($variantId, $locationId, $after, $threshold));
        }
    }
}
