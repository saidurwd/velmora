<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\DTOs\Storefront\ProductPage;
use App\Modules\Platform\Support\StructuredData;

/** schema.org Product + Offer for the product page: price, currency, availability and sale end, from the same live values the page shows. */
final class ProductStructuredData
{
    /** @return array<string, mixed> */
    public function product(ProductPage $page, string $url): array
    {
        $availability = in_array($page->stockState, ['out_of_stock'], true) ? 'OutOfStock' : 'InStock';

        $offer = array_filter([
            '@type' => 'Offer', 'url' => $url,
            'price' => $page->price->toMajorString(), 'priceCurrency' => $page->price->currency,
            'availability' => 'https://schema.org/'.$availability, 'itemCondition' => 'https://schema.org/NewCondition',
            'priceValidUntil' => $page->saleEndsAt?->utc()->format('Y-m-d'),
        ], fn ($v) => $v !== null);

        return array_filter([
            '@type' => 'Product', 'name' => $page->name, 'sku' => $page->sku,
            'description' => $page->shortDescription ?: ($page->descriptionHtml ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($page->descriptionHtml))), 0, 500) : null),
            'image' => $page->gallery === [] ? null : array_map(fn ($i) => StructuredData::absolute($i['src']), $page->gallery),
            'brand' => $page->brandName === null ? null : ['@type' => 'Brand', 'name' => $page->brandName],
            'offers' => $offer,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
