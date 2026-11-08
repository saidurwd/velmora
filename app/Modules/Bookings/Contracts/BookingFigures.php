<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Contracts;

use App\Modules\Bookings\DTOs\ResourceUtilisation;

/** Read-only, for the Reporting module. */
interface BookingFigures
{
    /**
     * Per service resource, for the shop days `$fromDate` to `$toDate` (Y-m-d, both included): bookings by what became of them, booked minutes and open minutes.
     * A booking belongs to the day its service starts. Bookings still held (not yet paid for) are not counted.
     *
     * @return list<ResourceUtilisation> in resource name order
     */
    public function utilisation(string $fromDate, string $toDate): array;
}
