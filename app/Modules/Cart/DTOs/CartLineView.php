<?php

declare(strict_types=1);

namespace App\Modules\Cart\DTOs;

use App\Modules\Platform\DTOs\Money;

/**
 * One cart line with live data. `problem` is null, 'unavailable' (can no longer be bought) or 'insufficient_stock';
 * `priceChangedFrom` is set when the price differs from what the customer last accepted.
 */
final readonly class CartLineView
{
    public function __construct(
        public string $variantPublicId,
        public int $variantId,
        public string $sku,
        public string $name,
        public ?string $optionsLabel,
        public string $productSlug,
        public ?string $imageUrl,
        public string $productType,
        public int $quantity,
        public int $minQty,
        public ?int $maxQty,
        public int $qtyStep,
        public ?Money $unitPrice,
        public ?Money $lineTotal,
        public ?Money $priceChangedFrom,
        public ?string $problem,
        public ?int $available,
        public int $weightG = 0,
    ) {}
}
