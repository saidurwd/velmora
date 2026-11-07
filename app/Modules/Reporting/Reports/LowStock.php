<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Inventory\Contracts\StockReportFigures;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;

/** Products with nothing left to sell, or at or below the level someone set as low, per place, least left first. */
final class LowStock implements ReportDefinition
{
    public function __construct(private readonly StockReportFigures $stock, private readonly PurchasableVariants $variants) {}

    public function code(): string
    {
        return 'low_stock';
    }

    public function permissions(): array
    {
        return ['reports.view', 'inventory.view'];
    }

    public function filters(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new ReportColumn('product'), new ReportColumn('sku'), new ReportColumn('location'), new ReportColumn('on_hand', 'count'), new ReportColumn('reserved', 'count'),
            new ReportColumn('available', 'count'), new ReportColumn('low_level', 'count'),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $lines = $this->stock->needingAttention((int) config('reporting.exports.max_rows') + 1);
        foreach (array_chunk($lines, 500) as $chunk) {
            $names = $this->variants->details(array_map(fn ($l): int => $l->variantId, $chunk), app()->getLocale());
            foreach ($chunk as $l) {
                $v = $names[$l->variantId] ?? null;
                yield [
                    'product' => $v === null ? '' : trim($v->productName.($v->optionsLabel !== null ? ' ('.$v->optionsLabel.')' : '')), 'sku' => $v->sku ?? '', 'location' => $l->location,
                    'on_hand' => $l->onHand, 'reserved' => $l->reserved, 'available' => $l->available, 'low_level' => $l->threshold,
                ];
            }
        }
    }
}
