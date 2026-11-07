<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

/** Units of one product sitting in one place, and for how many days (since one last left in a parcel, or since the stock first arrived). */
final readonly class AgedStockLine
{
    public function __construct(public int $variantId, public string $location, public int $units, public int $days) {}
}
