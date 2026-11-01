<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** The cash recorded for one delivered parcel. */
final readonly class CodCashLine
{
    public function __construct(public string $collectionPublicId, public int $shipmentId, public int $amountMinor, public bool $remitted) {}
}
