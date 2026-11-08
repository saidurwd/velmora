<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\Ratio;
use App\Modules\Reporting\Services\ReportDays;
use Illuminate\Support\Facades\DB;

/**
 * Signed-in customers grouped by the month of their first order, and how many of them ordered again 1 to 6 months later. Guests are not here (there is no safe way to know two guest
 * orders are one person). Worked out again every night, so today's orders show tomorrow. A month that has not started yet is left blank.
 */
final class CustomerCohorts implements ReportDefinition
{
    private const FOLLOW_MONTHS = 6;

    public function __construct(private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'customer_cohorts';
    }

    public function permissions(): array
    {
        return ['reports.view'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function columns(): array
    {
        $columns = [new ReportColumn('cohort'), new ReportColumn('customers', 'count')];
        foreach (range(1, self::FOLLOW_MONTHS) as $k) {
            $columns[] = new ReportColumn('month_'.$k, 'percent');
        }

        return [...$columns, new ReportColumn('orders', 'count'), new ReportColumn('sales', 'money', finance: true)];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $thisMonth = substr($this->days->today(), 0, 7);
        $cells = [];
        foreach (DB::table('report_cohort_months')->whereBetween('cohort_month', [substr($filter->from, 0, 7), substr($filter->to, 0, 7)])->orderBy('cohort_month')->orderBy('months_since')   // unique (cohort_month, months_since)
            ->get(['cohort_month', 'months_since', 'customers', 'orders', 'sales_minor']) as $r) {
            $cells[(string) $r->cohort_month][(int) $r->months_since] = $r;
        }

        foreach ($cells as $cohort => $bySince) {
            $size = (int) ($bySince[0]->customers ?? 0);
            $row = ['cohort' => $cohort, 'customers' => $size];
            foreach (range(1, self::FOLLOW_MONTHS) as $k) {
                $row['month_'.$k] = $this->monthsAfter($cohort, $k) > $thisMonth ? null : Ratio::basisPoints((int) ($bySince[$k]->customers ?? 0), $size);
            }
            yield $row + ['orders' => (int) array_sum(array_map(fn ($c): int => (int) $c->orders, $bySince)), 'sales' => (int) array_sum(array_map(fn ($c): int => (int) $c->sales_minor, $bySince))];
        }
    }

    /** The month (Y-m) that is `$k` months after `$cohort` (Y-m). */
    private function monthsAfter(string $cohort, int $k): string
    {
        $index = (int) substr($cohort, 0, 4) * 12 + (int) substr($cohort, 5, 2) - 1 + $k;

        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }
}
