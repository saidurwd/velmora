<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Disabled = 'disabled';
}
