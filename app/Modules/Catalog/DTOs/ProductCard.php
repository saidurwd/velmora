<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

use App\Modules\Platform\DTOs\Money;

/** One product as shown on storefront cards: everything a template needs, no models. */
final readonly class ProductCard
{
    public function __construct(
        public string $publicId,
        public string $name,
        public string $slug,
        public string $type,
        public Money $price,
        public ?Money $compareAt,
        public bool $onSale,
        public bool $inStock,
        public bool $hasPriceRange,
        public ?string $image,
        public ?string $srcset,
        public ?int $imageWidth,
        public ?int $imageHeight,
    ) {}
}
