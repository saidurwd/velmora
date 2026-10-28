<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Enums\OtpPurpose;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Otp\OtpService;
use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Re-confirms the signed-in person: password if they have one, otherwise a code sent to their verified phone. */
final class ConfirmRecentAuth
{
    public function __construct(private readonly OtpService $otp) {}

    public function withPassword(User $user, string $password): bool
    {
        $key = 'reauth:'.$user->id;

        if ($user->password === null || RateLimiter::tooManyAttempts($key, 5)) {
            return false;
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, 300);

            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    public function withOtp(User $user, string $code): bool
    {
        $phone = $user->phone === null ? null : PhoneNumber::tryParse($user->phone);

        return $phone !== null && $user->phone_verified_at !== null && $this->otp->verify($phone, OtpPurpose::VerifyPhone, $code) === OtpVerifyResult::Verified;
    }

    public function requestOtp(User $user, ?string $ip, string $locale): void
    {
        $phone = $user->phone === null ? null : PhoneNumber::tryParse($user->phone);

        if ($phone !== null && $user->phone_verified_at !== null) {
            $this->otp->issue($phone, OtpPurpose::VerifyPhone, $ip, $locale);
        }
    }
}
