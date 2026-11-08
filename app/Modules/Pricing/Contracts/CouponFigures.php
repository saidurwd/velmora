<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DTOs\CouponUse;
use Carbon\CarbonImmutable;

/** Read-only, for the Reporting module. */
interface CouponFigures
{
    /**
     * Every coupon use from `$from` (included) to `$to` (excluded), one at a time so a long period never sits in memory.
     *
     * @return iterable<CouponUse>
     */
    public function usedBetween(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
