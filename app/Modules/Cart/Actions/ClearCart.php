<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Contracts\CartWriter;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Models\CartCoupon;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartRepository;

/** Removes every line and code from the cart (the cart itself stays, ready for the next visit). */
final class ClearCart implements CartWriter
{
    public function __construct(private readonly CartRepository $carts) {}

    public function clear(CartOwner $owner): void
    {
        $cart = $this->carts->find($owner);
        if ($cart === null) {
            return;
        }

        CartItem::query()->where('cart_id', $cart->id)->delete();
        CartCoupon::query()->where('cart_id', $cart->id)->delete();
        $this->carts->touch($cart);
    }
}
