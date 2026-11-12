<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Modules\Catalog\DTOs\ListingScope;
use App\Modules\Catalog\Http\Requests\Api\ListProductsRequest;
use App\Modules\Catalog\Http\Resources\CatalogTypes;
use App\Modules\Catalog\Http\Resources\ProductResources;
use App\Modules\Catalog\Services\Storefront\ListingFacets;
use App\Modules\Catalog\Services\Storefront\ListingQuery;
use App\Modules\Platform\Http\Resources\PageEnvelope;
use App\Modules\Search\Contracts\CatalogSearch;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Search over the catalog, in English and Bangla, with typo help. The same engine as the website's search box. */
class SearchController
{
    /**
     * Search products
     *
     * `q` is 1 to 100 characters. Results come best first (`sort=relevance`, the default) or in any other order, and take the same `filter[...]`, `page` and `per_page` as the product list. When the exact words find nothing but a close spelling does, `meta.corrected_query` says which one was used. The first page of a real search is logged (text, language and result count only) to improve the shop.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: CatalogTypes::SEARCH_LIST)]
    public function search(ListProductsRequest $request, CatalogSearch $search, ListingQuery $query, ListingFacets $facets): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']])['q'];
        $raw = trim($q);
        $locale = app()->getLocale();

        $result = $search->search($raw, $locale);
        $scope = ListingScope::ids($result->productIds);
        $filters = $request->filters('relevance');
        $page = $query->paginate($scope, $filters, $locale, $request->perPage());

        if ($filters->page === 1 && $raw !== '') {
            $search->logQuery($result->normalizedQuery, $locale, count($result->productIds));
        }

        return response()->json([
            'data' => array_map(ProductResources::card(...), $page->items()),
            'meta' => PageEnvelope::meta($page) + ['sort' => $filters->sort, 'query' => $raw, 'corrected_query' => $result->correctedQuery, 'facets' => ProductResources::facets($facets->for($scope, $locale))],
            'links' => PageEnvelope::links($page, $request),
        ]);
    }

    /**
     * Search suggestions
     *
     * Product names as someone types: at least 2 characters, at most 10 suggestions. Never logged and not personal, so it may be cached for a minute.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<array{name: string, slug: string}>}')]
    public function autocomplete(Request $request, CatalogSearch $search): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:10']]);
        $q = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 6);

        $items = mb_strlen($q) < 2 ? [] : array_map(fn ($s): array => ['name' => $s->name, 'slug' => $s->slug], $search->suggest($q, app()->getLocale(), $limit));

        return response()->json(['data' => $items]);
    }
}
