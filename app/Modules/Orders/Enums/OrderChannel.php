<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

enum OrderChannel: string
{
    case Web = 'web';
    case Api = 'api';
    case Admin = 'admin';
}
