<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

enum ChangePhoneResult: string
{
    case Changed = 'changed';
    case InvalidCode = 'invalid_code';
    case AlreadyUsed = 'already_used';
}
