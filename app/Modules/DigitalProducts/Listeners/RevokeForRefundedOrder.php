<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Listeners;

use App\Modules\DigitalProducts\Actions\RevokeEntitlements;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Platform\Outbox\IdempotentConsumer;

/**
 * When all of an order's money is given back, the downloads and keys of that order go with it, as each product's refund policy says. A partial refund changes
 * nothing here: staff decide. The outbox may deliver the event more than once; revoking twice is harmless anyway.
 */
final class RevokeForRefundedOrder
{
    public function __construct(private readonly RevokeEntitlements $revoke, private readonly IdempotentConsumer $consumer) {}

    public function handle(OrderRefunded $event): void
    {
        if (! $event->full) {
            return;
        }

        $this->consumer->once('digital.revoke_refunded_order', $event, function () use ($event): void {
            $this->revoke->forRefundedOrder($event->orderId);
        });
    }
}
