<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class ResetPassword
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return bool true when the token was valid and the password changed */
    public function handle(string $email, string $token, string $newPassword): bool
    {
        $status = Password::broker('users')->reset(
            ['email' => mb_strtolower(trim($email)), 'password' => $newPassword, 'token' => $token],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'failed_login_attempts' => 0, 'locked_until' => null])->save();

                // every existing device must sign in again with the new password
                DB::table('user_sessions')->where('user_id', $user->id)->delete();
                $this->audit->record('auth.password_reset', $user, actor: Actor::guest());
                Event::dispatch(new PasswordReset($user));
            },
        );

        return $status === Password::PASSWORD_RESET;
    }
}
