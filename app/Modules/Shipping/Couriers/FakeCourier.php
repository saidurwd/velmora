<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Couriers;

use App\Modules\Shipping\Contracts\CourierProvider;
use App\Modules\Shipping\DTOs\CourierBooking;
use App\Modules\Shipping\DTOs\CourierCapabilities;
use App\Modules\Shipping\DTOs\CourierEvent;
use App\Modules\Shipping\DTOs\ShipmentRequest;
use App\Modules\Shipping\Exceptions\CourierException;
use Illuminate\Http\Request;

/** A courier that lives in memory, for tests and local work. Never registered in production. Tells tests what it was asked and can be made to fail. */
final class FakeCourier implements CourierProvider
{
    public const NAME = 'fake';

    /** @var list<ShipmentRequest> */
    public array $booked = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var array<string, list<CourierEvent>> consignment id => events the courier "has" */
    public array $events = [];

    /** @var array<string, string> method => reason: the next call to that method fails once */
    private array $failures = [];

    private int $counter = 0;

    public function failNext(string $method, string $reason = 'courier_unavailable'): self
    {
        $this->failures[$method] = $reason;

        return $this;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): CourierCapabilities
    {
        return new CourierCapabilities(createsConsignment: true, cancel: true, track: true, webhook: true, codHandling: true, sandbox: true);
    }

    public function createShipment(ShipmentRequest $request): CourierBooking
    {
        $this->maybeFail('create');
        $this->booked[] = $request;
        $id = 'FAKE-'.(++$this->counter).'-'.substr($request->shipmentPublicId, -6);

        return new CourierBooking($id, 'TRK'.$id, 'https://courier.example/track/'.$id);
    }

    public function cancelShipment(string $consignmentId): void
    {
        $this->maybeFail('cancel');
        $this->cancelled[] = $consignmentId;
    }

    public function track(string $consignmentId): array
    {
        $this->maybeFail('track');

        return $this->events[$consignmentId] ?? [];
    }

    public function parseWebhook(Request $request): array
    {
        throw new CourierException('not_supported');
    }

    private function maybeFail(string $method): void
    {
        if (isset($this->failures[$method])) {
            $reason = $this->failures[$method];
            unset($this->failures[$method]);

            throw new CourierException($reason);
        }
    }
}
