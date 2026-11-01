<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The signals that tell a person a delivery needs a human, read from our own records. Thresholds are plain numbers in config/shipping.php. `breached` is what
 * the scheduled check logs (once an hour per signal) and what the parcel screens show as a banner.
 */
final class ShippingHealth
{
    public const SIGNALS = ['unknown_courier_words', 'stuck_parcels', 'unhanded_parcels', 'problems_waiting', 'returns_waiting'];

    public function __construct(private readonly Clock $clock) {}

    /** @return array<string, array{value: int, threshold: int, breached: bool}> */
    public function snapshot(): array
    {
        $now = $this->clock->now();
        $cfg = (array) config('shipping.alerts');
        $format = 'Y-m-d H:i:s.u';

        $signals = [
            // a courier said something we cannot read, or named a parcel we do not have: someone must teach the status map or look (index on status)
            'unknown_courier_words' => DB::table('shipment_exceptions')->where('status', 'open')->whereIn('type', ['unknown_status', 'unknown_consignment'])->count(),
            // on its way, but nothing heard from the courier for stuck_days (the last event per parcel; index shipment_id, occurred_at)
            'stuck_parcels' => DB::table('shipments as s')->whereIn('s.status', ['handed_over', 'in_transit', 'out_for_delivery', 'failed', 'unknown'])
                ->whereRaw('COALESCE((SELECT MAX(e.occurred_at) FROM shipment_events e WHERE e.shipment_id = s.id), s.created_at) <= ?', [$now->subDays((int) config('shipping.stuck_days'))->format($format)])->count(),
            // made, stock already taken out, but never handed to the courier
            'unhanded_parcels' => DB::table('shipments')->where('status', 'created')->where('created_at', '<=', $now->subHours((int) $cfg['unhanded_hours'])->format($format))->count(),
            // anything in the delivery-problems queue left open too long
            'problems_waiting' => DB::table('shipment_exceptions')->where('status', 'open')->where('created_at', '<=', $now->subHours((int) $cfg['problem_grace_hours'])->format($format))->count(),
            // customers waiting for a decision on a return
            'returns_waiting' => DB::table('return_requests')->where('status', 'requested')->where('created_at', '<=', $now->subHours((int) $cfg['returns_grace_hours'])->format($format))->count(),
        ];

        $out = [];
        foreach ($signals as $name => $value) {
            $out[$name] = ['value' => (int) $value, 'threshold' => 1, 'breached' => $value >= 1];
        }

        return $out;
    }

    /** @return array<string, array{value: int, threshold: int, breached: bool}> only the signals that are breached now */
    public function breached(): array
    {
        return array_filter($this->snapshot(), fn (array $s): bool => $s['breached']);
    }
}
