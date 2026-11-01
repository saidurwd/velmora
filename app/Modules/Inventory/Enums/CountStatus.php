<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/** A stock-take is made, handed in, and approved or refused by a second person. The same moves are enforced by a database trigger. */
enum CountStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Open => [self::Submitted, self::Approved, self::Cancelled],   // approved straight from open when nothing differs
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved, self::Rejected, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}
