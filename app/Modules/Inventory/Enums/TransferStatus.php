<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/** A transfer between two places is on its way until it is received or cancelled. The same moves are enforced by a database trigger. */
enum TransferStatus: string
{
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::InTransit => [self::Received, self::Cancelled],
            self::Received, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}
