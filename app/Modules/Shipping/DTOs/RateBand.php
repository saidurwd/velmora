<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** "Up to this many grams costs this much"; no weight = every heavier parcel too (the open band). */
final readonly class RateBand
{
    public function __construct(public ?int $maxWeightG, public int $priceMinor) {}
}
