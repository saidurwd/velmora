<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\DTOs\CourierBooking;
use App\Modules\Shipping\DTOs\CourierCapabilities;
use App\Modules\Shipping\DTOs\CourierEvent;
use App\Modules\Shipping\DTOs\ShipmentRequest;
use App\Modules\Shipping\Exceptions\CourierException;
use Illuminate\Http\Request;

/**
 * One courier. Written only from the courier's saved official documentation (docs/vendor/<courier>/); the manual courier is always available.
 * An adapter must never log credentials and must send the courier only what a ShipmentRequest holds.
 */
interface CourierProvider
{
    public function name(): string;

    public function capabilities(): CourierCapabilities;

    /** @throws CourierException */
    public function createShipment(ShipmentRequest $request): CourierBooking;

    /** @throws CourierException */
    public function cancelShipment(string $consignmentId): void;

    /**
     * Every event the courier has for the parcel, oldest first.
     *
     * @return list<CourierEvent>
     *
     * @throws CourierException
     */
    public function track(string $consignmentId): array;

    /**
     * Checks that the request really came from the courier (as its documents describe) and reads the events in it.
     *
     * @return list<CourierEvent>
     *
     * @throws CourierException 'webhook_unauthenticated' when it did not
     */
    public function parseWebhook(Request $request): array;
}
