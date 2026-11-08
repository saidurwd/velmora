<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\DTOs\ReturnReasonRow;
use Carbon\CarbonImmutable;

/** Read-only, for the Reporting module. */
interface ReturnFigures
{
    /**
     * Return requests made from `$from` (included) to `$to` (excluded), added up per kind and reason. Reason codes are the stored codes, never the customer's own words.
     *
     * @return list<ReturnReasonRow>
     */
    public function requestedBetween(CarbonImmutable $from, CarbonImmutable $to): array;
}
