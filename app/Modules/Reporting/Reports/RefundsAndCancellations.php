<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\MetricInputs;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Reporting\Services\ReportReader;

/** Orders cancelled (by the day they were placed) and money given back (by the day the refund went through), every day of the period. */
final class RefundsAndCancellations implements ReportDefinition
{
    public function __construct(private readonly ReportReader $reader, private readonly MetricCatalogue $metrics, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'refunds_cancellations';
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
        return [new ReportColumn('day', 'date'), new ReportColumn('cancelled_orders', 'count'), new ReportColumn('cancelled_value', 'money'), new ReportColumn('refunded', 'money', finance: true)];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $perDay = $this->reader->perDay($filter);
        foreach ($this->days->range($filter->from, $filter->to) as $day) {
            $m = $this->metrics->compute($perDay[$day] ?? new MetricInputs, true);
            yield ['day' => $day, 'cancelled_orders' => $m['cancelled_orders'], 'cancelled_value' => $m['cancelled_value'], 'refunded' => $m['refunded']];
        }
    }
}
