<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\DeleteCategory;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Http\Requests\TranslatedEntityRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CategoryController
{
    public function index(CategoryTree $tree): View
    {
        $categories = Category::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();

        return view('catalog::admin.categories.index', ['rows' => $this->flatten($categories, $tree)]);
    }

    public function create(): View
    {
        return view('catalog::admin.categories.form', ['category' => null, 'parents' => $this->parentOptions(null)]);
    }

    public function store(TranslatedEntityRequest $request, SaveCategory $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(Category $category): View
    {
        return view('catalog::admin.categories.form', ['category' => $category->load('translations'), 'parents' => $this->parentOptions($category)]);
    }

    public function update(TranslatedEntityRequest $request, Category $category, SaveCategory $save): RedirectResponse
    {
        return $this->persist($request, $save, $category);
    }

    public function destroy(Request $request, Category $category, DeleteCategory $delete): RedirectResponse
    {
        try {
            $delete->handle($this->actor($request), $category);
        } catch (CatalogException $e) {
            return back()->withErrors(['category' => $e->getMessage()]);
        }

        return redirect()->route('admin.catalog.categories.index')->with('status', __('catalog::admin.saved'));
    }

    private function persist(TranslatedEntityRequest $request, SaveCategory $save, ?Category $category): RedirectResponse
    {
        $parent = $request->validated('parent') ? Category::query()->where('public_id', $request->validated('parent'))->first() : null;

        try {
            $save->handle($this->actor($request), $category, $parent, $request->translations(), (int) $request->validated('sort_order', 0), $request->boolean('is_active'));
        } catch (CatalogException $e) {
            return back()->withInput()->withErrors(['translations' => $e->getMessage()]);
        }

        return redirect()->route('admin.catalog.categories.index')->with('status', __('catalog::admin.saved'));
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return list<array{category: Category, depth: int}>
     */
    private function flatten($categories, CategoryTree $tree): array
    {
        $byParent = $categories->groupBy(fn (Category $c) => $c->parent_id ?? 0);
        $rows = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent): void {
            foreach ($byParent->get($parentId, []) as $category) {
                $rows[] = ['category' => $category, 'depth' => $depth];
                $walk($category->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $rows;
    }

    /** @return list<array{id: string, label: string}> every category except the one being edited and its descendants */
    private function parentOptions(?Category $editing): array
    {
        $tree = app(CategoryTree::class);
        $excluded = $editing === null ? [] : $tree->subtreeIds($editing);
        $out = [];

        foreach ($this->flatten(Category::query()->with('translations')->orderBy('sort_order')->get(), $tree) as $row) {
            if (! in_array($row['category']->id, $excluded, true) && $row['depth'] + 1 < CategoryTree::MAX_DEPTH) {
                $out[] = ['id' => $row['category']->public_id, 'label' => str_repeat('— ', $row['depth']).$row['category']->translate('name')];
            }
        }

        return $out;
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
