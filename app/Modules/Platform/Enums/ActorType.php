<?php

declare(strict_types=1);

namespace App\Modules\Platform\Enums;

enum ActorType: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Guest = 'guest';
    case System = 'system';
    case Provider = 'provider'; // payment gateway / courier callbacks
}
