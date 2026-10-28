<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\DTOs\LoginOutcome;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verifies an email-or-phone + password for ONE account type and returns the user. It does not touch the session:
 * Web logs the result in via the guard, the API issues a token from it (same rules, same Action).
 *
 * Enumeration/timing: unknown accounts still pay for a password hash check; locked, disabled, wrong-password and
 * unknown all return the same Failed outcome.
 */
final class LoginWithPassword
{
    private const MAX_ATTEMPTS_PER_MINUTE = 5;

    private const LOCK_AFTER_FAILURES = 10;

    private const LOCK_MINUTES = 15;

    private static ?string $dummyHash = null;

    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    public function handle(string $identifier, string $password, ?string $ip, AccountType $type = AccountType::Customer): LoginOutcome
    {
        $identifier = BanglaText::input($identifier);
        $throttleKey = 'login:'.$type->value.':'.sha1(mb_strtolower($identifier).'|'.$ip);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS_PER_MINUTE)) {
            return LoginOutcome::throttled(RateLimiter::availableIn($throttleKey));
        }

        $user = $this->find($identifier, $type);
        $hash = $user->password ?? (self::$dummyHash ??= Hash::make('timing-equaliser-'.bin2hex(random_bytes(8))));
        $passwordOk = Hash::check($password, $hash) && $user?->password !== null;

        if ($user === null || ! $passwordOk || ! $user->isActive()) {
            RateLimiter::hit($throttleKey, 60);
            $this->recordFailure($user, $passwordOk, $identifier, $ip);

            return LoginOutcome::failed();
        }

        RateLimiter::clear($throttleKey);
        $this->markSuccess($user, $password);

        return LoginOutcome::success($user);
    }

    private function find(string $identifier, AccountType $type): ?User
    {
        $query = User::query()->where('account_type', $type->value);

        if (str_contains($identifier, '@')) {
            return $query->where('email', mb_strtolower($identifier))->first();
        }

        $phone = PhoneNumber::tryParse($identifier);

        return $phone === null ? null : $query->where('phone', $phone->e164())->first();
    }

    private function recordFailure(?User $user, bool $passwordOk, string $identifier, ?string $ip): void
    {
        $label = str_contains($identifier, '@') ? 'email' : 'phone';

        if ($user !== null && ! $passwordOk) {
            $attempts = $user->failed_login_attempts + 1;
            $lock = $attempts >= self::LOCK_AFTER_FAILURES;

            $user->forceFill([
                'failed_login_attempts' => $lock ? 0 : $attempts,
                'locked_until' => $lock ? $this->clock->now()->addMinutes(self::LOCK_MINUTES) : $user->locked_until,
            ])->save();

            if ($lock) {
                $this->audit->record('auth.account_locked', $user, after: ['minutes' => self::LOCK_MINUTES], actor: new Actor(ActorType::System, label: 'auth'));
            }
        }

        $this->audit->record('auth.login_failed', after: ['identifier_type' => $label, 'known' => $user !== null, 'ip' => $ip], actor: Actor::guest());
    }

    private function markSuccess(User $user, string $password): void
    {
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => $this->clock->now()]);

        if (Hash::needsRehash((string) $user->password)) {
            $user->password = $password; // 'hashed' cast re-hashes with the current algorithm/cost
        }

        $user->save();
        $this->audit->record('auth.login', $user, actor: new Actor($user->isStaff() ? ActorType::Staff : ActorType::Customer, $user->id, $user->public_id));
    }
}
