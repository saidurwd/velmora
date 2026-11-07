<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Inventory\Contracts\StockReportFigures;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;

/** The longest-sitting stock, in units (the system holds no cost price): days since one last left in a parcel, or since the stock first arrived. */
final class StockAging implements ReportDefinition
{
    public function __construct(private readonly StockReportFigures $stock, private readonly PurchasableVariants $variants) {}

    public function code(): string
    {
        return 'stock_aging';
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
        return [new ReportColumn('product'), new ReportColumn('sku'), new ReportColumn('location'), new ReportColumn('units', 'count'), new ReportColumn('days', 'count')];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $lines = $this->stock->oldest(1000);
        foreach (array_chunk($lines, 500) as $chunk) {
            $names = $this->variants->details(array_map(fn ($l): int => $l->variantId, $chunk), app()->getLocale());
            foreach ($chunk as $l) {
                $v = $names[$l->variantId] ?? null;
                yield [
                    'product' => $v === null ? '' : trim($v->productName.($v->optionsLabel !== null ? ' ('.$v->optionsLabel.')' : '')), 'sku' => $v->sku ?? '',
                    'location' => $l->location, 'units' => $l->units, 'days' => $l->days,
                ];
            }
        }
    }
}
