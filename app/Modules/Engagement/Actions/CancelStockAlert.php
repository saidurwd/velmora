<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use Illuminate\Support\Facades\DB;

/** The customer no longer wants to be told. Only their own alert; there is nothing to keep, so the row goes. */
final class CancelStockAlert
{
    public function __construct(private readonly PurchasableVariants $variants) {}

    public function handle(int $userId, string $variantPublicId): void
    {
        $id = $this->variants->idByPublicId($variantPublicId);
        if ($id !== null) {
            DB::table('stock_alerts')->where('user_id', $userId)->where('variant_id', $id)->delete();   // unique (user_id, variant_id)
        }
    }
}
