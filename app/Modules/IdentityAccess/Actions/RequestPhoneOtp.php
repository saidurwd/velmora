<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\OtpPurpose;
use App\Modules\IdentityAccess\Otp\OtpService;
use App\Modules\Platform\DTOs\PhoneNumber;

/**
 * Sends a sign-in/sign-up code. It does NOT look the number up: unknown numbers get a code too (phone sign-in
 * doubles as sign-up), so the response reveals nothing about who has an account.
 */
final class RequestPhoneOtp
{
    public function __construct(private readonly OtpService $otp) {}

    public function handle(PhoneNumber $phone, ?string $ip, string $locale, OtpPurpose $purpose = OtpPurpose::Login): OtpIssueStatus
    {
        return $this->otp->issue($phone, $purpose, $ip, $locale);
    }
}
