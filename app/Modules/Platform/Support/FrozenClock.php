<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;

/** Test double: a clock you control. */
final class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(string|CarbonImmutable $now = '2026-01-01 00:00:00')
    {
        $this->now = CarbonImmutable::parse($now, 'UTC')->utc();
    }

    public function travelTo(string|CarbonImmutable $now): void
    {
        $this->now = CarbonImmutable::parse($now, 'UTC')->utc();
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->addSeconds($seconds);
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function nowInDisplayZone(): CarbonImmutable
    {
        return $this->now->setTimezone((string) config('app.display_timezone', 'Asia/Dhaka'));
    }
}
