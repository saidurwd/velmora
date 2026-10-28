<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\DTOs\LoginOutcome;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\OtpPurpose;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Events\UserRegistered;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Otp\OtpService;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Database\UniqueConstraintViolationException;

/** Verifies a code and returns the customer to sign in, creating the account on first use. */
final class VerifyPhoneOtpAndSignIn
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(PhoneNumber $phone, string $code, string $locale): LoginOutcome
    {
        if ($this->otp->verify($phone, OtpPurpose::Login, $code) !== OtpVerifyResult::Verified) {
            $this->audit->record('auth.otp_failed', after: ['phone' => $phone->masked()], actor: Actor::guest());

            return LoginOutcome::failed();
        }

        $user = User::query()->where('phone', $phone->e164())->where('account_type', AccountType::Customer->value)->first();

        if ($user === null) {
            // staff may not be reached through the customer phone flow, and a phone cannot belong to two accounts
            if (User::query()->where('phone', $phone->e164())->exists()) {
                return LoginOutcome::failed();
            }

            $user = $this->createAccount($phone, $locale);
        }

        if (! $user->isActive()) {
            return LoginOutcome::failed();
        }

        $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? $this->clock->now(), 'last_login_at' => $this->clock->now(), 'failed_login_attempts' => 0])->save();
        $this->audit->record('auth.login', $user, after: ['method' => 'phone_otp'], actor: new Actor(ActorType::Customer, $user->id, $user->public_id));

        return LoginOutcome::success($user);
    }

    private function createAccount(PhoneNumber $phone, string $locale): User
    {
        try {
            return Transactions::run(function () use ($phone, $locale): User {
                $user = new User;
                $user->fill(['phone' => $phone->e164(), 'locale' => $locale]);
                $user->forceFill(['account_type' => AccountType::Customer, 'status' => UserStatus::Active, 'phone_verified_at' => $this->clock->now()])->save();

                $this->outbox->record(new UserRegistered($user->id, $user->public_id, null, null, $phone->e164()));
                $this->audit->record('auth.registered', $user, after: ['method' => 'phone_otp'], actor: Actor::guest());

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return User::query()->where('phone', $phone->e164())->firstOrFail(); // concurrent first sign-in
        }
    }
}
