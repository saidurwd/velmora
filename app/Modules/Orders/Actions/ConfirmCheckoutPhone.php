<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\IdentityAccess\Contracts\PhoneConfirmation;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\Platform\DTOs\PhoneNumber;

/** Sends and checks the SMS code a large cash-on-delivery order needs. The result of a check is only ever "yes" or "no". */
final class ConfirmCheckoutPhone
{
    public function __construct(private readonly PhoneConfirmation $confirmation) {}

    public function send(string $phoneE164, ?string $ip, string $locale): OtpIssueStatus
    {
        return $this->confirmation->send(PhoneNumber::parse($phoneE164), $ip, $locale);
    }

    public function verify(string $phoneE164, string $code): bool
    {
        return $this->confirmation->check(PhoneNumber::parse($phoneE164), $code) === OtpVerifyResult::Verified;
    }
}
