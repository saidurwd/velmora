<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected],
            self::Approved => [self::Processing, self::Succeeded, self::Failed],
            self::Processing => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed, self::Rejected => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Counts against what can still be refunded: asked for, promised, in flight, or done. */
    public function reservesMoney(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Processing, self::Succeeded], true);
    }
}
