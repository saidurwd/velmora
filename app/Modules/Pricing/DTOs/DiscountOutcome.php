<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

/**
 * What the shopper gets. `lineDiscounts` (coupons, spread over the lines they apply to) and `orderDiscountMinor`
 * (automatic promotion) plug straight into PricingContext; `freeShipping` into the shipping request.
 */
final readonly class DiscountOutcome
{
    /**
     * @param  array<int, int>  $lineDiscounts  variant id => minor units
     * @param  list<AppliedDiscount>  $applied
     * @param  list<array{code: string, reason: string, min?: int}>  $rejected
     */
    public function __construct(public array $lineDiscounts = [], public int $orderDiscountMinor = 0, public bool $freeShipping = false, public array $applied = [], public array $rejected = []) {}

    public function totalMinor(): int
    {
        return array_sum($this->lineDiscounts) + $this->orderDiscountMinor;
    }

    /** @return list<string> codes of the coupons that were accepted */
    public function acceptedCodes(): array
    {
        return array_values(array_filter(array_map(fn (AppliedDiscount $a) => $a->kind === 'coupon' ? $a->code : null, $this->applied)));
    }
}
