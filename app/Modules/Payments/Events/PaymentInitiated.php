<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/** A customer was sent to the gateway to pay. */
final class PaymentInitiated extends PaymentEvent
{
    public static function eventName(): string
    {
        return 'payments.initiated';
    }
}
