<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Models\CartCoupon;
use App\Modules\Cart\Services\CartRepository;
use App\Modules\Platform\Support\CouponCodes;

final class RemoveCoupon
{
    public function __construct(private readonly CartRepository $carts) {}

    public function handle(CartOwner $owner, string $code): void
    {
        $cart = $this->carts->find($owner);
        if ($cart === null) {
            return;
        }

        CartCoupon::query()->where('cart_id', $cart->id)->where('code', CouponCodes::normalise($code))->delete();
        $this->carts->touch($cart);
    }
}
