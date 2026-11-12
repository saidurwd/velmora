<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Modules\Catalog\Http\Resources\CatalogTypes;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\Storefront\SlugLookup;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;

/** Categories, brands and collections: the ways the shop is organised. Products in them come from `GET /products?filter[category]=…` (or `filter[collection]`, `filter[brand]`). */
class CategoryController
{
    /**
     * Category tree
     *
     * Active categories only, as a tree in the shop's own order (three levels deep at most in practice). A category under a hidden one is hidden too.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<'.CatalogTypes::TREE_NODE.'>}')]
    public function index(): JsonResponse
    {
        $all = Category::query()->with('translations')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $byParent = $all->groupBy(fn (Category $c) => $c->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent): array {
            return array_values(($byParent->get($parentId) ?? collect())->map(fn (Category $c): array => [
                'id' => $c->public_id, 'name' => (string) $c->translate('name'), 'slug' => (string) $c->translate('slug'), 'children' => $build($c->id),
            ])->all());
        };

        return response()->json(['data' => $build(0)]);
    }

    /**
     * One category
     *
     * With its trail from the top (`ancestors`) and its direct sub-categories.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{id: string, name: string, slug: string, description: string|null, ancestors: list<'.CatalogTypes::NODE.'>, children: list<'.CatalogTypes::NODE.'>}}')]
    public function show(string $slug, SlugLookup $slugs, CategoryTree $tree): JsonResponse
    {
        $category = $slugs->find(Category::class, $slug, app()->getLocale()) ?? throw new ApiProblem(ErrorCode::NotFound);
        $node = fn (Category $c): array => ['id' => $c->public_id, 'name' => (string) $c->translate('name'), 'slug' => (string) $c->translate('slug')];

        $trail = Category::query()->with('translations')->whereIn('id', $tree->pathIds($category))->get()->keyBy('id');
        $ancestors = [];
        foreach ($tree->pathIds($category) as $id) {
            $c = $trail->get($id);
            if ($c !== null && $c->id !== $category->id) {
                $ancestors[] = $node($c);
            }
        }
        $children = Category::query()->with('translations')->where('parent_id', $category->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map($node)->all();

        return response()->json(['data' => $node($category) + ['description' => $category->translate('description'), 'ancestors' => $ancestors, 'children' => array_values($children)]]);
    }

    /**
     * Brands
     *
     * Active brands, by name.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<'.CatalogTypes::NODE.'>}')]
    public function brands(): JsonResponse
    {
        $brands = Brand::query()->with('translations')->where('is_active', true)->get()
            ->map(fn (Brand $b): array => ['id' => $b->public_id, 'name' => (string) $b->translate('name'), 'slug' => (string) $b->translate('slug')])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return response()->json(['data' => $brands]);
    }

    /**
     * One collection
     *
     * A hand-picked set of products (for example for a festival). The products themselves: `GET /products?filter[collection]=slug`.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{id: string, name: string, slug: string, description: string|null}}')]
    public function collection(string $slug, SlugLookup $slugs): JsonResponse
    {
        $c = $slugs->find(Collection::class, $slug, app()->getLocale()) ?? throw new ApiProblem(ErrorCode::NotFound);

        return response()->json(['data' => ['id' => $c->public_id, 'name' => (string) $c->translate('name'), 'slug' => (string) $c->translate('slug'), 'description' => $c->translate('description')]]);
    }
}
