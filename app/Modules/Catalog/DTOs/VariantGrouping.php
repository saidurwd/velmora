<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

/** Where a variant sits in the catalogue, for reports: its product (name in the language asked for), the product's primary category and its brand (null when it has none). */
final readonly class VariantGrouping
{
    public function __construct(
        public int $variantId,
        public int $productId,
        public string $sku,
        public string $productName,
        public ?int $categoryId,
        public ?string $categoryName,
        public ?int $brandId,
        public ?string $brandName,
    ) {}
}
