<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Enums;

enum RefreshStatus: string
{
    case Renewed = 'renewed';
    case Invalid = 'invalid';   // unknown, expired, already ended, or the account may no longer sign in
    case Reused = 'reused';     // a token that was already swapped: theft is assumed and the whole sign-in is ended
}
