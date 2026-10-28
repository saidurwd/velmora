<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Pricing\Contracts\PriceCatalog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `catalog_listing` (the storefront read model) in step with the catalog. Called directly after catalog edits and by
 * the once-a-minute reconcile job for price and stock changes, so a price or stock change shows within about a minute.
 */
final class ListingProjection
{
    /** Tag slug that marks curated "popular" products until real sales data exists (Phase 5). */
    public const POPULAR_TAG = 'bestseller';

    public function __construct(private readonly PriceCatalog $prices, private readonly Availability $availability, private readonly Clock $clock) {}

    /** @param  list<int>  $productIds */
    public function refreshMany(array $productIds): void
    {
        foreach (array_unique($productIds) as $id) {
            $this->refresh($id);
        }
    }

    public function refresh(int $productId): void
    {
        $product = Product::query()->find($productId);

        if ($product === null || $product->status !== ProductStatus::Published || ($product->publish_at !== null && $product->publish_at->greaterThan($this->clock->now()))) {
            $this->remove($productId);

            return;
        }

        $variants = DB::table('product_variants')->where('product_id', $productId)->whereNull('deleted_at')->where('status', 'active')->get(['id', 'track_inventory']);
        $quotes = [];
        foreach ($variants as $variant) {
            try {
                $quotes[(int) $variant->id] = $this->prices->quote((int) $variant->id, $product->tax_class_code);
            } catch (DomainException) {
                // an unpriced variant is not sellable: ignored here (publishing already requires prices)
            }
        }

        if ($quotes === []) {
            $this->remove($productId);

            return;
        }

        $sorted = array_values($quotes);
        usort($sorted, fn ($a, $b) => $a->price->amountMinor <=> $b->price->amountMinor);
        $cheapest = $sorted[0];
        $stock = $this->availability->forVariants(array_keys($quotes));
        $inStock = $variants->contains(fn ($v) => isset($quotes[(int) $v->id]) && (! $v->track_inventory || ($stock[(int) $v->id] ?? 0) > 0));

        $media = DB::table('product_media')->where('product_id', $productId)->whereNull('deleted_at')->orderByDesc('is_primary')->orderBy('sort_order')->first();
        $popular = DB::table('product_tags')->join('tag_translations', 'tag_translations.tag_id', '=', 'product_tags.tag_id')
            ->where('product_tags.product_id', $productId)->where('tag_translations.slug', self::POPULAR_TAG)->exists();

        DB::table('catalog_listing')->upsert([[
            'product_id' => $productId,
            'product_type' => $product->product_type->value,
            'brand_id' => $product->brand_id,
            'primary_category_id' => DB::table('product_categories')->where('product_id', $productId)->orderByDesc('is_primary')->value('category_id'),
            'min_price_minor' => min(array_map(fn ($q) => $q->price->amountMinor, $quotes)),
            'max_price_minor' => max(array_map(fn ($q) => $q->price->amountMinor, $quotes)),
            'compare_at_minor' => $cheapest->onSale ? $cheapest->regular->amountMinor : $cheapest->compareAt?->amountMinor,
            'on_sale' => $cheapest->onSale,
            'in_stock' => $inStock,
            'popularity' => $popular ? 100 : 0,
            'published_at' => ($product->published_at ?? $this->clock->now())->utc()->format('Y-m-d H:i:s.u'),
            'image_disk' => $media?->disk,
            'image_renditions' => $media?->renditions,
            'image_width' => $media?->width,
            'image_height' => $media?->height,
            'refreshed_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]], ['product_id'], ['product_type', 'brand_id', 'primary_category_id', 'min_price_minor', 'max_price_minor', 'compare_at_minor', 'on_sale', 'in_stock', 'popularity', 'published_at', 'image_disk', 'image_renditions', 'image_width', 'image_height', 'refreshed_at']);
    }

    public function remove(int $productId): void
    {
        DB::table('catalog_listing')->where('product_id', $productId)->delete();
    }

    /** Rebuilds everything (deploys, imports, repairs). */
    public function rebuild(): int
    {
        $ids = array_values(array_map('intval', Product::query()->where('status', ProductStatus::Published->value)->pluck('id')->all()));
        DB::table('catalog_listing')->whereNotIn('product_id', $ids)->delete();
        $this->refreshMany($ids);

        return count($ids);
    }
}
