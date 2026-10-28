<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\Contracts\SearchableProducts;
use App\Modules\Catalog\DTOs\SearchDocument;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

final class DatabaseSearchableProducts implements SearchableProducts
{
    public function __construct(private readonly Clock $clock) {}

    public function document(int $productId): ?SearchDocument
    {
        $product = Product::query()->whereKey($productId)->first();

        if ($product === null || $product->status !== ProductStatus::Published || ($product->publish_at !== null && $product->publish_at->greaterThan($this->clock->now()))) {
            return null;
        }

        $names = [];
        $slugs = [];
        $texts = [];
        foreach (ProductTranslation::query()->where('product_id', $productId)->get() as $t) {
            $names[$t->locale] = (string) $t->name;
            $slugs[$t->locale] = (string) $t->slug;
            $texts[$t->locale] = trim(((string) ($t->short_description ?? '')).' '.((string) ($t->description ?? '')));
        }

        $related = [];
        $add = function (string $table, string $fk, int $id) use (&$related): void {
            foreach (DB::table($table)->where($fk, $id)->get(['locale', 'name']) as $r) {
                $related[(string) $r->locale][] = (string) $r->name;
            }
        };

        if ($product->brand_id !== null) {
            $add('brand_translations', 'brand_id', (int) $product->brand_id);
        }
        foreach (DB::table('product_categories')->where('product_id', $productId)->pluck('category_id') as $id) {
            $add('category_translations', 'category_id', (int) $id);
        }
        foreach (DB::table('product_tags')->where('product_id', $productId)->pluck('tag_id') as $id) {
            $add('tag_translations', 'tag_id', (int) $id);
        }

        $skus = array_values(array_map('strval', DB::table('product_variants')->where('product_id', $productId)->whereNull('deleted_at')->pluck('sku')->all()));

        return new SearchDocument($productId, $names, $slugs, $texts, $related, $skus);
    }

    public function liveProductIds(): array
    {
        return array_values(array_map('intval', Product::query()->where('status', ProductStatus::Published->value)->pluck('id')->all()));
    }
}
