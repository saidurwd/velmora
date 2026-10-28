<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\DTOs\SmsResult;
use App\Modules\Platform\DTOs\PhoneNumber;

/**
 * Provider-independent SMS transport. Adapters: Log (dev), Fake (tests), SSL Wireless ISMS Plus (added when its
 * official API docs are saved under docs/vendor/sslwireless/).
 */
interface SmsGateway
{
    /** @param  array<string, scalar|null>  $meta  non-PII context for the delivery log (purpose, template key) */
    public function send(PhoneNumber $to, string $message, array $meta = []): SmsResult;
}
