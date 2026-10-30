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
            self::Succeeded, self::Failed, self::Cancelled, self::Expired => [],
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
}
