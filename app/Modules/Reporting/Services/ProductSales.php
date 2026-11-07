<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Catalog\Contracts\ProductGroupings;
use App\Modules\Reporting\DTOs\MetricInputs;
use App\Modules\Reporting\DTOs\ReportFilter;

/**
 * Sales split by product, category or brand. The summary stores sales per product VARIANT; the product, its primary category and its brand are looked up when the report is
 * opened (so moving a product to another category moves its past sales too). Each product sits in one category and has at most one brand, so every split adds up to the same total.
 * A variant that has been removed from the catalogue is shown under its sku.
 */
final class ProductSales
{
    public function __construct(private readonly ReportReader $reader, private readonly ProductGroupings $groupings) {}

    /**
     * One entry per product (the variants of a product added together), biggest sales first.
     *
     * @return list<array{key: string, name: string, category: ?string, categoryId: ?int, brand: ?string, brandId: ?int, variants: int, inputs: MetricInputs}>
     */
    public function byProduct(ReportFilter $filter, string $locale): array
    {
        $sold = $this->reader->byVariant($filter);
        $info = $this->groupings->forVariants(array_keys($sold), $locale);

        $products = [];
        foreach ($sold as $variantId => $row) {
            $g = $info[$variantId] ?? null;
            $key = $g === null ? 'v'.$variantId : 'p'.$g->productId;
            $products[$key] ??= ['key' => $key, 'name' => $g === null ? $row['sku'] : $g->productName, 'category' => $g?->categoryName, 'categoryId' => $g?->categoryId, 'brand' => $g?->brandName, 'brandId' => $g?->brandId, 'variants' => 0, 'inputs' => new MetricInputs];
            $products[$key]['variants']++;
            $products[$key]['inputs'] = self::add($products[$key]['inputs'], $row['inputs']);
        }
        $list = array_values($products);
        usort($list, fn (array $a, array $b): int => ($b['inputs']->grossMinor - $b['inputs']->discountsMinor) <=> ($a['inputs']->grossMinor - $a['inputs']->discountsMinor) ?: strcmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));

        return $list;
    }

    /**
     * The products added together under their primary category (or brand): one entry per group, biggest sales first.
     *
     * @param  'category'|'brand'  $by
     * @return list<array{name: ?string, products: int, inputs: MetricInputs}> `name` is null for products without a category (or brand)
     */
    public function grouped(ReportFilter $filter, string $locale, string $by): array
    {
        $groups = [];
        foreach ($this->byProduct($filter, $locale) as $p) {
            $id = $by === 'category' ? $p['categoryId'] : $p['brandId'];
            $key = $id === null ? '-' : (string) $id;
            $groups[$key] ??= ['name' => $by === 'category' ? $p['category'] : $p['brand'], 'products' => 0, 'inputs' => new MetricInputs];
            $groups[$key]['products']++;
            $groups[$key]['inputs'] = self::add($groups[$key]['inputs'], $p['inputs']);
        }
        $list = array_values($groups);
        usort($list, fn (array $a, array $b): int => ($b['inputs']->grossMinor - $b['inputs']->discountsMinor) <=> ($a['inputs']->grossMinor - $a['inputs']->discountsMinor) ?: strcmp((string) $a['name'], (string) $b['name']));

        return $list;
    }

    private static function add(MetricInputs $a, MetricInputs $b): MetricInputs
    {
        return new MetricInputs(
            grossMinor: $a->grossMinor + $b->grossMinor, discountsMinor: $a->discountsMinor + $b->discountsMinor, unitsSold: $a->unitsSold + $b->unitsSold, returnedUnits: $a->returnedUnits + $b->returnedUnits,
        );
    }
}
