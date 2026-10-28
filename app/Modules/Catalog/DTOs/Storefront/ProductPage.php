<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs\Storefront;

use App\Modules\Platform\DTOs\Money;
use Carbon\CarbonImmutable;

/**
 * Everything the product page shows, resolved for one language and one selected variant. Prices and stock are read live.
 *
 * @phpstan-type Crumb array{label: string, url: string|null}
 */
final readonly class ProductPage
{
    /**
     * @param  list<array{label: string, url: string|null}>  $breadcrumb
     * @param  list<array{src: string, srcset: string, width: int, height: int, alt: string}>  $gallery
     * @param  list<array{code: string, name: string, values: list<array{code: string, label: string, selected: bool, inStock: bool}>}>  $options
     */
    public function __construct(
        public string $publicId,
        public string $name,
        public string $slug,
        public string $type,
        public ?string $shortDescription,
        public ?string $descriptionHtml,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public ?string $brandName,
        public ?string $brandUrl,
        public array $breadcrumb,
        public array $gallery,
        public array $options,
        public string $sku,
        public Money $price,
        public ?Money $compareAt,
        public bool $onSale,
        public ?CarbonImmutable $saleEndsAt,
        public ?string $taxNote,
        public string $stockState,
        public int $variantCount,
    ) {}
}
