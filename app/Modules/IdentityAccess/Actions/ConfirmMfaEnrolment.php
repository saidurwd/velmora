<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

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

final class ConfirmMfaEnrolment
{
    public function __construct(private readonly TotpService $totp, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @return list<string>|null the recovery codes (shown once) or null when the code was wrong */
    public function handle(User $staff, string $code): ?array
    {
        $key = 'mfa-enrol:'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return null;
        }

        return DB::transaction(function () use ($staff, $code, $key): ?array {
            /** @var StaffMfaCredential|null $credential */
            $credential = StaffMfaCredential::query()->where('user_id', $staff->id)->lockForUpdate()->first();

            if ($credential === null || $credential->isConfirmed()) {
                return null;
            }

            $step = $this->totp->verify($credential->secret, $code, null);
            if ($step === null) {
                RateLimiter::hit($key, 300);

                return null;
            }

            $codes = RecoveryCodes::generate();
            $credential->forceFill([
                'confirmed_at' => $this->clock->now(),
                'last_used_step' => $step,
                'recovery_codes' => array_map(fn (string $c) => ['hash' => RecoveryCodes::hash($c), 'used_at' => null], $codes),
            ])->save();

            RateLimiter::clear($key);
            $this->audit->record('auth.mfa_enrolled', $staff, actor: new Actor(ActorType::Staff, $staff->id, $staff->public_id));

            return $codes;
        });
    }
}
