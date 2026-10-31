<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/** The money arrived, verified server to server. */
final class PaymentSucceeded extends PaymentEvent
{
    public static function eventName(): string
    {
        return 'payments.succeeded';
    }
}
