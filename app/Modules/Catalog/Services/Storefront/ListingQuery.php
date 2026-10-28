<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\DTOs\ListingFilters;
use App\Modules\Catalog\DTOs\ListingScope;
use App\Modules\Catalog\DTOs\ProductCard;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\StorefrontCache;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Reads the `catalog_listing` read model: filters, sorts and pages storefront product cards with a fixed number of queries. */
final class ListingQuery
{
    public function __construct(private readonly StorefrontCache $cache) {}

    public const PER_PAGE = 24;

    /** @return LengthAwarePaginator<int, ProductCard> */
    public function paginate(ListingScope $scope, ListingFilters $filters, string $locale, int $perPage = self::PER_PAGE, ?callable $extra = null): LengthAwarePaginator
    {
        $default = (string) config('app.default_locale');

        $query = $this->base($scope, $filters);
        if ($extra !== null) {
            $extra($query);
        }
        $total = (clone $query)->count();

        $query = $query
            ->leftJoin('product_translations as pl', fn ($j) => $j->on('pl.product_id', '=', 'l.product_id')->where('pl.locale', $locale))
            ->leftJoin('product_translations as pd', fn ($j) => $j->on('pd.product_id', '=', 'l.product_id')->where('pd.locale', $default))
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->select('l.*', 'p.public_id', DB::raw('COALESCE(pl.name, pd.name) as name'), DB::raw('COALESCE(pl.slug, pd.slug) as slug'));

        if ($filters->sort === 'relevance' && $scope->productIds !== null && $scope->productIds !== []) {
            // search results arrive already ranked (at most a couple of hundred): order them in PHP, then take the page
            $rank = array_flip($scope->productIds);
            $all = $query->get()->sortBy(fn ($r) => $rank[(int) $r->product_id] ?? PHP_INT_MAX)->values();
            $rows = $all->slice(($filters->page - 1) * $perPage, $perPage)->values();
        } else {
            $rows = $query->tap(fn (Builder $q) => $this->sort($q, $filters->sort))->forPage($filters->page, $perPage)->get();
        }

        return new LengthAwarePaginator($rows->map(fn ($r) => $this->card($r))->all(), $total, $perPage, $filters->page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    /**
     * The same page, served from the short-lived storefront cache (instantly invalidated by catalog, price and stock changes).
     *
     * @return LengthAwarePaginator<int, ProductCard>
     */
    public function paginateCached(ListingScope $scope, ListingFilters $filters, string $locale, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $page = $this->cache->remember('list:'.md5(json_encode([$scope, $filters, $perPage], JSON_THROW_ON_ERROR)), function () use ($scope, $filters, $locale, $perPage): array {
            $p = $this->paginate($scope, $filters, $locale, $perPage);

            return ['items' => $p->items(), 'total' => $p->total()];
        });

        return new LengthAwarePaginator($page['items'], $page['total'], $perPage, $filters->page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    /**
     * Newest published products regardless of filters (home page rails).
     *
     * @return list<ProductCard>
     */
    public function newest(string $locale, int $limit = 8): array
    {
        return array_values($this->paginate(ListingScope::all(), new ListingFilters, $locale, $limit)->items());
    }

    /** @return list<ProductCard> */
    public function forCollection(int $collectionId, string $locale, int $limit = 8): array
    {
        return array_values($this->paginate(ListingScope::collection($collectionId), new ListingFilters, $locale, $limit)->items());
    }

    /** @return list<ProductCard> */
    public function popular(string $locale, int $limit = 8): array
    {
        return array_values($this->paginate(ListingScope::all(), new ListingFilters(sort: 'popular'), $locale, $limit, fn (Builder $q) => $q->where('l.popularity', '>', 0))->items());
    }

    private function base(ListingScope $scope, ListingFilters $f): Builder
    {
        $q = DB::table('catalog_listing as l');

        if ($scope->categoryIds !== []) {
            $q->whereIn('l.product_id', DB::table('product_categories')->whereIn('category_id', $scope->categoryIds)->select('product_id'));
        }
        if ($scope->productIds !== null) {
            $q->whereIn('l.product_id', $scope->productIds === [] ? [0] : $scope->productIds);
        }
        if ($scope->brandId !== null) {
            $q->where('l.brand_id', $scope->brandId);
        }
        if ($scope->collectionId !== null) {
            $q->whereIn('l.product_id', DB::table('collection_products')->where('collection_id', $scope->collectionId)->select('product_id'));
        }

        if ($f->brands !== []) {
            $q->whereIn('l.brand_id', DB::table('brand_translations')->whereIn('slug', $f->brands)->select('brand_id'));
        }
        if ($f->minMinor !== null) {
            $q->where('l.min_price_minor', '>=', $f->minMinor);
        }
        if ($f->maxMinor !== null) {
            $q->where('l.min_price_minor', '<=', $f->maxMinor);
        }
        if ($f->types !== []) {
            $q->whereIn('l.product_type', $f->types);
        }
        if ($f->inStock) {
            $q->where('l.in_stock', true);
        }

        foreach ($f->attributes as $code => $valueCodes) {
            $q->where(fn (Builder $w) => $w
                ->whereExists(fn (Builder $e) => $e->select(DB::raw(1))->from('product_variants as v')
                    ->join('variant_option_values as vov', 'vov.variant_id', '=', 'v.id')
                    ->join('attribute_values as av', 'av.id', '=', 'vov.attribute_value_id')
                    ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
                    ->whereColumn('v.product_id', 'l.product_id')->whereNull('v.deleted_at')->where('v.status', 'active')
                    ->where('a.code', $code)->whereIn('av.code', $valueCodes))
                ->orWhereExists(fn (Builder $e) => $e->select(DB::raw(1))->from('product_attribute_values as pav')
                    ->join('attribute_values as av2', 'av2.id', '=', 'pav.attribute_value_id')
                    ->join('attributes as a2', 'a2.id', '=', 'av2.attribute_id')
                    ->whereColumn('pav.product_id', 'l.product_id')->where('a2.code', $code)->whereIn('av2.code', $valueCodes)));
        }

        return $q;
    }

    private function sort(Builder $q, string $sort): void
    {
        match ($sort) {
            'price_asc' => $q->orderBy('l.min_price_minor')->orderBy('l.product_id'),
            'price_desc' => $q->orderByDesc('l.min_price_minor')->orderByDesc('l.product_id'),
            'popular' => $q->orderByDesc('l.popularity')->orderByDesc('l.published_at')->orderByDesc('l.product_id'),
            default => $q->orderByDesc('l.published_at')->orderByDesc('l.product_id'),
        };
    }

    private function card(\stdClass $r): ProductCard
    {
        $renditions = $r->image_renditions === null ? [] : (array) json_decode((string) $r->image_renditions, true);
        $webp = (array) ($renditions['webp'] ?? []);
        ksort($webp, SORT_NUMERIC);
        $disk = $r->image_disk === null ? null : Storage::disk((string) $r->image_disk);

        $srcset = $disk === null || $webp === [] ? null : implode(', ', array_map(fn ($w, $p) => $disk->url((string) $p)." {$w}w", array_keys($webp), $webp));
        $src = $disk === null || $webp === [] ? null : $disk->url((string) ($webp[320] ?? reset($webp)));

        return new ProductCard(
            (string) $r->public_id, (string) ($r->name ?? ''), (string) ($r->slug ?? ''), (string) $r->product_type,
            Money::of((int) $r->min_price_minor), $r->compare_at_minor === null ? null : Money::of((int) $r->compare_at_minor),
            (bool) $r->on_sale, (bool) $r->in_stock, (int) $r->max_price_minor > (int) $r->min_price_minor,
            $src, $srcset, $r->image_width === null ? null : (int) $r->image_width, $r->image_height === null ? null : (int) $r->image_height,
        );
    }
}
