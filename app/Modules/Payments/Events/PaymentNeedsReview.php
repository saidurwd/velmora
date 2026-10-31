<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/** Something about the payment needs a person: a mismatch, or money that arrived for an order that can no longer take it. */
final class PaymentNeedsReview extends PaymentEvent
{
    public static function eventName(): string
    {
        return 'payments.needs_review';
    }
}
