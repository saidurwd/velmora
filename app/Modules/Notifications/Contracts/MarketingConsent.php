<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

/** Whether a customer has agreed to marketing messages on a channel. A module that sends marketing asks first, so people who said no leave no trace. */
interface MarketingConsent
{
    /** @param  'email'|'sms'  $channel */
    public function allows(int $userId, string $channel): bool;
}
