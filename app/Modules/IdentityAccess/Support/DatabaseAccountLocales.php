<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Support;

use App\Modules\IdentityAccess\Contracts\AccountLocales;
use App\Modules\IdentityAccess\Models\User;

final class DatabaseAccountLocales implements AccountLocales
{
    public function forUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $out = [];
        foreach (User::query()->whereIn('id', $userIds)->get(['id', 'locale']) as $user) {   // primary key
            $out[(int) $user->id] = (string) $user->locale;
        }

        return $out;
    }
}
