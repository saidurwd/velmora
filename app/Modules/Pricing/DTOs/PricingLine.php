<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

/** One thing being bought. The price is NOT here: the engine reads it (with any sale) from the price list. */
final readonly class PricingLine
{
    public function __construct(
        public int $variantId,
        public string $taxClassCode,
        public int $quantity,
        public int $lineDiscountMinor = 0,   // a discount that applies to this line only (e.g. a product-scoped promotion)
    ) {}
}
