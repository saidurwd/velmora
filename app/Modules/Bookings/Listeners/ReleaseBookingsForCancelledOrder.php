<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Listeners;

use App\Modules\Bookings\Actions\ReleaseBookingsForOrder;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Platform\Outbox\IdempotentConsumer;

/** When an order is cancelled, give back its held times. Applied at most once however often the outbox delivers it. */
final class ReleaseBookingsForCancelledOrder
{
    public function __construct(private readonly ReleaseBookingsForOrder $release, private readonly IdempotentConsumer $consumer) {}

    public function handle(OrderCancelled $event): void
    {
        $this->consumer->once('bookings.release_on_cancel', $event, function () use ($event): void {
            $this->release->handle($event->orderId);
        });
    }
}
