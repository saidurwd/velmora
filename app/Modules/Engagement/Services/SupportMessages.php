<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a customer that the shop answered their ticket: ONE message, by email when we have an address, otherwise by text. It never carries what was said, only the
 * reference and a link. It runs after the answer is saved and never undoes it: a problem recording the message is only logged, without the customer's details.
 * The key is the ticket and the message, so telling twice about the same answer sends once.
 */
final class SupportMessages
{
    public function __construct(private readonly NotificationSender $sender, private readonly CustomerProfiles $profiles) {}

    public function replyReceived(\stdClass $ticket, int $messageId): void
    {
        $contact = $this->profiles->findByUserId((int) $ticket->user_id);
        if ($contact === null) {
            return;
        }
        $channel = ($contact->email ?? '') !== '' ? 'email' : (($contact->phone ?? '') !== '' ? 'sms' : null);
        if ($channel === null) {
            return;
        }

        try {
            $this->sender->send(
                "support.reply_received.{$channel}",
                new Recipient($channel === 'sms' ? $contact->phone : null, $channel === 'email' ? $contact->email : null, (string) $ticket->locale, (int) $ticket->user_id),
                ['shop' => (string) config('app.name'), 'reference' => (string) $ticket->reference, 'link' => route('account.support.show', ['locale' => $ticket->locale, 'ticket' => $ticket->public_id])],
                'support-reply|'.$ticket->public_id.'|'.$messageId,
                'ticket:'.$ticket->reference,
            );
        } catch (Throwable $e) {
            Log::warning('support.message_not_recorded', ['ticket' => $ticket->reference, 'error' => $e::class]);
        }
    }
}
