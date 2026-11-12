<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Resources;

use App\Modules\IdentityAccess\Models\User;

/** The signed-in customer, as the app sees them: own data only, never an internal id. */
final class UserResource
{
    /** @return array{id: string, name: string|null, email: string|null, phone: string|null, locale: string, email_verified: bool, phone_verified: bool, has_password: bool} */
    public static function make(User $user): array
    {
        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'email_verified' => $user->email_verified_at !== null,
            'phone_verified' => $user->phone_verified_at !== null,
            'has_password' => $user->password !== null,
        ];
    }
}
