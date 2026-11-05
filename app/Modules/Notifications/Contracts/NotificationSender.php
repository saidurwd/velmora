<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\DTOs\Recipient;

/**
 * The one way the rest of the shop sends a message to a person. The caller names the template (a code such as `order.placed.sms`), who it is for, the values
 * for the template's variables, and a key that is the same every time the same event is announced (so a repeat never sends twice). The message is recorded at once
 * and delivered by a queued job, retried with a growing wait, and visible to staff if it cannot be delivered.
 */
interface NotificationSender
{
    /**
     * @param  array<string, scalar|null>  $variables
     * @return string the public id of the delivery record (the existing one when this key was used before)
     */
    public function send(string $templateCode, Recipient $to, array $variables, string $idempotencyKey, ?string $related = null): string;
}
