<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Listeners;

use App\Modules\DigitalProducts\Actions\GrantEntitlements;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Platform\Outbox\IdempotentConsumer;

/** When an order is paid, make the rights to download. The outbox may deliver the event more than once; it is applied at most once (and the database would refuse a second right anyway). */
final class GrantEntitlementsForPaidOrder
{
    public function __construct(private readonly GrantEntitlements $grant, private readonly IdempotentConsumer $consumer) {}

    public function handle(OrderPaid $event): void
    {
        $this->consumer->once('digital.grant_entitlements', $event, function () use ($event): void {
            $this->grant->handle($event->orderId);
        });
    }
}
