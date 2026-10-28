<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Contracts\TaxRates;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductController
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:physical,digital,service'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'category' => ['nullable', 'string', 'size:26'],
            'brand' => ['nullable', 'string', 'size:26'],
        ]);

        $products = Product::query()
            ->with(['translations', 'brand.translations', 'categories.translations'])
            ->withCount('variants')
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->whereHas('translations', fn ($t) => $t->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
                ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', addcslashes(strtoupper($term), '%_\\').'%'))))
            ->when($filters['type'] ?? null, fn ($q, string $v) => $q->where('product_type', $v))
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['category'] ?? null, fn ($q, string $v) => $q->whereHas('categories', fn ($c) => $c->where('categories.public_id', $v)))
            ->when($filters['brand'] ?? null, fn ($q, string $v) => $q->whereHas('brand', fn ($b) => $b->where('public_id', $v)))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('catalog::admin.products.index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::query()->with('translations')->orderBy('id')->get(),
            'brands' => Brand::query()->with('translations')->orderBy('id')->get(),
        ]);
    }

    public function create(TaxRates $tax): View
    {
        return view('catalog::admin.products.form', $this->formData(null, $tax) + ['optionAttributes' => collect()]);
    }

    public function store(ProductRequest $request, SaveProduct $save, TaxRates $tax): RedirectResponse
    {
        $actor = $this->actor($request);

        try {
            $product = $save->handle($actor, null, $this->data($request, $actor, withVariant: true));
        } catch (CatalogException $e) {
            return back()->withInput()->withErrors(['sku' => $e->getMessage()]);
        }

        return redirect()->route('admin.catalog.products.edit', $product)->with('status', __('catalog::admin.saved'));
    }

    public function edit(Product $product, TaxRates $tax): View
    {
        $product->load(['translations', 'categories', 'tags', 'brand', 'variants.optionValues.translations', 'variants.optionValues.attribute.translations']);

        return view('catalog::admin.products.form', $this->formData($product, $tax) + [
            'optionAttributes' => Attribute::query()->where('kind', 'option')->with(['translations', 'values.translations'])->orderBy('code')->get(),
        ]);
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        try {
            $save->handle($this->actor($request), $product, $this->data($request, $this->actor($request), withVariant: false));
        } catch (CatalogException $e) {
            return back()->withInput()->withErrors(['product_type' => $e->getMessage()]);
        }

        return redirect()->route('admin.catalog.products.edit', $product)->with('status', __('catalog::admin.saved'));
    }

    public function transition(Request $request, Product $product, TransitionProduct $transition, Clock $clock): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:publish,schedule,unpublish,archive'], 'publish_at' => ['required_if:action,schedule', 'nullable', 'date']]);
        $actor = $this->actor($request);

        try {
            match ($data['action']) {
                'publish' => $transition->publish($actor, $product),
                'schedule' => $transition->schedule($actor, $product, CarbonImmutable::parse((string) $data['publish_at'], (string) config('app.display_timezone'))->utc()),
                'unpublish' => $transition->unpublish($actor, $product),
                'archive' => $transition->archive($actor, $product),
                default => abort(422),
            };
        } catch (CatalogException $e) {
            return back()->withErrors(['transition' => $e->getMessage()]);
        }

        return back()->with('status', __('catalog::admin.saved'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        abort_if($product->status === ProductStatus::Published, 422, __('catalog::admin.products.unpublish_first'));
        $product->delete(); // soft delete

        return redirect()->route('admin.catalog.products.index')->with('status', __('catalog::admin.saved'));
    }

    private function data(ProductRequest $request, Actor $actor, bool $withVariant): ProductData
    {
        $categoryIds = Category::query()->whereIn('public_id', (array) $request->validated('categories', []))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $primary = $request->validated('primary_category') ? Category::query()->where('public_id', $request->validated('primary_category'))->value('id') : null;

        return new ProductData(
            $request->type(),
            $request->translations(),
            $request->validated('brand') ? Brand::query()->where('public_id', $request->validated('brand'))->value('id') : null,
            (string) $request->validated('tax_class_code'),
            array_values($categoryIds),
            $primary === null ? null : (int) $primary,
            array_values(Tag::query()->whereIn('public_id', (array) $request->validated('tags', []))->pluck('id')->map(fn ($v) => (int) $v)->all()),
            $withVariant ? $request->variant() : null,
            $actor->id,
        );
    }

    /** @return array<string, mixed> */
    private function formData(?Product $product, TaxRates $tax): array
    {
        return [
            'product' => $product,
            'types' => ProductType::cases(),
            'taxClasses' => $tax->classCodes(),
            'brands' => Brand::query()->with('translations')->orderBy('id')->get(),
            'categories' => Category::query()->with('translations')->orderBy('id')->get(),
            'tags' => Tag::query()->with('translations')->orderBy('id')->get(),
        ];
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
