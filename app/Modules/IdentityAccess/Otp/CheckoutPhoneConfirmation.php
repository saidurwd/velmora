<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Otp;

use App\Modules\IdentityAccess\Contracts\PhoneConfirmation;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\OtpPurpose;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\Platform\DTOs\PhoneNumber;

final class CheckoutPhoneConfirmation implements PhoneConfirmation
{
    public function __construct(private readonly OtpService $otp) {}

    public function send(PhoneNumber $phone, ?string $ip, ?string $locale): OtpIssueStatus
    {
        return $this->otp->issue($phone, OtpPurpose::CheckoutCod, $ip, $locale);
    }

    public function check(PhoneNumber $phone, string $code): OtpVerifyResult
    {
        return $this->otp->verify($phone, OtpPurpose::CheckoutCod, $code);
    }
}
