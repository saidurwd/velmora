<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

/**
 * One priced line. `grossMinor` = unit price × quantity (shop price: tax-inclusive when `taxInclusive`). `totalMinor` is what
 * remains after this line's own discount and its share of the order discount. Tax is taken from `totalMinor` once, on the whole line.
 */
final readonly class BreakdownLine
{
    public function __construct(
        public int $variantId,
        public int $quantity,
        public int $unitPriceMinor,
        public int $grossMinor,
        public int $lineDiscountMinor,
        public int $orderDiscountShareMinor,
        public int $totalMinor,
        public int $taxRateBp,
        public int $taxMinor,
    ) {}
}
