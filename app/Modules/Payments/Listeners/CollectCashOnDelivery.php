<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Actions\RecordCodCollection;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\IdempotentConsumer;
use App\Modules\Shipping\Events\ShipmentDelivered;
use Carbon\CarbonImmutable;

/**
 * A parcel that collects cash was delivered: its cash is recorded, once. Each announcement is applied at most once (IdempotentConsumer), and the database
 * allows one collection per parcel besides, so a repeated or concurrent announcement can never count the cash twice.
 */
final class CollectCashOnDelivery
{
    public function __construct(private readonly RecordCodCollection $collect, private readonly IdempotentConsumer $consumer) {}

    public function handle(ShipmentDelivered $e): void
    {
        $this->consumer->once('payments.cash_on_delivery', $e, function () use ($e): void {
            $this->collect->forShipment(Actor::system('delivery'), $e->orderPublicId, $e->shipmentId, $e->codAmountMinor, CarbonImmutable::parse($e->occurredAt, 'UTC'));
        });
    }
}
