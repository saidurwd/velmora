<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Modules\Cart\DTOs\CartView;

/** What a quote needs to know. Built from the cart and the delivery district; nothing here comes from the browser except the district choice. */
final readonly class ShippingRequest
{
    public function __construct(
        public ?int $districtId,
        public int $merchandiseMinor,
        public int $totalWeightG,
        public bool $requiresShipping,
        public bool $freeShippingCoupon = false,
    ) {}

    public static function fromCart(CartView $cart, ?int $districtId, bool $freeShippingCoupon = false): self
    {
        $weight = 0;
        $physical = false;
        foreach ($cart->lines as $line) {
            if ($line->problem === 'unavailable') {
                continue;
            }
            if ($line->productType === 'physical') {
                $physical = true;
                $weight += $line->weightG * $line->quantity;
            }
        }

        return new self($districtId, $cart->subtotal->amountMinor, $weight, $physical, $freeShippingCoupon);
    }
}
