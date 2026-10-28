<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\AttributeValue;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\SkuGenerator;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** Creates one variant per combination of the chosen option values (size × colour ...), skipping combinations that exist. */
final class GenerateVariants
{
    public const MAX = 250;

    public function __construct(private readonly SaveVariant $saveVariant, private readonly SkuGenerator $skus) {}

    /**
     * @param  array<int, list<int>>  $selection  option attribute id => chosen value ids
     * @return list<ProductVariant> the variants that were created
     */
    public function handle(Actor $actor, Product $product, array $selection, string $skuBase, VariantData $template): array
    {
        $combos = $this->cartesian(array_values($selection));

        if (count($combos) > self::MAX) {
            throw CatalogException::tooManyVariants(self::MAX);
        }

        return Transactions::run(function () use ($actor, $product, $combos, $skuBase, $template): array {
            $existing = DB::table('product_variants')->where('product_id', $product->id)->whereNull('deleted_at')->pluck('option_signature')->all();
            $hasDefault = DB::table('product_variants')->where('product_id', $product->id)->whereNull('deleted_at')->where('is_default', true)->exists();
            $created = [];
            $order = (int) DB::table('product_variants')->where('product_id', $product->id)->max('sort_order');

            foreach ($combos as $valueIds) {
                $signature = collect($valueIds)->sort()->implode('-');
                if (in_array($signature, $existing, true)) {
                    continue;
                }

                $codes = array_values(array_map('strval', AttributeValue::query()->whereIn('id', $valueIds)->orderBy('attribute_id')->pluck('code')->all()));
                $created[] = $this->saveVariant->handle($actor, $product, null, new VariantData(
                    $this->skus->suggest($skuBase, $codes),
                    null, $template->trackInventory, $template->weightG, $template->lengthMm, $template->widthMm, $template->heightMm,
                    $template->minQty, $template->maxQty, $template->qtyStep, $template->status, ++$order,
                    ! $hasDefault && $created === [], $valueIds,
                ));
            }

            return $created;
        });
    }

    /**
     * @param  list<list<int>>  $lists
     * @return list<list<int>>
     */
    private function cartesian(array $lists): array
    {
        $result = [[]];

        foreach ($lists as $values) {
            $next = [];
            foreach ($result as $prefix) {
                foreach (array_unique($values) as $value) {
                    $next[] = [...$prefix, $value];
                }
            }
            $result = $next;
        }

        return $result === [[]] ? [] : $result;
    }
}
