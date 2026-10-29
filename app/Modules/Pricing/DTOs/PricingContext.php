<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

/**
 * Everything the engine needs to price an order. Discount amounts arrive already decided (by the coupon / promotion
 * evaluators); shipping and the cash-on-delivery fee arrive from the shipping quote. The engine clamps defensively: a
 * discount can never exceed what it applies to.
 */
final readonly class PricingContext
{
    /** @param  list<PricingLine>  $lines */
    public function __construct(
        public array $lines,
        public int $orderDiscountMinor = 0,   // spread across the lines in proportion to what each line costs
        public int $shippingMinor = 0,
        public int $codFeeMinor = 0,
    ) {}
}
