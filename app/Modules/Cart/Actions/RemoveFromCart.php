<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartRepository;

final class RemoveFromCart
{
    public function __construct(private readonly CartRepository $carts) {}

    public function handle(CartOwner $owner, int $variantId): void
    {
        $cart = $this->carts->find($owner);
        if ($cart === null) {
            return; // nothing to remove: not an error
        }

        CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->delete();
        $this->carts->touch($cart);
    }
}
