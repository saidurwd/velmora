<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** Gives reserved stock back. Idempotent: releasing something already released/committed/expired changes nothing. */
final class ReleaseReservation
{
    public function __construct(private readonly Clock $clock) {}

    /** @return bool true when this call released it, false when there was nothing left to release */
    public function handle(int|string $reservation, string $as = 'released'): bool
    {
        return Transactions::run(function () use ($reservation, $as): bool {
            $query = StockReservation::query()->lockForUpdate();
            $row = is_int($reservation) ? $query->find($reservation) : $query->where('public_id', $reservation)->first();

            if ($row === null || $row->status !== 'active') {
                return false;
            }

            $stamp = $this->clock->now()->format('Y-m-d H:i:s.u');
            $affected = DB::update(
                'UPDATE stock_levels SET reserved = reserved - ?, updated_at = ? WHERE variant_id = ? AND location_id = ? AND reserved >= ?',
                [$row->quantity, $stamp, $row->variant_id, $row->location_id, $row->quantity],
            );

            if ($affected !== 1) {
                throw InventoryException::invariant("reservation {$row->public_id} holds more than the level shows reserved");
            }

            $row->forceFill(['status' => $as])->save();

            return true;
        });
    }

    /** Releases every active reservation an order/cart holds (cancellation, abandoned cart). */
    public function forOwner(string $ownerType, string $ownerRef): int
    {
        $count = 0;

        foreach (StockReservation::query()->where('owner_type', $ownerType)->where('owner_ref', $ownerRef)->where('status', 'active')->orderBy('id')->pluck('id') as $id) {
            $count += $this->handle((int) $id) ? 1 : 0;
        }

        return $count;
    }
}
