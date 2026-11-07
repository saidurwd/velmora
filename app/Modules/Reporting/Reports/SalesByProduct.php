<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ProductSales;

/** Every product that sold in the period: units, returns, goods price, discounts and sales after discounts, biggest sales first. Cancelled orders are left out. */
final class SalesByProduct implements ReportDefinition
{
    public function __construct(private readonly ProductSales $sales, private readonly MetricCatalogue $metrics) {}

    public function code(): string
    {
        return 'sales_by_product';
    }

    public function permissions(): array
    {
        return ['reports.view'];
    }

    public function filters(): array
    {
        return ['period', 'channel', 'method', 'locale'];
    }

    public function columns(): array
    {
        return [
            new ReportColumn('product'), new ReportColumn('category'), new ReportColumn('brand'), new ReportColumn('variants', 'count'), new ReportColumn('units_sold', 'count'),
            new ReportColumn('returned_units', 'count'), new ReportColumn('return_rate', 'percent'), new ReportColumn('gross_sales', 'money'), new ReportColumn('discounts', 'money'),
            new ReportColumn('sales_after_discounts', 'money'),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        foreach ($this->sales->byProduct($filter, app()->getLocale()) as $p) {
            $m = $this->metrics->compute($p['inputs'], false);
            yield [
                'product' => $p['name'] === '' ? $p['key'] : $p['name'], 'category' => $p['category'] ?? __('reporting::catalogue.common.uncategorised'), 'brand' => $p['brand'] ?? __('reporting::catalogue.common.no_brand'),
                'variants' => $p['variants'], 'units_sold' => $m['units_sold'], 'returned_units' => $m['returned_units'], 'return_rate' => $m['return_rate'],
                'gross_sales' => $m['gross_sales'], 'discounts' => $m['discounts'], 'sales_after_discounts' => $m['sales_after_discounts'],
            ];
        }
    }
}
