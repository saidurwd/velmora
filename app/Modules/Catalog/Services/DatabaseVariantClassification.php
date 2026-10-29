<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\VariantClassification;
use Illuminate\Support\Facades\DB;

final class DatabaseVariantClassification implements VariantClassification
{
    public function forVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $variants = DB::table('product_variants')->whereIn('id', $variantIds)->pluck('product_id', 'id');
        $productIds = array_values(array_unique(array_map('intval', $variants->all())));

        $byProduct = [];
        foreach (DB::table('product_categories as pc')->join('category_closure as cc', 'cc.descendant_id', '=', 'pc.category_id')->whereIn('pc.product_id', $productIds)->get(['pc.product_id', 'cc.ancestor_id']) as $r) {
            $byProduct[(int) $r->product_id][(int) $r->ancestor_id] = true;
        }

        $out = [];
        foreach ($variants as $variantId => $productId) {
            $out[(int) $variantId] = ['productId' => (int) $productId, 'categoryIds' => array_map('intval', array_keys($byProduct[(int) $productId] ?? []))];
        }

        return $out;
    }
}
