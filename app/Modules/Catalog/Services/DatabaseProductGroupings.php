<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\ProductGroupings;
use App\Modules\Catalog\DTOs\VariantGrouping;
use Illuminate\Support\Facades\DB;

final class DatabaseProductGroupings implements ProductGroupings
{
    public function forVariants(array $variantIds, string $locale): array
    {
        $out = [];
        $default = (string) config('app.default_locale');
        foreach (array_chunk(array_values(array_unique($variantIds)), 500) as $chunk) {
            // primary keys: the variants, their products, the primary link of each product (product_categories primary key), the names (unique per owner and locale)
            $rows = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
                ->leftJoin('product_translations as pl', fn ($j) => $j->on('pl.product_id', '=', 'p.id')->where('pl.locale', $locale))
                ->leftJoin('product_translations as pd', fn ($j) => $j->on('pd.product_id', '=', 'p.id')->where('pd.locale', $default))
                ->leftJoin('brand_translations as bl', fn ($j) => $j->on('bl.brand_id', '=', 'p.brand_id')->where('bl.locale', $locale))
                ->leftJoin('brand_translations as bd', fn ($j) => $j->on('bd.brand_id', '=', 'p.brand_id')->where('bd.locale', $default))
                ->whereIn('v.id', $chunk)
                ->get(['v.id', 'v.sku', 'p.id as product_id', 'p.brand_id', DB::raw('COALESCE(pl.name, pd.name) as product_name'), DB::raw('COALESCE(bl.name, bd.name) as brand_name')]);

            $primary = DB::table('product_categories as pc')
                ->leftJoin('category_translations as cl', fn ($j) => $j->on('cl.category_id', '=', 'pc.category_id')->where('cl.locale', $locale))
                ->leftJoin('category_translations as cd', fn ($j) => $j->on('cd.category_id', '=', 'pc.category_id')->where('cd.locale', $default))
                ->whereIn('pc.product_id', array_values(array_unique($rows->pluck('product_id')->map(fn ($v): int => (int) $v)->all())))
                ->orderByDesc('pc.is_primary')->orderBy('pc.category_id')   // the primary one, or else the lowest-numbered one
                ->get(['pc.product_id', 'pc.category_id', DB::raw('COALESCE(cl.name, cd.name) as category_name')]);
            $categoryOf = [];
            foreach ($primary as $link) {
                $categoryOf[(int) $link->product_id] ??= $link;
            }

            foreach ($rows as $r) {
                $category = $categoryOf[(int) $r->product_id] ?? null;
                $out[(int) $r->id] = new VariantGrouping(
                    (int) $r->id, (int) $r->product_id, (string) $r->sku, (string) ($r->product_name ?? ''), $category === null ? null : (int) $category->category_id,
                    $category === null ? null : (string) ($category->category_name ?? ''), $r->brand_id === null ? null : (int) $r->brand_id, $r->brand_id === null ? null : (string) ($r->brand_name ?? ''),
                );
            }
        }

        return $out;
    }
}
