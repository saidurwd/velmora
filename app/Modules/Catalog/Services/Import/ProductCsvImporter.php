<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Import;

use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\CsvReader;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Contracts\PriceWriter;
use App\Modules\Pricing\Contracts\TaxRates;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Simple (one-variant) products from CSV, keyed by SKU: a known SKU is updated, an unknown one is created, so
 * re-importing the same file changes nothing. Blank cells never erase existing data. Rows apply in chunks, each chunk in
 * one transaction; a bad row is rolled back and reported on its own. A dry run takes the identical path inside a
 * transaction that is always rolled back, so what it reports is what a real run would do.
 */
final class ProductCsvImporter
{
    public const COLUMNS = [
        'sku', 'product_type', 'name_en', 'name_bn', 'slug_en', 'slug_bn', 'short_description_en', 'short_description_bn', 'description_en', 'description_bn',
        'category', 'brand', 'tags', 'tax_class', 'price', 'compare_at', 'barcode', 'weight_g', 'track_inventory', 'opening_stock',
    ];

    private const REQUIRED = ['sku', 'product_type', 'name_en'];

    private const CHUNK = 50;

    public function __construct(
        private readonly SaveProduct $saveProduct,
        private readonly PriceWriter $prices,
        private readonly StockInitialiser $stock,
        private readonly TaxRates $tax,
    ) {}

    /** @throws ImportFailed */
    public function run(string $path, Actor $actor, bool $dryRun): ImportReport
    {
        $locales = array_values(array_map('strval', (array) config('app.supported_locales')));
        $parser = new ProductRowParser($this->tax->classCodes(), $locales);
        $report = new ImportReport;
        $reader = new CsvReader;

        $plans = [];
        $seen = [];
        try {
            foreach ($reader->rows($path) as $line => $row) {
                $parsed = $parser->parse($row);
                $sku = $parsed['data']['sku'];
                if ($sku !== '' && isset($seen[$sku])) {
                    $parsed['errors'][] = ['key' => 'duplicate_in_file', 'params' => ['line' => (string) $seen[$sku]]];
                }
                $seen[$sku] ??= $line;
                $plans[$line] = $parsed;
            }
        } catch (RuntimeException $e) {
            throw new ImportFailed('unreadable', [], $e->getMessage());
        }

        $missing = array_values(array_diff(self::REQUIRED, $reader->headers()));
        if ($missing !== []) {
            throw new ImportFailed('missing_columns', ['columns' => implode(', ', $missing)]);
        }
        $report->ignoredColumns = array_values(array_diff($reader->headers(), self::COLUMNS));

        $apply = function () use ($plans, $actor, $report): void {
            $valid = [];
            foreach ($plans as $line => $plan) {
                if ($plan['errors'] !== []) {
                    $report->add($line, (string) $plan['data']['sku'], 'error', $plan['errors']);
                } else {
                    $valid[$line] = $plan['data'];
                }
            }
            foreach (array_chunk($valid, self::CHUNK, true) as $chunk) {
                $this->applyChunk($chunk, $actor, $report);
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
        } else {
            $apply();
        }

        usort($report->rows, fn ($a, $b) => $a['line'] <=> $b['line']);

        return $report;
    }

    /** @param  array<int, array<string, mixed>>  $chunk */
    private function applyChunk(array $chunk, Actor $actor, ImportReport $report): void
    {
        try {
            $results = Transactions::run(function () use ($chunk, $actor): array {
                $out = [];
                foreach ($chunk as $line => $data) {
                    try {
                        $out[] = [$line, $data['sku'], DB::transaction(fn () => $this->applyRow($data, $actor)), []];
                    } catch (RowRejected $e) {
                        $out[] = [$line, $data['sku'], 'error', [['key' => $e->key, 'params' => $e->params]]];
                    } catch (DomainException $e) { // every module's business-rule exception extends DomainException
                        $out[] = [$line, $data['sku'], 'error', [['key' => 'domain', 'params' => ['message' => $e->getMessage()]]]];
                    }
                }

                return $out;
            });
        } catch (Throwable $e) {
            Log::error('catalog.import.chunk_failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            foreach ($chunk as $line => $data) {
                $report->add($line, (string) $data['sku'], 'error', [['key' => 'chunk_failed', 'params' => []]]);
            }

            return;
        }

        foreach ($results as [$line, $sku, $outcome, $errors]) {
            $report->add($line, (string) $sku, $outcome, $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $d
     * @return 'created'|'updated'
     */
    private function applyRow(array $d, Actor $actor): string
    {
        $existingVariant = ProductVariant::withTrashed()->where('sku', $d['sku'])->first();
        if ($existingVariant?->trashed()) {
            throw new RowRejected('sku_deleted');
        }

        $product = $existingVariant?->product;
        if ($existingVariant !== null && $product !== null && (! $existingVariant->is_default || $existingVariant->option_signature !== '' || $product->variants()->count() !== 1)) {
            throw new RowRejected('not_simple');
        }

        $categoryIds = $this->ids('category_translations', 'category_id', $d['categories'], 'unknown_category');
        $tagIds = $this->ids('tag_translations', 'tag_id', $d['tags'], 'unknown_tag');
        $brandId = $d['brand'] === '' ? null : ($this->ids('brand_translations', 'brand_id', [$d['brand']], 'unknown_brand')[0] ?? null);

        $saved = $this->saveProduct->handle($actor, $product, new ProductData(
            $d['type'],
            $this->translations($product, $d['translations']),
            $brandId ?? $product?->brand_id,
            $d['tax_class'],
            $categoryIds !== [] || $product === null ? $categoryIds : $this->linked('product_categories', 'category_id', $product),
            null,
            $tagIds !== [] || $product === null ? $tagIds : $this->linked('product_tags', 'tag_id', $product),
            $this->variant($existingVariant, $d),
            $actor->id,
        ));

        $variantId = (int) $saved->variants()->where('sku', $d['sku'])->value('id');

        if ($d['price'] !== null) {
            $this->prices->setRegular($actor, $variantId, $d['price'], $d['compare_at']);
        }
        if (($d['opening_stock'] ?? 0) > 0) {
            $this->stock->openingStock($actor, $variantId, $d['opening_stock']);
        }

        return $product === null ? 'created' : 'updated';
    }

    /** @param  array<string, mixed>  $d */
    private function variant(?ProductVariant $existing, array $d): VariantData
    {
        return new VariantData(
            $d['sku'],
            $d['barcode'] ?? $existing?->barcode,
            $d['track'],
            $d['weight_g'] ?? ($existing?->weight_g === null ? null : (int) $existing->weight_g),
            $existing?->length_mm === null ? null : (int) $existing->length_mm,
            $existing?->width_mm === null ? null : (int) $existing->width_mm,
            $existing?->height_mm === null ? null : (int) $existing->height_mm,
            (int) ($existing->min_qty ?? 1),
            $existing?->max_qty === null ? null : (int) $existing->max_qty,
            (int) ($existing->qty_step ?? 1),
            (string) ($existing->status ?? 'active'),
        );
    }

    /**
     * Row cells win; anything the row leaves blank (and any language it does not mention) keeps its current value.
     *
     * @param  array<string, array<string, string>>  $fromRow
     * @return list<TranslationInput>
     */
    private function translations(?Product $product, array $fromRow): array
    {
        $current = $product === null ? collect() : $product->translations()->get()->keyBy('locale');
        $out = [];

        foreach (array_unique([...array_keys($fromRow), ...$current->keys()->all()]) as $locale) {
            $row = $fromRow[$locale] ?? [];
            $old = $current->get($locale);
            $pick = fn (string $field, ?string $fallback) => ($row[$field] ?? '') !== '' ? $row[$field] : $fallback;

            $out[] = new TranslationInput(
                (string) $locale,
                (string) $pick('name', $old?->name),
                $pick('slug', $old?->slug),
                $pick('description', $old?->description),
                $old?->seo_title,
                $old?->seo_description,
                $pick('short_description', $old?->short_description),
            );
        }

        return $out;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function ids(string $table, string $column, array $slugs, string $unknownKey): array
    {
        if ($slugs === []) {
            return [];
        }

        $found = DB::table($table)->whereIn('slug', $slugs)->pluck($column, 'slug');
        $ids = [];
        foreach ($slugs as $slug) {
            $ids[] = (int) ($found[$slug] ?? throw new RowRejected($unknownKey, ['value' => $slug]));
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    private function linked(string $table, string $column, Product $product): array
    {
        return array_values(array_map('intval', DB::table($table)->where('product_id', $product->id)->pluck($column)->all()));
    }
}
