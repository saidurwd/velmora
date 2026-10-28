<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\DTOs\Storefront\ProductPage;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\Media\MediaUrls;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\SlugRedirects;
use App\Modules\Pricing\Contracts\PriceCatalog;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Assembles the product page for a live product and the variant a visitor picked (or the best match). */
final class ProductPageBuilder
{
    public const LOW_STOCK = 5;

    public function __construct(
        private readonly PriceCatalog $prices,
        private readonly Availability $availability,
        private readonly CategoryTree $tree,
        private readonly MediaUrls $media,
        private readonly Clock $clock,
        private readonly SlugRedirects $redirects,
    ) {}

    /**
     * Finds a live product by a slug in the visitor's language, falling back to a slug in any language.
     *
     * @return array{product: Product, canonicalSlug: string}|null
     */
    public function find(string $slug, string $locale): ?array
    {
        $default = (string) config('app.default_locale');
        $id = DB::table('product_translations')->where('locale', $locale)->where('slug', $slug)->value('product_id')
            ?? DB::table('product_translations')->where('slug', $slug)->orderByRaw('locale = ? DESC', [$default])->value('product_id')
            ?? $this->redirects->resolve('product', $locale, $slug); // a retired slug leads to the product's current address
        $product = $id === null ? null : Product::query()->with(['translations', 'brand.translations'])->whereKey($id)->first();

        if ($product === null || $product->status !== ProductStatus::Published || ($product->publish_at !== null && $product->publish_at->greaterThan($this->clock->now()))) {
            return null;
        }

        $canonical = $product->translate('slug', $locale) ?? $slug;

        return ['product' => $product, 'canonicalSlug' => (string) $canonical];
    }

    /**
     * The same product's address in every language it exists in (hreflang, language switcher).
     *
     * @return array<string, string>
     */
    public function alternates(Product $product): array
    {
        $out = [];
        foreach (DB::table('product_translations')->where('product_id', $product->id)->get(['locale', 'slug']) as $t) {
            $out[(string) $t->locale] = url("/{$t->locale}/p/".rawurlencode((string) $t->slug));
        }

        return $out;
    }

    /** @param  array<string, string>  $requested  attribute code => value code */
    public function build(Product $product, array $requested, string $locale): ?ProductPage
    {
        $variants = $product->variants()->where('status', 'active')->with(['optionValues.translations', 'optionValues.attribute.translations'])->get();
        if ($variants->isEmpty()) {
            return null;
        }

        // one query tells which variants have a price; only the chosen variant is quoted (price, sale, tax) below
        $priced = $this->prices->regularPrices(array_values($variants->pluck('id')->map(fn ($v) => (int) $v)->all()));
        $variants = $variants->filter(fn (ProductVariant $v) => isset($priced[$v->id]))->values(); // unpriced variants cannot be sold
        if ($variants->isEmpty()) {
            return null;
        }

        $stock = $this->availability->forVariants(array_values($variants->pluck('id')->map(fn ($v) => (int) $v)->all()));
        $inStock = fn (ProductVariant $v): bool => ! $v->track_inventory || ($stock[$v->id] ?? 0) > 0;
        $codes = fn (ProductVariant $v): array => $v->optionValues->mapWithKeys(fn ($ov) => [(string) $ov->attribute?->code => $ov->code])->all();

        $best = fn (array $want) => $variants->sortByDesc(fn (ProductVariant $v) => [
            count(array_intersect_assoc($want, $codes($v))), (int) $inStock($v), (int) $v->is_default,
        ])->first();

        /** @var ProductVariant $selected */
        $selected = $best($requested);
        $selectedCodes = $codes($selected);
        try {
            $quote = $this->prices->quote($selected->id, $product->tax_class_code);
        } catch (DomainException) {
            return null;
        }

        $attributes = $variants->flatMap(fn (ProductVariant $v) => $v->optionValues)->map(fn ($ov) => $ov->attribute)->filter()->unique('id')->values();
        $options = array_values($attributes->map(function ($attribute) use ($variants, $selectedCodes, $best, $inStock, $locale): array {
            $values = $variants->flatMap(fn (ProductVariant $v) => $v->optionValues)->where('attribute_id', $attribute->id)->unique('id')->sortBy('sort_order')->values();

            return [
                'code' => $attribute->code,
                'name' => $attribute->label($locale),
                'values' => array_values($values->map(function ($value) use ($attribute, $selectedCodes, $best, $inStock, $locale): array {
                    $target = $best([...$selectedCodes, $attribute->code => $value->code]);

                    return ['code' => $value->code, 'label' => $value->label($locale), 'selected' => ($selectedCodes[$attribute->code] ?? null) === $value->code, 'inStock' => $target !== null && $inStock($target)];
                })->all()),
            ];
        })->all());

        $t = $product->translations->firstWhere('locale', $locale) ?? $product->translations->firstWhere('locale', (string) config('app.default_locale'));
        $category = DB::table('product_categories')->where('product_id', $product->id)->orderByDesc('is_primary')->value('category_id');

        return new ProductPage(
            $product->public_id, (string) $product->translate('name', $locale), (string) ($t->slug ?? ''), $product->product_type->value,
            $product->translate('short_description', $locale), $product->translate('description', $locale), $t->seo_title ?? null, $t->seo_description ?? null,
            $product->brand?->translate('name', $locale), $product->brand === null ? null : url("/{$locale}/brands/".$product->brand->translate('slug', $locale)),
            $this->breadcrumb($category === null ? null : (int) $category, (string) $product->translate('name', $locale), $locale),
            $this->gallery($product, $locale), $options, $selected->sku,
            $quote->price, $quote->onSale ? $quote->regular : $quote->compareAt, $quote->onSale, $quote->saleEndsAt,
            $quote->taxRateBp > 0 ? ($quote->pricesIncludeTax ? 'incl' : 'excl') : null,
            $this->stockState($product, $selected, $stock[$selected->id] ?? 0), $variants->count(),
        );
    }

    private function stockState(Product $product, ProductVariant $variant, int $available): string
    {
        if (! $product->product_type->tracksStock() || ! $variant->track_inventory) {
            return $product->product_type->tracksStock() ? 'in_stock' : 'not_applicable';
        }

        return match (true) {
            $available <= 0 => 'out_of_stock',
            $available <= self::LOW_STOCK => 'low_stock',
            default => 'in_stock',
        };
    }

    /** @return list<array{src: string, srcset: string, width: int, height: int, alt: string}> */
    private function gallery(Product $product, string $locale): array
    {
        $images = ProductMedia::query()->with('translations')->where('product_id', $product->id)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')->get();
        $out = [];

        foreach ($images as $image) {
            $sets = $this->media->srcsets($image);
            if (! isset($sets['webp'])) {
                continue;
            }
            $out[] = ['src' => $this->media->url($image, 960), 'srcset' => $sets['webp'], 'width' => $image->width, 'height' => $image->height, 'alt' => $image->is_decorative ? '' : $image->alt($locale)];
        }

        return $out;
    }

    /** @return list<array{label: string, url: string|null}> */
    private function breadcrumb(?int $categoryId, string $name, string $locale): array
    {
        $trail = [['label' => (string) __('catalog::storefront.shop'), 'url' => url("/{$locale}/shop")]];
        $category = $categoryId === null ? null : Category::query()->whereKey($categoryId)->first();

        if ($category !== null) {
            $path = $this->tree->pathIds($category);
            $byId = Category::query()->with('translations')->whereIn('id', $path)->get()->keyBy('id');
            foreach ($path as $id) {
                $c = $byId->get($id);
                if ($c !== null) {
                    $trail[] = ['label' => (string) $c->translate('name', $locale), 'url' => url("/{$locale}/c/".$c->translate('slug', $locale))];
                }
            }
        }

        $trail[] = ['label' => $name, 'url' => null];

        return $trail;
    }
}
