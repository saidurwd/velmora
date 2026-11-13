<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Dashboard;

use App\Modules\Platform\Contracts\KeyFigureTile;
use App\Modules\Platform\DTOs\TileFigure;
use App\Modules\Reporting\DTOs\MetricInputs;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Reporting\Services\ReportReader;
use Carbon\CarbonImmutable;

/**
 * Today's orders (Dhaka day) against yesterday, with the last seven days. The figure is the same metric the reports use, read from the same summary tables (refreshed every
 * fifteen minutes), so the dashboard and the sales report cannot disagree. Net sales also needs the finance permission.
 */
abstract class DayFigureTile implements KeyFigureTile
{
    public function __construct(protected readonly ReportReader $reader, protected readonly MetricCatalogue $metrics, protected readonly ReportDays $days) {}

    abstract protected function metric(): string;

    abstract protected function kind(): string;

    public function figure(): TileFigure
    {
        $today = $this->days->today();
        $from = CarbonImmutable::parse($today)->subDays(6)->toDateString();
        $perDay = $this->reader->perDay(new ReportFilter($from, $today));

        $series = [];
        foreach ($this->days->range($from, $today) as $day) {
            $series[] = (int) $this->metrics->compute($perDay[$day] ?? new MetricInputs, true)[$this->metric()];
        }

        /** @var 'count'|'money' $kind */
        $kind = $this->kind();

        return new TileFigure($series[6], $kind, 'admin.reports.show', ['code' => 'sales_by_day', 'from' => $today, 'to' => $today], before: $series[5], series: $series);
    }
}
