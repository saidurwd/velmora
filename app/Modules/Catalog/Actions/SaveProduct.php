<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Services\TranslationWriter;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

final class SaveProduct
{
    public function __construct(
        private readonly TranslationWriter $translations,
        private readonly SaveVariant $saveVariant,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Actor $actor, ?Product $product, ProductData $data): Product
    {
        if ($data->defaultVariant?->trackInventory && ! $data->type->tracksStock()) {
            throw CatalogException::noStockForType($data->type->value);
        }

        return Transactions::run(function () use ($actor, $product, $data): Product {
            $isNew = $product === null;

            if (! $isNew && $product->product_type !== $data->type && $product->status !== ProductStatus::Draft) {
                throw CatalogException::typeLocked();
            }

            $before = $isNew ? [] : ['type' => $product->product_type->value, 'brand_id' => $product->brand_id, 'tax_class_code' => $product->tax_class_code];

            $product ??= new Product;
            $product->forceFill([
                'product_type' => $data->type,
                'brand_id' => $data->brandId,
                'tax_class_code' => $data->taxClassCode,
                'created_by' => $isNew ? $data->createdBy : $product->created_by,
            ])->save();

            $this->translations->sync($product, ProductTranslation::class, 'product_id', $data->translations);
            $this->syncLinks($product, $data);

            if ($data->defaultVariant !== null) {
                $existing = $product->variants()->where('is_default', true)->first();
                $this->saveVariant->handle($actor, $product, $existing, $this->asDefault($data->defaultVariant));
            }

            $this->audit->record($isNew ? 'product.created' : 'product.updated', $product, $before, ['type' => $data->type->value, 'brand_id' => $data->brandId, 'categories' => $data->categoryIds], $actor);

            return $product->refresh();
        });
    }

    private function syncLinks(Product $product, ProductData $data): void
    {
        $primary = $data->primaryCategoryId ?? ($data->categoryIds[0] ?? null);

        DB::table('product_categories')->where('product_id', $product->id)->delete();
        foreach (array_unique($data->categoryIds) as $categoryId) {
            DB::table('product_categories')->insert(['product_id' => $product->id, 'category_id' => $categoryId, 'is_primary' => $categoryId === $primary]);
        }

        DB::table('product_tags')->where('product_id', $product->id)->delete();
        foreach (array_unique($data->tagIds) as $tagId) {
            DB::table('product_tags')->insert(['product_id' => $product->id, 'tag_id' => $tagId]);
        }
    }

    private function asDefault(VariantData $v): VariantData
    {
        return new VariantData($v->sku, $v->barcode, $v->trackInventory, $v->weightG, $v->lengthMm, $v->widthMm, $v->heightMm, $v->minQty, $v->maxQty, $v->qtyStep, $v->status, $v->sortOrder, true, $v->optionValueIds);
    }
}
