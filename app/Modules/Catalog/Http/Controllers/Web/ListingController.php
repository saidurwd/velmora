<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Web;

use App\Modules\Catalog\DTOs\ListingFilters;
use App\Modules\Catalog\DTOs\ListingScope;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\Storefront\ListingFacets;
use App\Modules\Catalog\Services\Storefront\ListingQuery;
use App\Modules\Platform\Support\PageSeo;
use App\Modules\Platform\Support\SlugRedirects;
use App\Modules\Platform\Support\StructuredData;
use App\Modules\Search\Contracts\CatalogSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Shop, category, brand and collection pages: same engine, different scope. All state lives in the URL. */
class ListingController
{
    public function __construct(private readonly ListingQuery $query, private readonly ListingFacets $facets, private readonly CategoryTree $tree, private readonly PageSeo $seo, private readonly SlugRedirects $redirects) {}

    public function shop(Request $request): View
    {
        return $this->render($request, ListingScope::all(), __('catalog::storefront.shop'), null, [['label' => __('catalog::storefront.shop'), 'url' => null]], []);
    }

    public function search(Request $request, CatalogSearch $search): View
    {
        $locale = app()->getLocale();
        $q = $request->query('q');
        $raw = is_string($q) ? trim(mb_substr($q, 0, 100)) : ''; // q[]=… and other junk count as an empty search
        $result = $search->search($raw, $locale);
        $title = $raw === '' ? __('catalog::storefront.search') : __('catalog::storefront.search_results_for', ['query' => $raw]);

        // the first page of a real search is logged (text, language, count: nothing about the visitor)
        if ($raw !== '' && ! $request->query('page') && ! $request->boolean('sf')) {
            $search->logQuery($result->normalizedQuery, $locale, count($result->productIds));
        }

        $query = $request->query() + [];
        if (! isset($query['sort'])) {
            $query['sort'] = 'relevance';
        }

        return $this->render($request, ListingScope::ids($result->productIds), $title, null, [['label' => __('catalog::storefront.search'), 'url' => null]], [], null, $query, ['searchQuery' => $raw, 'correctedQuery' => $result->correctedQuery], [], true);
    }

    public function category(Request $request, string $slug): View
    {
        /** @var Category $category */
        $category = $this->bySlug(Category::class, 'category_translations', 'category_id', $slug);
        $category->load('translations');

        $path = $this->tree->pathIds($category);
        $byId = Category::query()->with('translations')->whereIn('id', $path)->get()->keyBy('id');
        $trail = [];
        foreach ($path as $id) {
            $c = $byId->get($id);
            if ($c !== null) {
                $trail[] = ['label' => (string) $c->translate('name'), 'url' => $id === $category->id ? null : url('/'.app()->getLocale().'/c/'.$c->translate('slug'))];
            }
        }

        $children = array_values(Category::query()->with('translations')->where('parent_id', $category->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Category $c) => ['name' => (string) $c->translate('name'), 'url' => url('/'.app()->getLocale().'/c/'.$c->translate('slug'))])->all());

        return $this->render($request, ListingScope::category($this->tree->subtreeIds($category)), (string) $category->translate('name'), $category->translate('description'), $this->withShop($trail), $children, $category->translate('seo_description'), null, [], $this->alternatesFor($category, 'c'));
    }

    public function brand(Request $request, string $slug): View
    {
        /** @var Brand $brand */
        $brand = $this->bySlug(Brand::class, 'brand_translations', 'brand_id', $slug)->load('translations');

        return $this->render($request, ListingScope::brand($brand->id), (string) $brand->translate('name'), $brand->translate('description'), $this->withShop([['label' => (string) $brand->translate('name'), 'url' => null]]), [], $brand->translate('seo_description'), null, [], $this->alternatesFor($brand, 'brands'));
    }

    public function collection(Request $request, string $slug): View
    {
        /** @var Collection $collection */
        $collection = $this->bySlug(Collection::class, 'collection_translations', 'collection_id', $slug)->load('translations');

        return $this->render($request, ListingScope::collection($collection->id), (string) $collection->translate('name'), $collection->translate('description'), $this->withShop([['label' => (string) $collection->translate('name'), 'url' => null]]), [], $collection->translate('seo_description'), null, [], $this->alternatesFor($collection, 'collections'));
    }

    /**
     * @param  list<array{label: string, url: string|null}>  $breadcrumb
     * @param  list<array{name: string, url: string}>  $children
     * @param  array<string, mixed>|null  $queryOverride
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $alternates
     */
    private function render(Request $request, ListingScope $scope, string $title, ?string $description, array $breadcrumb, array $children, ?string $metaDescription = null, ?array $queryOverride = null, array $extra = [], array $alternates = [], bool $search = false): View
    {
        $locale = app()->getLocale();
        $filters = ListingFilters::fromQuery($queryOverride ?? $request->query());
        $products = $search ? $this->query->paginate($scope, $filters, $locale) : $this->query->paginateCached($scope, $filters, $locale);

        // one canonical address per listing: filters and sorting never make new indexable pages
        $this->seo->canonical = $request->url().($filters->page > 1 ? '?page='.$filters->page : '');
        $this->seo->noindex = $search || $filters->isFiltered() || $filters->sort !== 'newest' || $products->isEmpty();
        $this->seo->describe($metaDescription ?? $description);
        if ($alternates !== []) {
            $this->seo->alternates($alternates);
        }
        $this->seo->jsonLd(StructuredData::breadcrumbs($breadcrumb, $this->seo->canonical));

        // a page past the end shows the last page's worth of nothing: send people back rather than an empty grid
        return view('catalog::storefront.listing', [
            'title' => $title, 'description' => $description, 'metaDescription' => $metaDescription ?? $description,
            'breadcrumb' => $breadcrumb, 'children' => $children,
            'products' => $products, 'filters' => $filters, 'facets' => $this->facets->for($scope, $locale),
            'basePath' => $request->url(),
        ] + $extra);
    }

    /**
     * @param  list<array{label: string, url: string|null}>  $trail
     * @return list<array{label: string, url: string|null}>
     */
    private function withShop(array $trail): array
    {
        return [['label' => __('catalog::storefront.shop'), 'url' => url('/'.app()->getLocale().'/shop')], ...$trail];
    }

    /** @var array<class-string<Model>, array{0: string, 1: string}> model => [redirect type, URL segment] */
    private const ROUTES = [Category::class => ['category', 'c'], Brand::class => ['brand', 'brands'], Collection::class => ['collection', 'collections']];

    /** @param  class-string<Model>  $model */
    private function bySlug(string $model, string $translations, string $fk, string $slug): Model
    {
        $locale = app()->getLocale();
        $id = DB::table($translations)->where('locale', $locale)->where('slug', $slug)->value($fk);

        if ($id === null) { // a retired slug leads to the current address
            [$type, $segment] = self::ROUTES[$model];
            $moved = $this->redirects->resolve($type, $locale, $slug);
            $current = $moved === null ? null : DB::table($translations)->where($fk, $moved)->where('locale', $locale)->value('slug');
            if ($current !== null) {
                throw new HttpResponseException(redirect()->to(url("/{$locale}/{$segment}/".rawurlencode((string) $current)), 301));
            }
        }

        /** @var Model|null $entity */
        $entity = $id === null ? null : $model::query()->whereKey($id)->where('is_active', true)->first();

        return $entity ?? abort(404);
    }

    /** @return array<string, string> */
    private function alternatesFor(Category|Brand|Collection $entity, string $segment): array
    {
        $out = [];
        foreach ($entity->translations as $t) {
            $locale = (string) $t->getAttribute('locale');
            $out[$locale] = url("/{$locale}/{$segment}/".rawurlencode((string) $t->getAttribute('slug')));
        }

        return $out;
    }
}
