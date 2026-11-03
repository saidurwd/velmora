<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Listeners;

use App\Modules\Bookings\Actions\EndBookingsForRefundedOrder;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Platform\Outbox\IdempotentConsumer;

/** When an order is refunded in full, its upcoming bookings give their time back. A partial refund changes nothing here. Applied at most once however often the outbox delivers it. */
final class EndBookingsOfRefundedOrder
{
    public function __construct(private readonly EndBookingsForRefundedOrder $end, private readonly IdempotentConsumer $consumer) {}

    public function handle(OrderRefunded $event): void
    {
        if (! $event->full) {
            return;
        }
        $this->consumer->once('bookings.end_on_full_refund', $event, function () use ($event): void {
            $this->end->handle($event->orderId);
        });
    }
}
