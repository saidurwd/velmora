<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Platform\Http\Resources\ApiTypes;

/** Shapes of the catalog answers, for the OpenAPI document. */
final class CatalogTypes
{
    public const IMAGE = 'array{src: string, srcset: string|null, width: int|null, height: int|null}';

    public const CARD = 'array{id: string, name: string, slug: string, type: string, price: '.ApiTypes::MONEY.', compare_at: '.ApiTypes::MONEY.'|null, on_sale: bool, in_stock: bool, has_price_range: bool, image: '.self::IMAGE.'|null}';

    public const FACETS = 'array{brands: list<array{slug: string, name: string, count: int}>, types: array<string, int>, attributes: list<array{code: string, name: string, values: list<array{code: string, label: string, count: int}>}>, price: array{min: '.ApiTypes::MONEY.', max: '.ApiTypes::MONEY.'}|null}';

    public const LIST = 'array{data: list<'.self::CARD.'>, meta: array{page: int, per_page: int, total: int, last_page: int, sort: string, facets: '.self::FACETS.'}, links: '.ApiTypes::LINKS.'}';

    public const SEARCH_LIST = 'array{data: list<'.self::CARD.'>, meta: array{page: int, per_page: int, total: int, last_page: int, sort: string, query: string, corrected_query: string|null, facets: '.self::FACETS.'}, links: '.ApiTypes::LINKS.'}';

    public const NODE = 'array{id: string, name: string, slug: string}';

    public const TREE_NODE = 'array{id: string, name: string, slug: string, children: list<array{id: string, name: string, slug: string, children: list<mixed>}>}';

    public const PRODUCT = 'array{id: string, name: string, slug: string, type: string, short_description: string|null, description_html: string|null, brand: string|null, breadcrumb: list<string>, gallery: list<array{src: string, srcset: string, width: int, height: int, alt: string}>, options: list<array{code: string, name: string, values: list<array{code: string, label: string, selected: bool, in_stock: bool}>}>, variant: array{id: string, sku: string, min_qty: int, max_qty: int|null, qty_step: int}, variant_count: int, price: '.ApiTypes::MONEY.', compare_at: '.ApiTypes::MONEY.'|null, on_sale: bool, sale_ends_at: string|null, tax_note: string|null, stock_state: string}';
}
