<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ProductSales;

/** The twenty products that sold the most units in the period (ties: the bigger sales first). */
final class BestSellers implements ReportDefinition
{
    public const TOP = 20;

    public function __construct(private readonly ProductSales $sales, private readonly MetricCatalogue $metrics) {}

    public function code(): string
    {
        return 'best_sellers';
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
            new ReportColumn('rank', 'count'), new ReportColumn('product'), new ReportColumn('category'), new ReportColumn('units_sold', 'count'), new ReportColumn('returned_units', 'count'),
            new ReportColumn('return_rate', 'percent'), new ReportColumn('sales_after_discounts', 'money'),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $products = $this->sales->byProduct($filter, app()->getLocale());
        usort($products, fn (array $a, array $b): int => $b['inputs']->unitsSold <=> $a['inputs']->unitsSold
            ?: ($b['inputs']->grossMinor - $b['inputs']->discountsMinor) <=> ($a['inputs']->grossMinor - $a['inputs']->discountsMinor) ?: strcmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));
        foreach (array_slice($products, 0, self::TOP) as $i => $p) {
            $m = $this->metrics->compute($p['inputs'], false);
            yield [
                'rank' => $i + 1, 'product' => $p['name'] === '' ? $p['key'] : $p['name'], 'category' => $p['category'] ?? __('reporting::catalogue.common.uncategorised'), 'units_sold' => $m['units_sold'],
                'returned_units' => $m['returned_units'], 'return_rate' => $m['return_rate'], 'sales_after_discounts' => $m['sales_after_discounts'],
            ];
        }
    }
}
