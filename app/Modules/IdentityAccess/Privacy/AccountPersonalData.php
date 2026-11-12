<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Privacy;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/**
 * The sign-in itself. Exported: the details the customer gave. Erased LAST: name, phone and password are removed, the email becomes a fixed address that can never
 * receive mail (".invalid"), the account is disabled, and every session and token is ended. The row stays so that past orders still point at "a deleted account".
 * Staff accounts are never erased this way.
 */
final class AccountPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public function __construct(private readonly Clock $clock) {}

    public function section(): string
    {
        return 'account';
    }

    public function export(int $userId): array
    {
        $user = DB::table('users')->where('id', $userId)->where('account_type', 'customer')->first();

        return $user === null ? [] : ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'language' => $user->locale, 'joined_at' => $user->created_at, 'last_sign_in_at' => $user->last_login_at];
    }

    public function blockers(int $userId): array
    {
        return DB::table('users')->where('id', $userId)->where('account_type', '<>', 'customer')->exists() ? ['staff_account'] : [];
    }

    public function erase(int $userId): void
    {
        $user = DB::table('users')->where('id', $userId)->where('account_type', 'customer')->first();
        if ($user === null) {
            return;
        }
        $erasedEmail = 'erased-'.$user->public_id.'@erased.invalid';
        if ($user->email !== $erasedEmail || $user->status !== 'disabled') {   // already erased: nothing to change, and no new "updated" time
            DB::table('users')->where('id', $userId)->update([
                'name' => null, 'email' => $erasedEmail, 'phone' => null, 'password' => null, 'remember_token' => null,
                'email_verified_at' => null, 'phone_verified_at' => null, 'status' => 'disabled', 'updated_at' => $this->clock->now(),
            ]);
        }
        DB::table('user_sessions')->where('user_id', $userId)->delete();
        DB::table('sessions')->where('user_id', $userId)->delete();
        DB::table('refresh_tokens')->where('user_id', $userId)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->where('tokenable_id', $userId)->delete();
        $targets = array_values(array_filter([$user->email, $user->phone]));
        if ($targets !== []) {
            DB::table('otp_challenges')->whereIn('target', $targets)->delete();
            DB::table('password_reset_tokens')->whereIn('email', $targets)->delete();
        }
    }
}
