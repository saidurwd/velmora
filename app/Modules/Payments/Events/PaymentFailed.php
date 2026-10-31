<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/** An attempt ended without payment and the customer may try again. */
final class PaymentFailed extends PaymentEvent
{
    public static function eventName(): string
    {
        return 'payments.failed';
    }
}
