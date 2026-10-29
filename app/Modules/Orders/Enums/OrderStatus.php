<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** Where the order is in its life. `completed` and `cancelled` are final. */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::Cancelled],
            self::Processing => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /** Still counts as an open order (for example against a customer's open cash-on-delivery limit). */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }
}
