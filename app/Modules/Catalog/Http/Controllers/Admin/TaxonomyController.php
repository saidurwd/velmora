<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\SaveTaxonomyEntity;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Http\Requests\TranslatedEntityRequest;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\StorefrontCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** One controller for the three flat taxonomies (brands, tags, collections); `type` comes from the route. */
class TaxonomyController
{
    private const TYPES = ['brands' => Brand::class, 'tags' => Tag::class, 'collections' => Collection::class];

    public function index(string $type): View
    {
        $model = $this->model($type);

        return view('catalog::admin.taxonomy.index', ['type' => $type, 'items' => $model::query()->with('translations')->orderBy('sort_order')->orderBy('id')->paginate(50)]);
    }

    public function create(string $type): View
    {
        $this->model($type);

        return view('catalog::admin.taxonomy.form', ['type' => $type, 'item' => null]);
    }

    public function store(TranslatedEntityRequest $request, string $type, SaveTaxonomyEntity $save): RedirectResponse
    {
        return $this->persist($request, $type, $save, null);
    }

    public function edit(string $type, string $item): View
    {
        $model = $this->model($type);

        return view('catalog::admin.taxonomy.form', ['type' => $type, 'item' => $model::query()->with('translations')->where('public_id', $item)->firstOrFail()]);
    }

    public function update(TranslatedEntityRequest $request, string $type, string $item, SaveTaxonomyEntity $save): RedirectResponse
    {
        $model = $this->model($type);

        return $this->persist($request, $type, $save, $model::query()->where('public_id', $item)->firstOrFail());
    }

    public function destroy(string $type, string $item): RedirectResponse
    {
        $model = $this->model($type);
        $model::query()->where('public_id', $item)->firstOrFail()->delete(); // soft delete
        app(StorefrontCache::class)->bump();

        return redirect()->route('admin.catalog.taxonomy.index', $type)->with('status', __('catalog::admin.saved'));
    }

    private function persist(TranslatedEntityRequest $request, string $type, SaveTaxonomyEntity $save, Brand|Tag|Collection|null $item): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        try {
            $save->handle(new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id), $this->model($type), $item, $request->translations(), (int) $request->validated('sort_order', 0), $request->boolean('is_active'));
        } catch (CatalogException $e) {
            return back()->withInput()->withErrors(['translations' => $e->getMessage()]);
        }

        return redirect()->route('admin.catalog.taxonomy.index', $type)->with('status', __('catalog::admin.saved'));
    }

    /** @return class-string<Brand|Tag|Collection> */
    private function model(string $type): string
    {
        return self::TYPES[$type] ?? abort(404);
    }
}
