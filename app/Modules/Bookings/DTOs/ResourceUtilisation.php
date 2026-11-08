<?php

declare(strict_types=1);

namespace App\Modules\Bookings\DTOs;

/** How one service resource was used in a period. Minutes: `bookedMinutes` is the service time of bookings that took the resource (confirmed, running or completed), `openMinutes` its working hours on days the shop was open. */
final readonly class ResourceUtilisation
{
    public function __construct(
        public string $resource,
        public int $bookings,
        public int $completed,
        public int $noShows,
        public int $cancelled,
        public int $bookedMinutes,
        public int $openMinutes,
    ) {}
}
