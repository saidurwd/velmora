<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\DeleteVariant;
use App\Modules\Catalog\Actions\GenerateVariants;
use App\Modules\Catalog\Actions\SaveVariant;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Http\Requests\VariantRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VariantController
{
    public function store(VariantRequest $request, Product $product, SaveVariant $save): RedirectResponse
    {
        return $this->run($product, fn () => $save->handle($this->actor($request), $product, null, $request->variant()));
    }

    public function update(VariantRequest $request, Product $product, ProductVariant $variant, SaveVariant $save): RedirectResponse
    {
        $this->owned($product, $variant);

        return $this->run($product, fn () => $save->handle($this->actor($request), $product, $variant, $request->variant()));
    }

    public function destroy(Request $request, Product $product, ProductVariant $variant, DeleteVariant $delete): RedirectResponse
    {
        $this->owned($product, $variant);

        return $this->run($product, fn () => $delete->handle($this->actor($request), $variant));
    }

    public function generate(Request $request, Product $product, GenerateVariants $generate): RedirectResponse
    {
        $data = $request->validate([
            'sku_base' => ['required', 'string', 'max:40'],
            'selection' => ['required', 'array', 'min:1', 'max:5'],
            'selection.*' => ['array', 'min:1', 'max:50'],
            'selection.*.*' => ['integer'],
            'track_inventory' => ['sometimes', 'boolean'],
        ]);

        $selection = [];
        foreach ($data['selection'] as $attributeId => $valueIds) {
            $selection[(int) $attributeId] = array_values(array_map('intval', $valueIds));
        }

        return $this->run($product, fn () => $generate->handle($this->actor($request), $product, $selection, (string) $data['sku_base'], new VariantData('', trackInventory: ! empty($data['track_inventory']))));
    }

    private function run(Product $product, callable $work): RedirectResponse
    {
        try {
            $work();
        } catch (CatalogException $e) {
            return redirect()->route('admin.catalog.products.edit', $product)->withInput()->withErrors(['variant' => $e->getMessage()]);
        }

        return redirect()->route('admin.catalog.products.edit', $product)->with('status', __('catalog::admin.saved'));
    }

    private function owned(Product $product, ProductVariant $variant): void
    {
        abort_unless($variant->product_id === $product->id, 404);
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
