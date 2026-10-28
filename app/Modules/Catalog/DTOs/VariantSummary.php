<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

/** What other modules (Pricing/Inventory admin screens, Cart) may know about a variant. */
final readonly class VariantSummary
{
    public function __construct(
        public int $id,
        public string $sku,
        public string $productName,
        public string $productPublicId,
        public string $productType,
        public string $taxClassCode,
        public string $status,
        public bool $trackInventory,
    ) {}
}
