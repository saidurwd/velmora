<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

enum FulfillmentStatus: string
{
    case Unfulfilled = 'unfulfilled';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Unfulfilled => [self::PartiallyFulfilled, self::Fulfilled],
            self::PartiallyFulfilled => [self::Fulfilled, self::Unfulfilled],   // back to unfulfilled when the only parcel is cancelled before it leaves
            self::Fulfilled => [self::PartiallyFulfilled, self::Unfulfilled, self::PartiallyReturned, self::Returned],
            self::PartiallyReturned => [self::Returned],
            self::Returned => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Anything has left the building. */
    public function hasShipped(): bool
    {
        return $this !== self::Unfulfilled;
    }
}
