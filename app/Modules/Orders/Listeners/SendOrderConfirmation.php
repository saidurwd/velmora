<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderMessages;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Fmt;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

/**
 * Tells the customer their order was received: a text message to the phone, and an email when they gave an address. Both go through the notifications platform
 * (which records them, delivers, retries and shows failures to staff). The key is the order itself, so the outbox delivering the event twice records each message once.
 * A problem recording a message is thrown, so the outbox tries again.
 */
final class SendOrderConfirmation
{
    public function __construct(private readonly OrderMessages $messages) {}

    public function handle(OrderPlaced $event): void
    {
        $order = Order::query()->with('items')->find($event->orderId);
        if ($order === null) {
            return;
        }

        $previous = App::getLocale();
        App::setLocale($order->locale);   // amounts and words in the language the order was placed in
        try {
            $money = fn (int $minor): string => Fmt::money(Money::of($minor, $order->currency));
            $this->messages->send($order, $order->payment_method_code === 'cod' ? 'order.placed_cod.sms' : 'order.placed.sms', ['total' => $money($order->grand_total_minor)], 'order.placed.sms|'.$order->public_id);   // one key for both: an order has one text message

            if ($order->contact_email !== null) {
                $this->messages->send($order, 'order.placed.email', [
                    'name' => $order->contact_name, 'items' => $this->items($order, $money), 'summary' => $this->summary($order, $money),
                    'link' => URL::temporarySignedRoute('checkout.thanks', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => $order->locale, 'order' => $order->public_id]),
                ], 'order.placed.email|'.$order->public_id);
            }
        } finally {
            App::setLocale($previous);
        }
    }

    /** One line per item: "Cotton Panjabi × 2 — ৳ 1,300.00". */
    private function items(Order $order, callable $money): string
    {
        $lines = [];
        foreach ($order->items as $item) {
            $lines[] = $item->name($order->locale).' × '.$item->quantity.' — '.$money($item->line_total_minor);
        }

        return implode("\n", $lines);
    }

    /** Delivery, the cash-on-delivery fee when there is one, the total, and what to pay on delivery. */
    private function summary(Order $order, callable $money): string
    {
        $lines = [__('orders::checkout.delivery').': '.($order->shipping_minor === 0 ? __('orders::checkout.free') : $money($order->shipping_minor))];
        if ($order->cod_fee_minor > 0) {
            $lines[] = __('orders::checkout.cod_fee').': '.$money($order->cod_fee_minor);
        }
        $lines[] = __('orders::checkout.total').': '.$money($order->grand_total_minor);
        if ($order->payment_method_code === 'cod') {
            $lines[] = __('orders::checkout.cod_message', ['total' => $money($order->grand_total_minor)]);
        }

        return implode("\n", $lines);
    }
}
