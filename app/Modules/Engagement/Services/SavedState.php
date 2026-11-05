<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use Illuminate\Support\Facades\DB;

/** For the product page: has this customer saved this variant, or asked to be told when it is back? */
final class SavedState
{
    public function __construct(private readonly PurchasableVariants $variants) {}

    /** @return array{saved: bool, alert: bool} */
    public function forVariant(int $userId, string $variantPublicId): array
    {
        $id = $this->variants->idByPublicId($variantPublicId);
        if ($id === null) {
            return ['saved' => false, 'alert' => false];
        }

        return [
            'saved' => DB::table('wishlist_items')->where('user_id', $userId)->where('variant_id', $id)->exists(),   // unique (user_id, variant_id)
            'alert' => DB::table('stock_alerts')->where('user_id', $userId)->where('variant_id', $id)->where('status', 'pending')->exists(),
        ];
    }
}
