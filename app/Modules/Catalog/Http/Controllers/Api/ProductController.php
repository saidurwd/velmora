<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Modules\Catalog\DTOs\ListingScope;
use App\Modules\Catalog\Http\Requests\Api\ListProductsRequest;
use App\Modules\Catalog\Http\Resources\CatalogTypes;
use App\Modules\Catalog\Http\Resources\ProductResources;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\Storefront\ListingFacets;
use App\Modules\Catalog\Services\Storefront\ListingQuery;
use App\Modules\Catalog\Services\Storefront\ProductPageBuilder;
use App\Modules\Catalog\Services\Storefront\RelatedProducts;
use App\Modules\Catalog\Services\Storefront\SlugLookup;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Platform\Http\Resources\PageEnvelope;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The product catalog for the app. Reads only; it uses the same storefront services as the website, so prices, sales, stock and the language fallback are the website's. */
class ProductController
{
    /**
     * List products
     *
     * One scope at most: the whole shop, a category (with its sub-categories) or a collection. Narrow it with `filter[brand]`, `filter[type]`, `filter[in_stock]`, `filter[price_min]` / `filter[price_max]` (minor units, e.g. poisha) and `filter[attr][code]`. Sort by `newest`, `price_asc`, `price_desc` or `popular`. `meta.facets` tells which filters would leave something to show.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: CatalogTypes::LIST)]
    public function index(ListProductsRequest $request, ListingQuery $query, ListingFacets $facets, SlugLookup $slugs, CategoryTree $tree): JsonResponse
    {
        if ($request->sort() === 'relevance') {
            throw ValidationException::withMessages(['sort' => [__('validation.in', ['attribute' => 'sort'])]]); // relevance only exists for a search
        }

        $locale = app()->getLocale();
        $scope = $this->scope($request, $slugs, $tree, $locale);
        $filters = $request->filters();
        $page = $query->paginateCached($scope, $filters, $locale, $request->perPage());

        return response()->json([
            'data' => array_map(ProductResources::card(...), $page->items()),
            'meta' => PageEnvelope::meta($page) + ['sort' => $filters->sort, 'facets' => ProductResources::facets($facets->for($scope, $locale))],
            'links' => PageEnvelope::links($page, $request),
        ]);
    }

    /**
     * One product
     *
     * Everything the product page shows for one variant. Choose a variant by sending `options[code]=value` (for example `options[size]=m`); the answer says which values are selected, which would be in stock, the price of that variant and the `variant.id` to put in the cart. A slug from another language or an old spelling still finds the product; use the `slug` in the answer from then on.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.CatalogTypes::PRODUCT.'}')]
    public function show(Request $request, string $slug, ProductPageBuilder $builder): JsonResponse
    {
        $locale = app()->getLocale();
        $found = $builder->find($slug, $locale) ?? throw new ApiProblem(ErrorCode::ProductNotFound);

        $requested = [];
        foreach (is_array($request->query('options')) ? array_slice($request->query('options'), 0, 10, true) : [] as $code => $value) {
            if (is_string($code) && is_string($value) && strlen($code) <= 60 && strlen($value) <= 60) {
                $requested[strtolower($code)] = strtolower($value);
            }
        }

        $page = $builder->build($found['product'], $requested, $locale) ?? throw new ApiProblem(ErrorCode::ProductNotFound);

        return response()->json(['data' => ProductResources::page($page)]);
    }

    /**
     * Related products
     *
     * Same category first, then same brand, then newest. Never the product itself.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<'.CatalogTypes::CARD.'>}')]
    public function related(Request $request, string $slug, ProductPageBuilder $builder, RelatedProducts $related): JsonResponse
    {
        $limit = min(12, max(1, (int) $request->query('limit', 6)));
        $found = $builder->find($slug, app()->getLocale()) ?? throw new ApiProblem(ErrorCode::ProductNotFound);

        return response()->json(['data' => array_map(ProductResources::card(...), $related->for($found['product']->id, app()->getLocale(), $limit))]);
    }

    private function scope(ListProductsRequest $request, SlugLookup $slugs, CategoryTree $tree, string $locale): ListingScope
    {
        $category = $request->categorySlug();
        $collection = $request->collectionSlug();

        if ($category !== null && $collection !== null) {
            throw ValidationException::withMessages(['filter.collection' => [__('catalog::api.one_scope')]]);
        }
        if ($category !== null) {
            $found = $slugs->find(Category::class, $category, $locale) ?? throw ValidationException::withMessages(['filter.category' => [__('catalog::api.unknown_slug')]]);

            return ListingScope::category($tree->subtreeIds($found));
        }
        if ($collection !== null) {
            $found = $slugs->find(Collection::class, $collection, $locale) ?? throw ValidationException::withMessages(['filter.collection' => [__('catalog::api.unknown_slug')]]);

            return ListingScope::collection($found->id);
        }

        return ListingScope::all();
    }
}
