<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\Ratio;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Shipping\Contracts\ReturnFigures;

/** Return requests made in the period, by who started them (the customer, or the courier bringing a parcel back) and why. The customer's own words are never shown here. */
final class ReturnsByReason implements ReportDefinition
{
    public function __construct(private readonly ReturnFigures $figures, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'returns_by_reason';
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
        return [
            new ReportColumn('kind'), new ReportColumn('reason'), new ReportColumn('requests', 'count'), new ReportColumn('units', 'count'), new ReportColumn('approved', 'count'),
            new ReportColumn('rejected', 'count'), new ReportColumn('received', 'count'), new ReportColumn('share', 'percent'),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $rows = $this->figures->requestedBetween($this->days->bounds($filter->from)[0], $this->days->bounds($filter->to)[1]);
        usort($rows, fn ($a, $b): int => [$b->requests, $a->kind, $a->reasonCode] <=> [$a->requests, $b->kind, $b->reasonCode]);   // most requests first
        $total = array_sum(array_map(fn ($r): int => $r->requests, $rows));

        foreach ($rows as $r) {
            yield [
                'kind' => $this->word('kind', $r->kind), 'reason' => $this->word('reason', $r->reasonCode), 'requests' => $r->requests, 'units' => $r->units, 'approved' => $r->approved,
                'rejected' => $r->rejected, 'received' => $r->received, 'share' => Ratio::basisPoints($r->requests, $total),
            ];
        }
    }

    /** A known code in the reader's language; a code added later shows as it is rather than as a missing key. */
    private function word(string $group, string $code): string
    {
        $key = 'reporting::catalogue.'.$group.'.'.$code;

        return app('translator')->has($key) ? __($key) : $code;
    }
}
