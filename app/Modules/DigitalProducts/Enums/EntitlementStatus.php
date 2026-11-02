<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Enums;

/** Whether a customer may still download. "Expired" is not stored: it is worked out from `expires_at`, so extending is a plain change of that date. */
enum EntitlementStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Active => [self::Revoked],
            self::Revoked => [],
        };
    }
}
