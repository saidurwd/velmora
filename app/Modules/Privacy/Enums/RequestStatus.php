<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Enums;

enum RequestStatus: string
{
    case Requested = 'requested';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Blocked = 'blocked';
    case Done = 'done';

    /** While open, a customer cannot start another request of the same kind. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Blocked], true);
    }
}
