<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use Carbon\CarbonImmutable;

interface Clock
{
    /** Current instant in UTC. */
    public function now(): CarbonImmutable;

    /** Current instant in the business display timezone (Asia/Dhaka). */
    public function nowInDisplayZone(): CarbonImmutable;
}
