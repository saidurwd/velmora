<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\Platform\DTOs\PhoneNumber;

/**
 * "Is this really the person holding this phone?" for checkout, by one-time SMS code. The code is single use, expires, has an
 * attempt cap and sends are rate-limited (cooldown, per number, per IP, daily cap): all of that lives in the OTP service.
 * Callers show one generic message for every result other than Verified.
 */
interface PhoneConfirmation
{
    public function send(PhoneNumber $phone, ?string $ip, ?string $locale): OtpIssueStatus;

    public function check(PhoneNumber $phone, string $code): OtpVerifyResult;
}
