<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Events\ProductListingChanged;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\AttributeValue;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\SkuGenerator;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class SaveVariant
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Actor $actor, Product $product, ?ProductVariant $variant, VariantData $data): ProductVariant
    {
        $sku = strtoupper(trim($data->sku));

        if (! SkuGenerator::isValid($sku)) {
            throw CatalogException::skuInvalid($data->sku);
        }

        if ($data->trackInventory && ! $product->product_type->tracksStock()) {
            throw CatalogException::noStockForType($product->product_type->value);
        }

        return Transactions::run(function () use ($actor, $product, $variant, $data, $sku): ProductVariant {
            if (ProductVariant::withTrashed()->where('sku', $sku)->when($variant, fn ($q) => $q->whereKeyNot($variant?->id))->exists()) {
                throw CatalogException::skuTaken($sku);
            }

            $values = $this->resolveOptionValues($product, $data->optionValueIds, $variant);
            $signature = collect($values)->pluck('id')->sort()->implode('-');
            $isNew = $variant === null;
            $before = $isNew ? [] : $variant->only(['sku', 'status', 'is_default', 'track_inventory']);

            // serialise default/signature decisions for this product
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            if ($data->isDefault) {
                ProductVariant::query()->where('product_id', $product->id)->where('is_default', true)->when($variant, fn ($q) => $q->whereKeyNot($variant?->id))->update(['is_default' => false]);
            }

            $variant ??= new ProductVariant;
            try {
                $variant->forceFill([
                    'product_id' => $product->id,
                    'sku' => $sku,
                    'barcode' => $data->barcode,
                    'is_default' => $data->isDefault,
                    'track_inventory' => $data->trackInventory,
                    'option_signature' => $signature,
                    'weight_g' => $data->weightG,
                    'length_mm' => $data->lengthMm,
                    'width_mm' => $data->widthMm,
                    'height_mm' => $data->heightMm,
                    'min_qty' => $data->minQty,
                    'max_qty' => $data->maxQty,
                    'qty_step' => $data->qtyStep,
                    'status' => $data->status,
                    'sort_order' => $data->sortOrder,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                // sku is pre-checked, so what is left is the option combination (or a second default): say so in plain words
                throw CatalogException::invalidOption('a variant with these options already exists (a product has one variant per size/colour combination)');
            }

            DB::table('variant_option_values')->where('variant_id', $variant->id)->delete();
            foreach ($values as $value) {
                DB::table('variant_option_values')->insert(['variant_id' => $variant->id, 'attribute_value_id' => $value->id, 'attribute_id' => $value->attribute_id]);
                DB::table('product_options')->insertOrIgnore(['product_id' => $product->id, 'attribute_id' => $value->attribute_id, 'position' => 0]);
            }

            $this->audit->record($isNew ? 'variant.created' : 'variant.updated', $variant, $before, ['sku' => $sku, 'status' => $data->status, 'is_default' => $data->isDefault, 'options' => $signature], $actor);
            ProductListingChanged::dispatch($product->id);

            return $variant;
        });
    }

    /**
     * @param  list<int>  $ids
     * @return list<AttributeValue>
     */
    private function resolveOptionValues(Product $product, array $ids, ?ProductVariant $editing): array
    {
        $values = AttributeValue::query()->with('attribute')->whereIn('id', $ids)->get();

        if ($values->count() !== count(array_unique($ids))) {
            throw CatalogException::invalidOption('unknown option value');
        }

        if ($values->pluck('attribute_id')->duplicates()->isNotEmpty()) {
            throw CatalogException::invalidOption('only one value per option (e.g. one size)');
        }

        foreach ($values as $value) {
            if ($value->attribute?->kind !== 'option') {
                throw CatalogException::invalidOption("[{$value->attribute?->code}] is a specification, not an option");
            }
        }

        // every live variant of a product must use the same set of option attributes (simple product = none)
        $wanted = $values->pluck('attribute_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $others = DB::table('product_variants')->where('product_id', $product->id)->whereNull('deleted_at')
            ->when($editing, fn ($q) => $q->where('id', '!=', $editing?->id))->pluck('id');

        $sets = DB::table('variant_option_values')->whereIn('variant_id', $others)->get()->groupBy('variant_id')
            ->map(fn ($rows) => $rows->pluck('attribute_id')->map(fn ($v) => (int) $v)->sort()->values()->all());

        foreach ($others as $otherId) {
            if (($sets[$otherId] ?? []) !== $wanted) {
                throw CatalogException::invalidOption('all variants of a product must use the same options');
            }
        }

        return array_values($values->all());
    }
}
