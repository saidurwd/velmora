<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Takes reserved units out of the warehouse (at shipment): on_hand and reserved both drop, one ledger row records it.
 * A reservation that already expired or was released can NOT be committed; committing twice is a harmless no-op.
 */
final class CommitReservation
{
    public function __construct(private readonly Clock $clock) {}

    /** @return bool true when this call committed it, false when it was already committed */
    public function handle(string $reservationPublicId, string $refType = 'order', ?string $refId = null, ?int $userId = null): bool
    {
        return Transactions::run(function () use ($reservationPublicId, $refType, $refId, $userId): bool {
            $row = StockReservation::query()->where('public_id', $reservationPublicId)->lockForUpdate()->first()
                ?? throw InventoryException::reservationNotActive('unknown');

            if ($row->status === 'committed') {
                return false;
            }

            if ($row->status !== 'active') {
                throw InventoryException::reservationNotActive($row->status);
            }

            $stamp = $this->clock->now()->format('Y-m-d H:i:s.u');
            $affected = DB::update(
                'UPDATE stock_levels SET on_hand = on_hand - ?, reserved = reserved - ?, updated_at = ? WHERE variant_id = ? AND location_id = ? AND reserved >= ? AND on_hand >= ?',
                [$row->quantity, $row->quantity, $stamp, $row->variant_id, $row->location_id, $row->quantity, $row->quantity],
            );

            if ($affected !== 1) {
                throw InventoryException::invariant("cannot commit reservation {$row->public_id}: level too low");
            }

            DB::table('stock_movements')->insert([
                'variant_id' => $row->variant_id, 'location_id' => $row->location_id, 'delta' => -$row->quantity, 'reason' => 'shipment',
                'ref_type' => $refType, 'ref_id' => $refId ?? $row->owner_ref, 'note' => null, 'created_by' => $userId, 'created_at' => $stamp,
            ]);

            $row->forceFill(['status' => 'committed'])->save();

            return true;
        });
    }
}
