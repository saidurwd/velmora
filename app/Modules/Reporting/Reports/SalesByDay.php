<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\MetricInputs;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Reporting\Services\ReportReader;

/** Every day of the period, gross to net. The same figures as the sales summary screen, one row a day. */
final class SalesByDay implements ReportDefinition
{
    public function __construct(private readonly ReportReader $reader, private readonly MetricCatalogue $metrics, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'sales_by_day';
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
            new ReportColumn('day', 'date'), new ReportColumn('orders', 'count'), new ReportColumn('gross_sales', 'money'), new ReportColumn('discounts', 'money'),
            new ReportColumn('delivery_charges', 'money'), new ReportColumn('cod_fees', 'money'), new ReportColumn('tax', 'money'), new ReportColumn('billed_total', 'money'),
            new ReportColumn('average_order_value', 'money'), new ReportColumn('cancelled_orders', 'count'), new ReportColumn('collected', 'money', finance: true),
            new ReportColumn('refunded', 'money', finance: true), new ReportColumn('net_sales', 'money', finance: true),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $perDay = $this->reader->perDay($filter);
        foreach ($this->days->range($filter->from, $filter->to) as $day) {
            yield ['day' => $day] + $this->metrics->compute($perDay[$day] ?? new MetricInputs, true);
        }
    }
}
