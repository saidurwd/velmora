<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\DTOs\CourierDeliveryRow;
use Carbon\CarbonImmutable;

/** Read-only, for the Reporting module. */
interface DeliveryFigures
{
    /**
     * Parcels handed to a courier from `$from` (included) to `$to` (excluded), added up per courier. Parcels never handed over (still being prepared, or cancelled) are left out.
     * The cash-on-delivery value counts only parcels in `$currency`.
     *
     * @return list<CourierDeliveryRow>
     */
    public function handedOverBetween(CarbonImmutable $from, CarbonImmutable $to, string $currency): array;
}
