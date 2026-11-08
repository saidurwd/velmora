<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\Ratio;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Shipping\Contracts\DeliveryFigures;
use Illuminate\Support\Str;

/** How each courier did on the parcels handed to it in the period: delivered, failed, brought back, still on the way, and how long delivery took. */
final class DeliveryPerformance implements ReportDefinition
{
    public function __construct(private readonly DeliveryFigures $figures, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'delivery_performance';
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
            new ReportColumn('courier'), new ReportColumn('parcels', 'count'), new ReportColumn('delivered', 'count'), new ReportColumn('failed', 'count'), new ReportColumn('returned', 'count'),
            new ReportColumn('in_transit', 'count'), new ReportColumn('delivery_rate', 'percent'), new ReportColumn('return_rate', 'percent'), new ReportColumn('failed_attempts', 'count'),
            new ReportColumn('avg_hours', 'count'), new ReportColumn('cod_value', 'money', finance: true),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $from = $this->days->bounds($filter->from)[0];
        $to = $this->days->bounds($filter->to)[1];

        foreach ($this->figures->handedOverBetween($from, $to, (string) config('reporting.currency')) as $r) {
            yield [
                'courier' => Str::headline($r->courier), 'parcels' => $r->parcels, 'delivered' => $r->delivered, 'failed' => $r->failed, 'returned' => $r->returned, 'in_transit' => $r->inTransit,
                'delivery_rate' => Ratio::basisPoints($r->delivered, $r->parcels), 'return_rate' => Ratio::basisPoints($r->returned, $r->parcels), 'failed_attempts' => $r->failedAttempts,
                'avg_hours' => $r->delivered > 0 ? intdiv(2 * $r->deliverySeconds + 3600 * $r->delivered, 7200 * $r->delivered) : 0,   // hours, rounded to the nearest
                'cod_value' => $r->codMinor,
            ];
        }
    }
}
