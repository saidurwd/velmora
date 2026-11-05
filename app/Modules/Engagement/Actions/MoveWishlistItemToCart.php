<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Cart\Contracts\CartFiller;
use App\Modules\Cart\DTOs\CartFillResult;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\SavableVariants;
use Illuminate\Support\Facades\DB;

/** Puts a saved item in the customer's cart (the smallest order the item allows, at today's price). Once it is in the cart it leaves the list; if it cannot go in it stays, and the customer is told why. */
final class MoveWishlistItemToCart
{
    public function __construct(private readonly SavableVariants $variants, private readonly CartFiller $cart) {}

    /** @throws EngagementException not_found | service | not_saved */
    public function handle(int $userId, string $variantPublicId, string $locale): CartFillResult
    {
        $variant = $this->variants->resolve($variantPublicId, $locale);
        if (! DB::table('wishlist_items')->where('user_id', $userId)->where('variant_id', $variant->id)->exists()) {   // unique (user_id, variant_id)
            throw new EngagementException('not_saved');
        }

        $result = $this->cart->add(CartOwner::user($userId), $variant->id, max(1, $variant->minQty), $locale);
        if ($result->wasAdded()) {
            DB::table('wishlist_items')->where('user_id', $userId)->where('variant_id', $variant->id)->delete();
        }

        return $result;
    }
}
