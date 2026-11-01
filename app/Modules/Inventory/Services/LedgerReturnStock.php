<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\ReturnStock;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

final class LedgerReturnStock implements ReturnStock
{
    public function __construct(private readonly Clock $clock) {}

    public function receive(string $returnRef, int $variantId, int $quantity, string $outcome, ?string $shipmentRef, ?int $actorId): void
    {
        if ($quantity < 1 || ! in_array($outcome, ['restock', 'quarantine', 'write_off'], true)) {
            throw InventoryException::invalidQuantity();
        }

        $locationId = $outcome === 'restock' ? $this->origin($variantId, $shipmentRef) : $this->location('quarantine');
        $this->move($returnRef, $variantId, $locationId, $quantity, 'return_restock', $actorId, $outcome === 'restock' ? null : ($outcome === 'quarantine' ? 'Kept for inspection' : 'Came back, to be destroyed'));
        if ($outcome === 'write_off') {
            $this->move($returnRef, $variantId, $locationId, -$quantity, 'write_off', $actorId, 'Written off after return');
        }
    }

    private function move(string $returnRef, int $variantId, int $locationId, int $delta, string $reason, ?int $actorId, ?string $note): void
    {
        $stamp = $this->clock->now()->format('Y-m-d H:i:s.u');
        // the level row is made if this place never held the product; the count and the ledger row change together
        if ($delta > 0) {
            DB::statement('INSERT INTO stock_levels (variant_id, location_id, on_hand, reserved, updated_at) VALUES (?, ?, ?, 0, ?) ON DUPLICATE KEY UPDATE on_hand = on_hand + VALUES(on_hand), updated_at = VALUES(updated_at)', [$variantId, $locationId, $delta, $stamp]);
        } elseif (DB::update('UPDATE stock_levels SET on_hand = on_hand + ?, updated_at = ? WHERE variant_id = ? AND location_id = ? AND on_hand + ? >= reserved AND on_hand + ? >= 0', [$delta, $stamp, $variantId, $locationId, $delta, $delta]) !== 1) {
            throw InventoryException::invariant("cannot take {$delta} of variant {$variantId} out: not enough there");
        }
        DB::table('stock_movements')->insert(['variant_id' => $variantId, 'location_id' => $locationId, 'delta' => $delta, 'reason' => $reason, 'ref_type' => 'return', 'ref_id' => $returnRef, 'note' => $note, 'created_by' => $actorId, 'created_at' => $stamp]);
    }

    /** Where the goods left from: the place of the parcel's stock movement, else the main warehouse. */
    private function origin(int $variantId, ?string $shipmentRef): int
    {
        if ($shipmentRef !== null) {
            $from = DB::table('stock_movements')->where('ref_type', 'shipment')->where('ref_id', $shipmentRef)->where('reason', 'shipment')->where('variant_id', $variantId)->orderBy('id')->value('location_id');
            if ($from !== null) {
                return (int) $from;
            }
        }

        return $this->location('main');
    }

    private function location(string $code): int
    {
        return (int) (DB::table('stock_locations')->where('code', $code)->value('id') ?? throw InventoryException::unknownLocation($code));
    }
}
