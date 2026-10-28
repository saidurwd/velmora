<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;

final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public function nowInDisplayZone(): CarbonImmutable
    {
        return $this->now()->setTimezone((string) config('app.display_timezone', 'Asia/Dhaka'));
    }
}
