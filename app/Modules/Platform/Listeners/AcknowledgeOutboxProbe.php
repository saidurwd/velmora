<?php

declare(strict_types=1);

namespace App\Modules\Platform\Listeners;

use App\Modules\Platform\Events\OutboxProbe;
use App\Modules\Platform\Outbox\IdempotentConsumer;

/** The "effect" of a probe is its consumed_events row: exactly one per message, however many deliveries. */
final class AcknowledgeOutboxProbe
{
    public function __construct(private readonly IdempotentConsumer $consumer) {}

    public function handle(OutboxProbe $event): void
    {
        $this->consumer->once('platform.probe', $event, function (): void {});
    }
}
