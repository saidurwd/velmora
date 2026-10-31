<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** One attempt. Once it has an outcome it never changes. */
enum TransactionStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Initiated => [self::Pending, self::Succeeded, self::Failed, self::Cancelled, self::Expired],
            self::Pending => [self::Succeeded, self::Failed, self::Cancelled, self::Expired],
            // A closed attempt can still turn out to have been paid (a customer fails one card, then pays with another in the same gateway
            // session): money the gateway confirms is the truth. "Succeeded" itself never changes.
            self::Failed, self::Cancelled, self::Expired => [self::Succeeded],
            self::Succeeded => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Closed for good: nothing further can happen to it. Failed, cancelled and expired attempts are closed but may still be found paid. */
    public function isFinal(): bool
    {
        return $this === self::Succeeded;
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Expired], true);
    }
}
