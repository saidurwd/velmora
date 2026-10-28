<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\DTOs\SmsResult;
use App\Modules\Platform\DTOs\PhoneNumber;
use RuntimeException;

/** Bound when SMS_DRIVER names a provider whose adapter does not exist yet: fail loudly, never silently drop OTPs. */
final class UnconfiguredSmsGateway implements SmsGateway
{
    public function __construct(private readonly string $driver) {}

    public function send(PhoneNumber $to, string $message, array $meta = []): SmsResult
    {
        throw new RuntimeException("SMS driver [{$this->driver}] has no adapter. Save the provider's official API docs under docs/vendor/ and implement it (Phase 2 follow-up).");
    }
}
