<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use Illuminate\Support\Facades\Password;

/** Always silent: whether or not the address exists, the caller shows the same message. */
final class RequestPasswordReset
{
    public function handle(string $email, string $broker = 'users'): void
    {
        Password::broker($broker)->sendResetLink(['email' => mb_strtolower(trim($email))]);
    }
}
