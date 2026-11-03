<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Listeners;

use App\Modules\Bookings\Actions\ConfirmBookingsForOrder;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Platform\Outbox\IdempotentConsumer;

/** When an order is paid, confirm its booked times. The outbox may deliver the event more than once; it is applied at most once. */
final class ConfirmBookingsForPaidOrder
{
    public function __construct(private readonly ConfirmBookingsForOrder $confirm, private readonly IdempotentConsumer $consumer) {}

    public function handle(OrderPaid $event): void
    {
        $this->consumer->once('bookings.confirm_on_paid', $event, function () use ($event): void {
            $this->confirm->handle($event->orderId);
        });
    }
}
