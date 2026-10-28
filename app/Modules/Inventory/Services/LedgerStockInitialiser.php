<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

final class LedgerStockInitialiser implements StockInitialiser
{
    public function __construct(private readonly AdjustStock $adjust) {}

    public function openingStock(Actor $actor, int $variantId, int $quantity, string $locationCode = 'main'): bool
    {
        if ($quantity <= 0) {
            return false;
        }

        return Transactions::run(function () use ($actor, $variantId, $quantity, $locationCode): bool {
            // the product_variants row serialises two imports racing on the same SKU
            DB::table('product_variants')->where('id', $variantId)->lockForUpdate()->first();

            if (DB::table('stock_movements')->where('variant_id', $variantId)->exists()) {
                return false;
            }

            $this->adjust->handle($actor, $variantId, $locationCode, $quantity, 'initial', 'CSV import');

            return true;
        });
    }
}
