<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ProductSales;
use App\Modules\Reporting\Services\Ratio;

/** Sales split by the brand of each product. Products without a brand are shown together. */
final class SalesByBrand implements ReportDefinition
{
    public function __construct(private readonly ProductSales $sales, private readonly MetricCatalogue $metrics) {}

    public function code(): string
    {
        return 'sales_by_brand';
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
            new ReportColumn('brand'), new ReportColumn('products', 'count'), new ReportColumn('units_sold', 'count'), new ReportColumn('returned_units', 'count'), new ReportColumn('gross_sales', 'money'),
            new ReportColumn('discounts', 'money'), new ReportColumn('sales_after_discounts', 'money'), new ReportColumn('share', 'percent'),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $groups = $this->sales->grouped($filter, app()->getLocale(), 'brand');
        $total = array_sum(array_map(fn (array $g): int => $g['inputs']->grossMinor - $g['inputs']->discountsMinor, $groups));
        foreach ($groups as $g) {
            $m = $this->metrics->compute($g['inputs'], false);
            yield [
                'brand' => $g['name'] ?? __('reporting::catalogue.common.no_brand'), 'products' => $g['products'], 'units_sold' => $m['units_sold'], 'returned_units' => $m['returned_units'],
                'gross_sales' => $m['gross_sales'], 'discounts' => $m['discounts'], 'sales_after_discounts' => $m['sales_after_discounts'], 'share' => Ratio::basisPoints($m['sales_after_discounts'], $total),
            ];
        }
    }
}
