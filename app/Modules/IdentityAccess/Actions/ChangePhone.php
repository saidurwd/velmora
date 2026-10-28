<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\OtpPurpose;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Otp\OtpService;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;

/** A new number must prove it receives SMS: a code is sent to it and must be entered before the swap. */
final class ChangePhone
{
    public function __construct(private readonly OtpService $otp, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    public function request(PhoneNumber $new, ?string $ip, string $locale): OtpIssueStatus
    {
        return $this->otp->issue($new, OtpPurpose::ChangePhone, $ip, $locale);
    }

    public function confirm(User $user, PhoneNumber $new, string $code): ChangePhoneResult
    {
        if ($this->otp->verify($new, OtpPurpose::ChangePhone, $code) !== OtpVerifyResult::Verified) {
            return ChangePhoneResult::InvalidCode;
        }

        if (User::query()->where('phone', $new->e164())->whereKeyNot($user->id)->exists()) {
            return ChangePhoneResult::AlreadyUsed;
        }

        $old = $user->phone;
        $user->forceFill(['phone' => $new->e164(), 'phone_verified_at' => $this->clock->now()])->save();
        $this->audit->record('auth.phone_changed', $user, ['phone' => $old], ['phone' => $new->e164()], new Actor(ActorType::Customer, $user->id, $user->public_id));

        return ChangePhoneResult::Changed;
    }
}
