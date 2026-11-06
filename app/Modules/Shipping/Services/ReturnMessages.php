<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Shipping\Models\ReturnRequest;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a customer that their return was allowed or refused, by text message and by email where we have one, in the language the order was placed in. It runs after
 * the decision is saved and never undoes it: a problem recording a message is only logged (without the customer's details). The key is the return and the decision,
 * so a repeated call sends each message once.
 */
final class ReturnMessages
{
    public function __construct(private readonly NotificationSender $sender, private readonly OrderDirectory $orders) {}

    public function tell(ReturnRequest $return, string $what): void
    {
        $contact = $this->orders->contacts([$return->order_id])[$return->order_id] ?? null;
        if ($contact === null) {
            return;
        }
        $previous = App::getLocale();
        App::setLocale($contact['locale']);
        try {
            $variables = ['shop' => (string) config('app.name'), 'number' => $contact['number'], 'note' => (string) $return->decision_note, 'link' => route('account.returns', ['locale' => $contact['locale']])];
            foreach (['sms' => $contact['phone'], 'email' => $contact['email']] as $channel => $address) {
                if ($address === null || $address === '') {
                    continue;
                }
                try {
                    $this->sender->send("shipping.return_{$what}.{$channel}", new Recipient($channel === 'sms' ? $address : null, $channel === 'email' ? $address : null, $contact['locale'], $contact['user_id']), $variables, 'return-'.$what.'|'.$return->public_id, 'order:'.$contact['public_id']);
                } catch (Throwable $e) {
                    Log::warning('shipping.return_message_not_recorded', ['return' => $return->public_id, 'message' => $what, 'error' => $e::class]);
                }
            }
        } finally {
            App::setLocale($previous);
        }
    }
}
