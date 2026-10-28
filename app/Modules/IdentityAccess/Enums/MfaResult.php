<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Enums;

enum MfaResult: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case Locked = 'locked';      // too many wrong codes: start over from the password step
    case RecoveryUsed = 'recovery_used';
}
