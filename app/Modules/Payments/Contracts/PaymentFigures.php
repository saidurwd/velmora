<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\PaymentFact;
use Carbon\CarbonImmutable;

/** Read-only money movements for the Reporting module. Cash-on-delivery collections are payments too (recorded when the courier hands the cash over the counter). */
interface PaymentFigures
{
    /**
     * Payments that were paid from `$from` (included) to `$to` (excluded), by the time they were paid, with the amount really received.
     *
     * @return list<PaymentFact>
     */
    public function capturedBetween(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * Refunds that succeeded from `$from` (included) to `$to` (excluded), by the time they succeeded.
     *
     * @return list<PaymentFact>
     */
    public function refundedBetween(CarbonImmutable $from, CarbonImmutable $to): array;
}
