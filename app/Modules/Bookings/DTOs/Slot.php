<?php

declare(strict_types=1);

namespace App\Modules\Bookings\DTOs;

use Carbon\CarbonImmutable;

/** One free time a customer can pick, with the resource it is on. Times are UTC; the local helpers give the shop's own clock. */
final readonly class Slot
{
    public function __construct(
        public string $resourcePublicId,
        public string $resourceName,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public function localStart(): CarbonImmutable
    {
        return $this->startsAt->setTimezone((string) config('bookings.timezone'));
    }

    public function localEnd(): CarbonImmutable
    {
        return $this->endsAt->setTimezone((string) config('bookings.timezone'));
    }

    /** The shop's own day this slot falls on, e.g. 2026-12-01. */
    public function localDate(): string
    {
        return $this->localStart()->format('Y-m-d');
    }
}
