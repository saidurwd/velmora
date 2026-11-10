<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Platform\DTOs\ReasonFigure;
use Carbon\CarbonImmutable;

/** Read-only, for the Reporting module. */
interface CancellationFigures
{
    /**
     * Orders cancelled from `$from` (included) to `$to` (excluded), added up per reason code and currency, with the orders' total value. Cancellations made before codes existed have a null code.
     *
     * @return list<ReasonFigure>
     */
    public function cancelledBetween(CarbonImmutable $from, CarbonImmutable $to): array;
}
