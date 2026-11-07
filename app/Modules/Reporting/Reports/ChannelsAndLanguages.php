<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ReportReader;

/** Where orders come from and in which language: one block of rows split by where the order was placed, one split by the language of the order. */
final class ChannelsAndLanguages implements ReportDefinition
{
    public function __construct(private readonly ReportReader $reader, private readonly MetricCatalogue $metrics) {}

    public function code(): string
    {
        return 'channels_languages';
    }

    public function permissions(): array
    {
        return ['reports.view'];
    }

    public function filters(): array
    {
        return ['period', 'method'];
    }

    public function columns(): array
    {
        return [
            new ReportColumn('split'), new ReportColumn('group'), new ReportColumn('orders', 'count'), new ReportColumn('gross_sales', 'money'), new ReportColumn('discounts', 'money'),
            new ReportColumn('billed_total', 'money'), new ReportColumn('average_order_value', 'money'),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $base = new ReportFilter($filter->from, $filter->to, null, $filter->method, null);
        foreach (['channel' => 'channel', 'locale' => 'language'] as $dimension => $split) {
            foreach ($this->reader->breakdown($base, $dimension) as $group => $inputs) {
                $m = $this->metrics->compute($inputs, false);
                yield [
                    'split' => $split, 'group' => $group, 'orders' => $m['orders'], 'gross_sales' => $m['gross_sales'], 'discounts' => $m['discounts'],
                    'billed_total' => $m['billed_total'], 'average_order_value' => $m['average_order_value'],
                ];
            }
        }
    }
}
