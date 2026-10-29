<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Mail\OrderPlacedMail;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Outbox\IdempotentConsumer;
use App\Modules\Platform\Support\Fmt;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Tells the customer their order was received: an SMS to the phone, and an email when they gave one. The outbox may deliver the
 * event more than once; each channel runs at most once per event, and a failure on one channel is retried without resending the other.
 */
final class SendOrderConfirmation
{
    public function __construct(private readonly SmsGateway $sms, private readonly IdempotentConsumer $consumer) {}

    public function handle(OrderPlaced $event): void
    {
        $order = Order::query()->with('items')->find($event->orderId);
        if ($order === null) {
            return;
        }

        $this->consumer->once('orders.confirmation_sms', $event, fn () => $this->sendSms($order));

        if ($order->contact_email !== null) {
            $this->consumer->once('orders.confirmation_email', $event, function () use ($order): void {
                Mail::to((string) $order->contact_email)->send(new OrderPlacedMail($order));
            });
        }
    }

    private function sendSms(Order $order): void
    {
        $previous = App::getLocale();
        App::setLocale($order->locale); // the message and its amounts in the language the order was placed in
        try {
            $text = __('orders::notifications.sms_placed', [
                'shop' => (string) config('app.name'), 'number' => $order->order_number,
                'total' => Fmt::money(Money::of($order->grand_total_minor, $order->currency)),
                'cod' => $order->payment_method_code === 'cod' ? __('orders::notifications.sms_cod') : '',
            ]);
        } finally {
            App::setLocale($previous);
        }

        $result = $this->sms->send(PhoneNumber::parse($order->contact_phone), $text, ['purpose' => 'order_placed']);
        if (! $result->accepted) {
            throw new RuntimeException('Order confirmation SMS was not accepted: '.($result->error ?? 'unknown')); // the outbox retries
        }
    }
}
