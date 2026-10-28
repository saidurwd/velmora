<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\DTOs\ListingScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Filter choices for a listing page: brands, product types, filterable attribute values and the price range, each with how
 * many products in the page's scope match. Counts reflect the scope only (not the other active filters) and are labelled so.
 */
final class ListingFacets
{
    /**
     * @return array{
     *     brands: list<array{slug: string, name: string, count: int}>,
     *     types: array<string, int>,
     *     attributes: list<array{code: string, name: string, values: list<array{code: string, label: string, count: int}>}>,
     *     price: array{min: int, max: int}|null
     * }
     */
    public function for(ListingScope $scope, string $locale): array
    {
        $default = (string) config('app.default_locale');
        $base = fn (): Builder => $this->scoped($scope);

        $brands = $base()->whereNotNull('l.brand_id')
            ->join('brand_translations as bl', fn ($j) => $j->on('bl.brand_id', '=', 'l.brand_id')->where('bl.locale', $locale))
            ->groupBy('bl.slug', 'bl.name')->orderBy('bl.name')
            ->get(['bl.slug', 'bl.name', DB::raw('COUNT(*) as c')]);
        if ($brands->isEmpty() && $locale !== $default) {
            $brands = $base()->whereNotNull('l.brand_id')
                ->join('brand_translations as bl', fn ($j) => $j->on('bl.brand_id', '=', 'l.brand_id')->where('bl.locale', $default))
                ->groupBy('bl.slug', 'bl.name')->orderBy('bl.name')
                ->get(['bl.slug', 'bl.name', DB::raw('COUNT(*) as c')]);
        }

        $types = $base()->groupBy('l.product_type')->pluck(DB::raw('COUNT(*)'), 'l.product_type');
        $range = $base()->selectRaw('MIN(l.min_price_minor) as lo, MAX(l.min_price_minor) as hi')->first();

        $attrRows = $base()
            ->join('product_variants as v', fn ($j) => $j->on('v.product_id', '=', 'l.product_id')->whereNull('v.deleted_at')->where('v.status', 'active'))
            ->join('variant_option_values as vov', 'vov.variant_id', '=', 'v.id')
            ->join('attribute_values as av', 'av.id', '=', 'vov.attribute_value_id')
            ->join('attributes as a', fn ($j) => $j->on('a.id', '=', 'av.attribute_id')->where('a.is_filterable', true))
            ->leftJoin('attribute_translations as at', fn ($j) => $j->on('at.attribute_id', '=', 'a.id')->where('at.locale', $locale))
            ->leftJoin('attribute_translations as atd', fn ($j) => $j->on('atd.attribute_id', '=', 'a.id')->where('atd.locale', $default))
            ->leftJoin('attribute_value_translations as vt', fn ($j) => $j->on('vt.attribute_value_id', '=', 'av.id')->where('vt.locale', $locale))
            ->leftJoin('attribute_value_translations as vtd', fn ($j) => $j->on('vtd.attribute_value_id', '=', 'av.id')->where('vtd.locale', $default))
            ->groupBy('a.id', 'a.code', 'av.id', 'av.code', 'av.sort_order', 'at.name', 'atd.name', 'vt.label', 'vtd.label')
            ->orderBy('a.code')->orderBy('av.sort_order')
            ->get(['a.code as acode', DB::raw('COALESCE(at.name, atd.name, a.code) as aname'), 'av.code as vcode', DB::raw('COALESCE(vt.label, vtd.label, av.code) as vlabel'), DB::raw('COUNT(DISTINCT l.product_id) as c')]);

        $attributes = [];
        foreach ($attrRows as $r) {
            $attributes[$r->acode] ??= ['code' => (string) $r->acode, 'name' => (string) $r->aname, 'values' => []];
            $attributes[$r->acode]['values'][] = ['code' => (string) $r->vcode, 'label' => (string) $r->vlabel, 'count' => (int) $r->c];
        }

        return [
            'brands' => array_values($brands->map(fn ($b) => ['slug' => (string) $b->slug, 'name' => (string) $b->name, 'count' => (int) $b->c])->all()),
            'types' => array_map(fn ($c) => (int) $c, array_combine(array_map('strval', array_keys($types->all())), $types->all())),
            'attributes' => array_values($attributes),
            'price' => $range === null || $range->lo === null ? null : ['min' => (int) $range->lo, 'max' => (int) $range->hi],
        ];
    }

    private function scoped(ListingScope $scope): Builder
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

        return $q;
    }
}
