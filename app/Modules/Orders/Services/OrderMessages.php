<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orders' way of telling a customer something: it hands the message to the notifications platform, addressed to the order's phone and email, in the language
 * the order was placed in (values such as amounts and dates are made in that language). The platform records, delivers, retries and shows failures to staff.
 */
final class OrderMessages
{
    public function __construct(private readonly NotificationSender $sender) {}

    /**
     * Records the message; a problem recording it is thrown (the caller's own retry, such as the outbox, tries again).
     *
     * @param  array<string, scalar|null>  $variables  the order's number and the shop's name are added
     * @param  string  $key  the same for the same event every time, different for a different event
     */
    public function send(Order $order, string $code, array $variables, string $key): void
    {
        $previous = App::getLocale();
        App::setLocale($order->locale);
        try {
            $this->sender->send($code, new Recipient($order->contact_phone, $order->contact_email, $order->locale, $order->user_id), $variables + ['shop' => (string) config('app.name'), 'number' => $order->order_number], $key, 'order:'.$order->public_id);
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Same, for a message that must never undo what it announces: a problem recording it is only written to the log (without the customer's details).
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function tryToSend(Order $order, string $code, array $variables, string $key): void
    {
        try {
            $this->send($order, $code, $variables, $key);
        } catch (Throwable $e) {
            Log::warning('orders.message_not_recorded', ['order' => $order->order_number, 'message' => $code, 'error' => $e::class]);
        }
    }
}
