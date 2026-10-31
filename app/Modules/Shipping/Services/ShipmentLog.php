<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Writes the parcel's history. Each row is kept exactly as written and never changed. */
final class ShipmentLog
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  array<string, scalar|null>  $summary */
    public function add(int $shipmentId, string $providerStatus, string $internalStatus, string $source, ?int $actorId = null, ?string $note = null, ?CarbonImmutable $occurredAt = null, array $summary = []): void
    {
        $now = $this->clock->now();
        DB::table('shipment_events')->insert([
            'shipment_id' => $shipmentId, 'provider_status' => $providerStatus, 'internal_status' => $internalStatus, 'occurred_at' => $occurredAt ?? $now, 'received_at' => $now,
            'source' => $source, 'actor_id' => $actorId, 'note' => $note === null ? null : mb_substr($note, 0, 255), 'summary' => $summary === [] ? null : json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }
}
