<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** What the gateway itself says about a transaction when asked server to server (never what the browser says). */
enum VerifiedStatus: string
{
    case Valid = 'valid';          // money received
    case Pending = 'pending';      // not finished yet
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Unknown = 'unknown';      // the gateway has no such transaction / the answer cannot be trusted

    public function isSuccess(): bool
    {
        return $this === self::Valid;
    }
}
