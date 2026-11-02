<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Enums;

/** The life of one booking. The same moves are enforced by a database trigger (see the migration and its test). */
enum BookingStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Held => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::InProgress, self::Completed, self::Cancelled, self::NoShow],
            self::InProgress => [self::Completed, self::NoShow],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** The resource is taken for this booking's time (held or confirmed). The database lets nobody else book it. */
    public function holdsTheSlot(): bool
    {
        return in_array($this, [self::Held, self::Confirmed], true);
    }
}
