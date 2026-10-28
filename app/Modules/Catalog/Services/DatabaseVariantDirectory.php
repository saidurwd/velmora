<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Catalog\DTOs\VariantSummary;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseVariantDirectory implements VariantDirectory
{
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $this->summaries($this->base()->where(function ($q) use ($like) {
            $q->where('product_variants.sku', 'like', $like)->orWhere('pt.name', 'like', $like);
        })->orderBy('product_variants.sku')->limit(min(max($limit, 1), 50)));
    }

    public function findBySku(string $sku): ?VariantSummary
    {
        return $this->summaries($this->base()->where('product_variants.sku', trim($sku))->limit(1))[0] ?? null;
    }

    public function findMany(array $ids): array
    {
        $out = [];

        foreach ($this->summaries($this->base()->whereIn('product_variants.id', $ids)) as $summary) {
            $out[$summary->id] = $summary;
        }

        return $out;
    }

    private function base(): Builder
    {
        $default = (string) config('app.default_locale');

        return DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('product_translations as pt', fn ($j) => $j->on('pt.product_id', '=', 'products.id')->where('pt.locale', $default))
            ->whereNull('product_variants.deleted_at')->whereNull('products.deleted_at')
            ->select('product_variants.id', 'product_variants.sku', 'product_variants.status', 'product_variants.track_inventory', 'products.public_id as product_public_id', 'products.product_type', 'products.tax_class_code', 'pt.name as product_name');
    }

    /** @return list<VariantSummary> */
    private function summaries(Builder $query): array
    {
        return array_values($query->get()->map(fn ($r) => new VariantSummary((int) $r->id, (string) $r->sku, (string) ($r->product_name ?? ''), (string) $r->product_public_id, (string) $r->product_type, (string) $r->tax_class_code, (string) $r->status, (bool) $r->track_inventory))->all());
    }
}
