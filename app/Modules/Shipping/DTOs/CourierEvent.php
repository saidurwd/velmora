<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use Carbon\CarbonImmutable;

/** One thing that happened to a parcel, in the courier's own words and time. Mapping to our statuses happens later, from a table. */
final readonly class CourierEvent
{
    /** @param  array<string, scalar|null>  $summary  what the courier sent, without secrets or the customer's details */
    public function __construct(
        public string $consignmentId,
        public string $providerStatus,
        public CarbonImmutable $occurredAt,
        public ?string $note = null,
        public array $summary = [],
    ) {}
}
