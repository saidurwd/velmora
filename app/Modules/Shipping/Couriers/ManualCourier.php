<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Couriers;

use App\Modules\Shipping\Contracts\CourierProvider;
use App\Modules\Shipping\DTOs\CourierBooking;
use App\Modules\Shipping\DTOs\CourierCapabilities;
use App\Modules\Shipping\DTOs\ShipmentRequest;
use App\Modules\Shipping\Exceptions\CourierException;
use Illuminate\Http\Request;

/**
 * Staff hand the parcel to any courier (or deliver it themselves) and enter the tracking code and status by hand. It talks to nobody, so it
 * works when every courier API is down, and nothing is lost: the shipment and its history are kept exactly as for an automatic courier.
 */
final class ManualCourier implements CourierProvider
{
    public const NAME = 'manual';

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): CourierCapabilities
    {
        return new CourierCapabilities(codHandling: true);   // staff or the rider collect the cash; nothing else is automatic
    }

    public function createShipment(ShipmentRequest $request): CourierBooking
    {
        return new CourierBooking;
    }

    public function cancelShipment(string $consignmentId): void
    {
        // nothing to tell: there is no courier system behind a manual shipment
    }

    public function track(string $consignmentId): array
    {
        return [];
    }

    public function parseWebhook(Request $request): array
    {
        throw new CourierException('not_supported');
    }
}
