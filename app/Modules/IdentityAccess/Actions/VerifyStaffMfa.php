<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Enums\MfaResult;
use App\Modules\IdentityAccess\Mfa\RecoveryCodes;
use App\Modules\IdentityAccess\Mfa\TotpService;
use App\Modules\IdentityAccess\Models\StaffMfaCredential;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Second factor for staff: a TOTP code or a single-use recovery code. Five wrong tries in five minutes end the attempt
 * (the person must start again from the password step). A TOTP code can never be reused (step must increase).
 */
final class VerifyStaffMfa
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TotpService $totp, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    public function handle(User $staff, string $input): MfaResult
    {
        $key = 'mfa:'.$staff->id;
        $actor = new Actor(ActorType::Staff, $staff->id, $staff->public_id);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return MfaResult::Locked;
        }

        $result = DB::transaction(function () use ($staff, $input): MfaResult {
            /** @var StaffMfaCredential|null $credential */
            $credential = StaffMfaCredential::query()->where('user_id', $staff->id)->lockForUpdate()->first();

            if ($credential === null || ! $credential->isConfirmed()) {
                return MfaResult::Invalid;
            }

            if (RecoveryCodes::looksLikeRecoveryCode($input)) {
                return $this->useRecoveryCode($credential, $input);
            }

            $step = $this->totp->verify($credential->secret, $input, $credential->last_used_step);
            if ($step === null) {
                return MfaResult::Invalid;
            }

            $credential->forceFill(['last_used_step' => $step])->save();

            return MfaResult::Verified;
        });

        if ($result === MfaResult::Invalid) {
            RateLimiter::hit($key, 300);
            $this->audit->record('auth.mfa_failed', $staff, actor: $actor);

            return RateLimiter::remaining($key, self::MAX_ATTEMPTS) <= 0 ? MfaResult::Locked : MfaResult::Invalid;
        }

        RateLimiter::clear($key);
        $this->audit->record($result === MfaResult::RecoveryUsed ? 'auth.recovery_code_used' : 'auth.mfa_passed', $staff, actor: $actor);

        return $result;
    }

    private function useRecoveryCode(StaffMfaCredential $credential, string $input): MfaResult
    {
        $hash = RecoveryCodes::hash($input);
        $codes = $credential->recovery_codes ?? [];

        foreach ($codes as $i => $entry) {
            if ($entry['used_at'] === null && hash_equals($entry['hash'], $hash)) {
                $codes[$i]['used_at'] = $this->clock->now()->toIso8601String();
                $credential->forceFill(['recovery_codes' => $codes])->save(); // row is locked: single use even when raced

                return MfaResult::RecoveryUsed;
            }
        }

        return MfaResult::Invalid;
    }
}
