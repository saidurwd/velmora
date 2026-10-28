<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Enums;

/** Internal detail for audit/metrics only. Callers must return one generic message for every non-Verified case. */
enum OtpVerifyResult: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Locked = 'locked';
    case NoChallenge = 'no_challenge';
}
