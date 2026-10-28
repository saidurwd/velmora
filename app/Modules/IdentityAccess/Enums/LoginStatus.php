<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Enums;

enum LoginStatus: string
{
    case Success = 'success';
    case Failed = 'failed';       // wrong credentials, unknown account, locked or disabled: callers show ONE generic message
    case Throttled = 'throttled';
}
