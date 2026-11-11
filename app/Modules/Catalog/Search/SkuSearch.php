<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Search;

use App\Modules\Platform\Contracts\SearchProvider;
use App\Modules\Platform\DTOs\SearchHit;
use Illuminate\Support\Facades\DB;

/** Products by the start of a variant's SKU. */
final class SkuSearch implements SearchProvider
{
    public function kind(): string
    {
        return 'catalog::search.products';
    }

    public function permissions(): array
    {
        return ['catalog.view'];
    }

    public function search(string $term, int $limit, bool $seePii): array
    {
        $sku = trim($term);
        if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $sku) !== 1) {
            return [];
        }

        return array_values(DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->whereNull('p.deleted_at')
            ->where('v.sku', 'like', addcslashes($sku, '%_\\').'%')->orderBy('v.sku')->limit($limit)->get(['v.sku', 'p.public_id', 'p.product_type', 'p.status'])   // unique index sku: a prefix range
            ->map(fn ($r): SearchHit => new SearchHit((string) $r->sku, __('catalog::search.detail', ['type' => (string) $r->product_type, 'status' => (string) $r->status]), route('admin.catalog.products.edit', (string) $r->public_id)))->all());
    }
}
