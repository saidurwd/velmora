<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

/** Everything the cart and checkout may know about a variant: what to show, whether it can be bought, and its quantity rules. */
final readonly class PurchasableVariant
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $sku,
        public string $productName,
        public ?string $optionsLabel,
        public string $productSlug,
        public ?string $imageUrl,
        public string $productType,
        public string $taxClassCode,
        public bool $isPurchasable,
        public bool $trackInventory,
        public int $minQty,
        public ?int $maxQty,
        public int $qtyStep,
    ) {}
}
