<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\DTOs\SmsResult;
use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Development transport: writes the message (including OTP codes) to the log. Refuses to run in production. */
final class LogSmsGateway implements SmsGateway
{
    public function send(PhoneNumber $to, string $message, array $meta = []): SmsResult
    {
        if (app()->environment('production', 'staging')) {
            throw new RuntimeException('LogSmsGateway must not be used in production/staging; set SMS_DRIVER.');
        }

        Log::info('[sms:log] '.$to->masked().' '.$message, $meta);

        return new SmsResult(true, 'log-'.bin2hex(random_bytes(4)));
    }
}
