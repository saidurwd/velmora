<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\ScopeDirectory;
use Illuminate\Support\Facades\DB;

final class DatabaseScopeDirectory implements ScopeDirectory
{
    public function productIdsBySku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(fn (string $s) => mb_strtoupper(trim($s)), $skus), fn (string $s) => $s !== '')));
        if ($skus === []) {
            return [];
        }

        $out = [];
        foreach (DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->whereIn('v.sku', $skus)->whereNull('v.deleted_at')->whereNull('p.deleted_at')->get(['v.sku', 'p.id']) as $row) {
            $out[mb_strtoupper((string) $row->sku)] = (int) $row->id;
        }

        return $out;
    }

    public function skusForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $out = [];
        foreach (DB::table('product_variants')->whereIn('product_id', $productIds)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('id')->get(['product_id', 'sku']) as $row) {
            $out[(int) $row->product_id] ??= (string) $row->sku;
        }

        return $out;
    }

    public function categoryOptions(string $locale): array
    {
        $default = (string) config('app.default_locale');
        $rows = DB::table('categories as c')
            ->leftJoin('category_translations as t', fn ($j) => $j->on('t.category_id', '=', 'c.id')->where('t.locale', $locale))
            ->leftJoin('category_translations as d', fn ($j) => $j->on('d.category_id', '=', 'c.id')->where('d.locale', $default))
            ->whereNull('c.deleted_at')->orderBy('c.sort_order')->orderBy('c.id')
            ->get(['c.id', 'c.parent_id', DB::raw('COALESCE(t.name, d.name) as name')]);

        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int) ($r->parent_id ?? 0)][] = $r;
        }

        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $r) {
                $out[] = ['id' => (int) $r->id, 'name' => (string) ($r->name ?? '—'), 'depth' => $depth];
                $walk((int) $r->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }
}
