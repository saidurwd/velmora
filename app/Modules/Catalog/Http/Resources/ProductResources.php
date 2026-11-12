<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\DTOs\ProductCard;
use App\Modules\Catalog\DTOs\Storefront\ProductPage;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Http\Resources\MoneyData;

/** How products leave the API: dedicated shapes, never models, money as {amount_minor, currency, formatted}, enums as stable strings. */
final class ProductResources
{
    /** @return array<string, mixed> */
    public static function card(ProductCard $c): array
    {
        return [
            'id' => $c->publicId,
            'name' => $c->name,
            'slug' => $c->slug,
            'type' => $c->type,
            'price' => MoneyData::from($c->price),
            'compare_at' => $c->compareAt === null ? null : MoneyData::from($c->compareAt),
            'on_sale' => $c->onSale,
            'in_stock' => $c->inStock,
            'has_price_range' => $c->hasPriceRange,
            'image' => $c->image === null ? null : ['src' => $c->image, 'srcset' => $c->srcset, 'width' => $c->imageWidth, 'height' => $c->imageHeight],
        ];
    }

    /** @return array<string, mixed> */
    public static function page(ProductPage $p): array
    {
        return [
            'id' => $p->publicId,
            'name' => $p->name,
            'slug' => $p->slug,
            'type' => $p->type,
            'short_description' => $p->shortDescription,
            'description_html' => $p->descriptionHtml,
            'brand' => $p->brandName,
            'breadcrumb' => array_map(fn (array $b): string => $b['label'], $p->breadcrumb),
            'gallery' => $p->gallery,
            'options' => array_map(fn (array $o): array => [
                'code' => $o['code'], 'name' => $o['name'],
                'values' => array_map(fn (array $v): array => ['code' => $v['code'], 'label' => $v['label'], 'selected' => $v['selected'], 'in_stock' => $v['inStock']], $o['values']),
            ], $p->options),
            'variant' => ['id' => $p->variantPublicId, 'sku' => $p->sku, 'min_qty' => $p->minQty, 'max_qty' => $p->maxQty, 'qty_step' => $p->qtyStep],
            'variant_count' => $p->variantCount,
            'price' => MoneyData::from($p->price),
            'compare_at' => $p->compareAt === null ? null : MoneyData::from($p->compareAt),
            'on_sale' => $p->onSale,
            'sale_ends_at' => $p->saleEndsAt?->toIso8601String(),
            'tax_note' => $p->taxNote,
            'stock_state' => $p->stockState,
        ];
    }

    /**
     * @param  array{brands: list<array{slug: string, name: string, count: int}>, types: array<string, int>, attributes: list<array{code: string, name: string, values: list<array{code: string, label: string, count: int}>}>, price: array{min: int, max: int}|null}  $facets
     * @return array<string, mixed>
     */
    public static function facets(array $facets, string $currency = 'BDT'): array
    {
        return [
            'brands' => $facets['brands'],
            'types' => (object) $facets['types'],
            'attributes' => $facets['attributes'],
            'price' => $facets['price'] === null ? null : ['min' => MoneyData::from(Money::of($facets['price']['min'], $currency)), 'max' => MoneyData::from(Money::of($facets['price']['max'], $currency))],
        ];
    }
}
