<?php

declare(strict_types=1);

namespace App\Modules\Orders\Mail;

use App\Modules\Orders\Models\Order;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Fmt;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/** The receipt-style email. Rendered in the language the order was placed in. */
class OrderPlacedMail extends Mailable
{
    public function __construct(public readonly Order $order)
    {
        $this->locale($order->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('orders::notifications.email_subject', ['number' => $this->order->order_number]));
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing('items');

        return new Content(view: 'orders::mail.placed', with: [
            'order' => $order,
            'money' => fn (int $minor): string => Fmt::money(Money::of($minor, $order->currency)),
            'link' => URL::temporarySignedRoute('checkout.thanks', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => $order->locale, 'order' => $order->public_id]),
        ]);
    }
}
