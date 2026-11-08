<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Bookings\Contracts\BookingFigures;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\Ratio;

/**
 * Each service resource over the period: bookings by what became of them, how much of its working time was booked, and the no-show rate (no-shows out of the bookings whose time
 * came and were not cancelled). A booking belongs to the day its service starts. No-shows and cancelled bookings add no booked time. Held bookings (not yet paid) are not counted.
 */
final class BookingUtilisation implements ReportDefinition
{
    public function __construct(private readonly BookingFigures $figures) {}

    public function code(): string
    {
        return 'booking_utilisation';
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
            new ReportColumn('resource'), new ReportColumn('bookings', 'count'), new ReportColumn('completed', 'count'), new ReportColumn('no_shows', 'count'), new ReportColumn('cancelled', 'count'),
            new ReportColumn('no_show_rate', 'percent'), new ReportColumn('booked_minutes', 'count'), new ReportColumn('open_minutes', 'count'), new ReportColumn('utilisation', 'percent'),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        foreach ($this->figures->utilisation($filter->from, $filter->to) as $r) {
            yield [
                'resource' => $r->resource, 'bookings' => $r->bookings, 'completed' => $r->completed, 'no_shows' => $r->noShows, 'cancelled' => $r->cancelled,
                'no_show_rate' => Ratio::basisPoints($r->noShows, $r->completed + $r->noShows), 'booked_minutes' => $r->bookedMinutes, 'open_minutes' => $r->openMinutes,
                'utilisation' => Ratio::basisPoints($r->bookedMinutes, $r->openMinutes),
            ];
        }
    }
}
