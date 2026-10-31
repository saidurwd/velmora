<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** What a courier adapter can really do, written from its documentation and never assumed. */
final readonly class CourierCapabilities
{
    public function __construct(
        public bool $createsConsignment = false,   // we can book the parcel with the courier through its API
        public bool $cancel = false,
        public bool $track = false,                // we can ask for the parcel's events
        public bool $webhook = false,              // the courier tells us (authenticated as its documents describe)
        public bool $codHandling = false,          // the courier collects cash on delivery
        public bool $rateQuote = false,
        public bool $codSettlement = false,        // the courier reports what it collected and paid over
        public bool $sandbox = false,
    ) {}
}
