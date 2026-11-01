<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\CountStatus;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** Writes down what was counted for one product (it can be corrected while the count is open). A product found that was not on the list is added. */
final class RecordStockCount
{
    /** @throws StockOpsException */
    public function handle(Actor $actor, string $countPublicId, int $variantId, int $counted): void
    {
        if ($counted < 0) {
            throw new StockOpsException('counted_invalid');
        }

        Transactions::run(function () use ($countPublicId, $variantId, $counted): void {
            $count = StockCount::query()->where('public_id', $countPublicId)->lockForUpdate()->first() ?? throw new StockOpsException('count_not_found');
            if ($count->status !== CountStatus::Open) {
                throw new StockOpsException('count_not_open', ['status' => $count->status->value]);
            }
            if (! DB::table('product_variants')->where('id', $variantId)->exists()) {
                throw new StockOpsException('variant_unknown');
            }

            $expected = (int) DB::table('stock_levels')->where('variant_id', $variantId)->where('location_id', $count->location_id)->value('on_hand');
            DB::table('stock_count_lines')->insertOrIgnore(['count_id' => $count->id, 'variant_id' => $variantId, 'expected_qty' => $expected]);
            DB::table('stock_count_lines')->where('count_id', $count->id)->where('variant_id', $variantId)->update(['counted_qty' => $counted]);
        });
    }
}
