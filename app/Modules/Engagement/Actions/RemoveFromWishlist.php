<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use Illuminate\Support\Facades\DB;

/** Takes a variant off the signed-in customer's list. Only their own list; removing what is not there does nothing. */
final class RemoveFromWishlist
{
    public function __construct(private readonly PurchasableVariants $variants) {}

    public function handle(int $userId, string $variantPublicId): void
    {
        $id = $this->variants->idByPublicId($variantPublicId);
        if ($id !== null) {
            DB::table('wishlist_items')->where('user_id', $userId)->where('variant_id', $id)->delete();   // unique (user_id, variant_id)
        }
    }
}
