<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** The life of one return. The same moves are enforced by a database trigger (see the migration and its test). */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Requested => [self::Authorized, self::Rejected, self::Cancelled],
            self::Authorized => [self::Received, self::Cancelled],
            self::Received => [self::Closed],
            self::Rejected, self::Closed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Goods are still expected or waiting to be looked at: these quantities are spoken for. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Authorized, self::Received], true);
    }
}
