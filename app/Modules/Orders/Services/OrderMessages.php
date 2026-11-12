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
    /** @var array<string, string> the text message an order sends => the push message that goes with it, to the app of a customer with an account (a guest has no app) */
    private const PUSH_FOR = [
        'order.placed.sms' => 'order.placed.push', 'order.placed_cod.sms' => 'order.placed.push', 'order.shipped.sms' => 'order.shipped.push', 'order.delivered.sms' => 'order.delivered.push',
        'order.delivery_failed.sms' => 'order.delivery_failed.push', 'order.delivery_failed_final.sms' => 'order.delivery_failed.push', 'order.cancelled_stale.sms' => 'order.cancelled.push', 'order.refunded.sms' => 'order.refunded.push',
    ];

    public function __construct(private readonly NotificationSender $sender) {}

    /**
     * Records the message; a problem recording it is thrown (the caller's own retry, such as the outbox, tries again).
     *
     * @param  array<string, scalar|null>  $variables  the order's number and the shop's name are added
     * @param  string  $key  the same for the same event every time, different for a different event
     */
    public function send(Order $order, string $code, array $variables, string $key): void
    {
        if (str_ends_with($code, '.push') && $order->user_id === null) {
            return;   // a guest has no app to push to
        }
        $previous = App::getLocale();
        App::setLocale($order->locale);
        try {
            $recipient = new Recipient($order->contact_phone, $order->contact_email, $order->locale, $order->user_id);
            $values = $variables + ['shop' => (string) config('app.name'), 'number' => $order->order_number];
            $this->sender->send($code, $recipient, $values, $key, 'order:'.$order->public_id);
            $push = $order->user_id === null ? null : (self::PUSH_FOR[$code] ?? null);
            if ($push !== null) {
                $this->sender->send($push, $recipient, array_diff_key($values, ['amount' => 1, 'total' => 1]), $key, 'order:'.$order->public_id);   // the same event, the same key: announced twice, pushed once. No amounts travel in a push.
            }
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
