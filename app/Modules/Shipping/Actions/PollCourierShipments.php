<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Shipping\Exceptions\CourierException;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\CourierRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The safety net behind the courier's own messages: parcels still on their way are put to their courier, least recently asked first, so nothing is starved.
 * A courier that cannot be reached is skipped (and tried again next time); one failing courier never stops the others. Asking is safe to repeat.
 */
final class PollCourierShipments
{
    public function __construct(private readonly CourierRegistry $couriers, private readonly RecordCourierEvent $record, private readonly Clock $clock) {}

    /** @return array{asked: int, events: int, unreachable: int} */
    public function handle(?int $limit = null): array
    {
        $limit ??= (int) config('shipping.poll_batch');
        $result = ['asked' => 0, 'events' => 0, 'unreachable' => 0];

        // serves: unfinished parcels with a courier id, least recently asked first (index status, polled_at)
        $due = Shipment::query()->whereIn('status', ['handed_over', 'in_transit', 'out_for_delivery', 'failed', 'unknown'])->whereNotNull('courier_consignment_id')
            ->orderByRaw('polled_at IS NOT NULL')->orderBy('polled_at')->orderBy('id')->limit($limit)->get();

        foreach ($due as $shipment) {
            if (! $this->couriers->has($shipment->courier) || ! $this->couriers->for($shipment->courier)->capabilities()->track) {
                continue;
            }
            $result['asked']++;

            try {
                $events = $this->couriers->for($shipment->courier)->track((string) $shipment->courier_consignment_id); // outside any lock: the courier may be slow
            } catch (CourierException $e) {
                Log::warning('shipping.poll_failed', ['courier' => $shipment->courier, 'shipment' => $shipment->public_id, 'reason' => $e->reason]);
                $result['unreachable']++;
                $this->touch($shipment->id);

                continue;
            }
            foreach ($events as $event) {
                if ($this->record->handle($shipment->courier, $event, 'poll') === RecordCourierEvent::RECORDED) {
                    $result['events']++;
                }
            }
            $this->touch($shipment->id);
        }

        return $result;
    }

    private function touch(int $shipmentId): void
    {
        DB::table('shipments')->where('id', $shipmentId)->update(['polled_at' => $this->clock->now()]);
    }
}
