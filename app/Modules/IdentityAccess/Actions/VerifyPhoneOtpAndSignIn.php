<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\Cms\Contracts\PolicyAcceptances;
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
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

/** Verifies a code and returns the customer to sign in, creating the account on first use. */
final class VerifyPhoneOtpAndSignIn
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly PolicyAcceptances $policies,
    ) {}

    /** @param  list<int>  $policyReleaseIds  what the person accepted on the phone page; recorded only when this first sign-in creates the account */
    public function handle(PhoneNumber $phone, string $code, string $locale, array $policyReleaseIds = []): LoginOutcome
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

            $user = $this->createAccount($phone, $locale, $policyReleaseIds);
        }

        if (! $user->isActive()) {
            return LoginOutcome::failed();
        }

        $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? $this->clock->now(), 'last_login_at' => $this->clock->now(), 'failed_login_attempts' => 0])->save();
        $this->audit->record('auth.login', $user, after: ['method' => 'phone_otp'], actor: new Actor(ActorType::Customer, $user->id, $user->public_id));

        return LoginOutcome::success($user);
    }

    /** @param  list<int>  $policyReleaseIds */
    private function createAccount(PhoneNumber $phone, string $locale, array $policyReleaseIds): User
    {
        try {
            return Transactions::run(function () use ($phone, $locale, $policyReleaseIds): User {
                $user = new User;
                $user->fill(['phone' => $phone->e164(), 'locale' => $locale]);
                $user->forceFill(['account_type' => AccountType::Customer, 'status' => UserStatus::Active, 'phone_verified_at' => $this->clock->now()])->save();

                $this->outbox->record(new UserRegistered($user->id, $user->public_id, null, null, $phone->e164()));
                $this->audit->record('auth.registered', $user, after: ['method' => 'phone_otp'], actor: Actor::guest());
                $this->recordAcceptance($user->id, $policyReleaseIds);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return User::query()->where('phone', $phone->e164())->firstOrFail(); // concurrent first sign-in
        }
    }

    /**
     * A policy released between the phone page and the code is not a reason to refuse sign-in: the account starts unaccepted and the account page asks.
     *
     * @param  list<int>  $policyReleaseIds
     */
    private function recordAcceptance(int $userId, array $policyReleaseIds): void
    {
        if ($policyReleaseIds === []) {
            return;
        }

        try {
            $this->policies->accept($userId, null, 'signup', $policyReleaseIds);
        } catch (DomainException) {
            // nothing was written; the customer is asked again from the account page and at checkout
        }
    }
}
