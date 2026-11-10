<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Platform\DTOs\ReasonFigure;
use Carbon\CarbonImmutable;

/** Read-only, for the Reporting module. */
interface RefundReasonFigures
{
    /**
     * Refunds that went through from `$from` (included) to `$to` (excluded), added up per reason code and currency. Refunds made before codes existed have a null code.
     *
     * @return list<ReasonFigure>
     */
    public function succeededBetween(CarbonImmutable $from, CarbonImmutable $to): array;
}
