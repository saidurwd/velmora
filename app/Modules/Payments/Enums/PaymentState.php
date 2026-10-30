<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** The life of one payment. The same moves are enforced by a database trigger (see the migration and its test). */
enum PaymentState: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NeedsReview = 'needs_review';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Initiated => [self::Pending, self::Paid, self::Failed, self::Cancelled, self::Expired, self::NeedsReview],
            self::Pending => [self::Paid, self::Failed, self::Cancelled, self::Expired, self::NeedsReview],
            self::Failed, self::Cancelled => [self::Pending],
            self::Expired => [self::NeedsReview],
            self::NeedsReview => [self::Paid, self::Failed, self::Cancelled, self::Expired],
            self::Paid => [self::NeedsReview],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Waiting for the gateway (or for the customer): reconciliation and expiry look at these. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Initiated, self::Pending], true);
    }
}
