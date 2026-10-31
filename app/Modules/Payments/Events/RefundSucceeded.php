<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/**
 * Money has been given back. `amountMinor` is the refund's amount, `merchantRef` the refund's public id, and `detail` says what it means
 * for the order: 'full' (everything received is now returned), 'partial', or 'extra' (a double payment was returned; the order is unaffected).
 */
final class RefundSucceeded extends PaymentEvent
{
    public static function eventName(): string
    {
        return 'payments.refund_succeeded';
    }
}
