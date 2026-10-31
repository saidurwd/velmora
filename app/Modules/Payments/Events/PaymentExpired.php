<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/** Time ran out with no payment: the order should be cancelled and its stock released. */
final class PaymentExpired extends PaymentEvent
{
    public static function eventName(): string
    {
        return 'payments.expired';
    }
}
