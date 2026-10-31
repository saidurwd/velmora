<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Raises something a person must look at, once per problem (UNIQUE type + reference). Returns true when it was new. */
final class ShipmentIssues
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  array<string, mixed>  $details */
    public function raise(string $type, ?int $shipmentId, string $reference, array $details): bool
    {
        $now = $this->clock->now();

        return DB::table('shipment_exceptions')->insertOrIgnore([
            'public_id' => strtolower((string) Str::ulid()), 'type' => $type, 'shipment_id' => $shipmentId, 'reference' => mb_substr($reference, 0, 120),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
        ]) === 1;
    }
}
