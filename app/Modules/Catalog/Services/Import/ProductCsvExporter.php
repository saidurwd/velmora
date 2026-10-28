<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Import;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\CsvWriter;
use App\Modules\Pricing\Contracts\PriceCatalog;
use Illuminate\Support\Collection;

/** One row per variant, same columns the importer reads (plus read-only `status` and `stock_available`). Streams in chunks. */
final class ProductCsvExporter
{
    private const EXTRA = ['status', 'stock_available'];

    public function __construct(private readonly PriceCatalog $prices, private readonly Availability $availability) {}

    /** @return list<string> */
    public static function header(): array
    {
        return [...ProductCsvImporter::COLUMNS, ...self::EXTRA];
    }

    /** @param  resource  $out */
    public function stream($out): void
    {
        CsvWriter::bom($out);
        CsvWriter::row($out, self::header());

        $numeric = array_keys(array_intersect(self::header(), ['price', 'compare_at', 'weight_g', 'stock_available']));

        ProductVariant::query()
            ->with(['product.translations', 'product.categories.translations', 'product.tags.translations', 'product.brand.translations'])
            ->whereHas('product')
            ->chunkById(200, function (Collection $variants) use ($out, $numeric): void {
                $ids = array_values($variants->pluck('id')->map(fn ($v) => (int) $v)->all());
                $prices = $this->prices->regularPrices($ids);
                $stock = $this->availability->forVariants($ids);

                foreach ($variants as $v) {
                    $p = $v->product;
                    if ($p === null) {
                        continue;
                    }
                    $price = $prices[$v->id] ?? null;
                    $t = fn (string $locale, string $field) => (string) ($p->translations->firstWhere('locale', $locale)->{$field} ?? '');
                    $slugs = fn ($items) => $items->map(fn ($i) => (string) ($i->translations->firstWhere('locale', 'en')->slug ?? ''))->filter()->implode('|');

                    CsvWriter::row($out, [
                        $v->sku, $p->product_type->value,
                        $t('en', 'name'), $t('bn', 'name'), $t('en', 'slug'), $t('bn', 'slug'),
                        $t('en', 'short_description'), $t('bn', 'short_description'), $t('en', 'description'), $t('bn', 'description'),
                        $slugs($p->categories), (string) ($p->brand?->translations->firstWhere('locale', 'en')->slug ?? ''), $slugs($p->tags), $p->tax_class_code,
                        $price === null ? '' : Money::of($price['amount_minor'])->toMajorString(),
                        $price === null || $price['compare_at_minor'] === null ? '' : Money::of($price['compare_at_minor'])->toMajorString(),
                        (string) ($v->barcode ?? ''), $v->weight_g === null ? '' : (string) $v->weight_g, $v->track_inventory ? 'yes' : 'no', '',
                        $p->status->value, $v->track_inventory ? (string) ($stock[$v->id] ?? 0) : '',
                    ], $numeric);
                }
            });
    }
}
