<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Enums;

enum AccountType: string
{
    case Customer = 'customer';
    case Staff = 'staff';

    public function guard(): string
    {
        return match ($this) {
            self::Customer => 'web',
            self::Staff => 'admin',
        };
    }
}
