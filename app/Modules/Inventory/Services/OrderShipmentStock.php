<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\ShipmentStock;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrderShipmentStock implements ShipmentStock
{
    public function __construct(private readonly Clock $clock) {}

    public function commit(string $orderRef, array $lines, string $shipmentRef, ?int $actorId): void
    {
        ksort($lines); // the same order everywhere, so concurrent shipments never lock rows in a cycle

        Transactions::run(function () use ($orderRef, $lines, $shipmentRef, $actorId): void {
            $stamp = $this->clock->now()->format('Y-m-d H:i:s.u');

            foreach ($lines as $variantId => $quantity) {
                $remaining = (int) $quantity;
                if ($remaining < 1) {
                    throw InventoryException::invalidQuantity();
                }

                $held = DB::table('stock_reservations')->where('owner_type', 'order')->where('owner_ref', $orderRef)->where('variant_id', $variantId)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
                if ((int) $held->sum('quantity') < $remaining) {
                    throw InventoryException::invariant("order {$orderRef} does not hold {$remaining} of variant {$variantId}");
                }

                foreach ($held as $reservation) {
                    if ($remaining === 0) {
                        break;
                    }
                    $take = min($remaining, (int) $reservation->quantity);

                    $affected = DB::update(
                        'UPDATE stock_levels SET on_hand = on_hand - ?, reserved = reserved - ?, updated_at = ? WHERE variant_id = ? AND location_id = ? AND reserved >= ? AND on_hand >= ?',
                        [$take, $take, $stamp, $variantId, $reservation->location_id, $take, $take],
                    );
                    if ($affected !== 1) {
                        throw InventoryException::invariant("cannot ship {$take} of variant {$variantId}: level too low");
                    }

                    DB::table('stock_movements')->insert([
                        'variant_id' => $variantId, 'location_id' => $reservation->location_id, 'delta' => -$take, 'reason' => 'shipment',
                        'ref_type' => 'shipment', 'ref_id' => $shipmentRef, 'note' => null, 'created_by' => $actorId, 'created_at' => $stamp,
                    ]);

                    if ($take === (int) $reservation->quantity) {
                        DB::table('stock_reservations')->where('id', $reservation->id)->update(['status' => 'committed', 'updated_at' => $stamp]);
                    } else {
                        // part of the hold leaves with this parcel: the rest stays held, and the shipped part is kept as its own committed row
                        DB::table('stock_reservations')->where('id', $reservation->id)->update(['quantity' => $reservation->quantity - $take, 'updated_at' => $stamp]);
                        DB::table('stock_reservations')->insert([
                            'public_id' => strtolower((string) Str::ulid()), 'variant_id' => $variantId, 'location_id' => $reservation->location_id, 'quantity' => $take,
                            'owner_type' => 'order', 'owner_ref' => $orderRef, 'status' => 'committed', 'expires_at' => null, 'created_at' => $stamp, 'updated_at' => $stamp,
                        ]);
                    }
                    $remaining -= $take;
                }
            }
        });
    }

    public function revert(string $orderRef, string $shipmentRef, ?int $actorId): void
    {
        Transactions::run(function () use ($orderRef, $shipmentRef, $actorId): void {
            $stamp = $this->clock->now()->format('Y-m-d H:i:s.u');
            $moves = DB::table('stock_movements')->where('ref_type', 'shipment')->where('ref_id', $shipmentRef)->where('reason', 'shipment')->orderBy('id')->get();

            foreach ($moves as $move) {
                $already = DB::table('stock_movements')->where('ref_type', 'shipment')->where('ref_id', $shipmentRef)->where('reason', 'shipment_cancelled')
                    ->where('variant_id', $move->variant_id)->where('location_id', $move->location_id)->exists();
                if ($already) {
                    continue; // put back before
                }
                $quantity = -(int) $move->delta;

                $affected = DB::update('UPDATE stock_levels SET on_hand = on_hand + ?, reserved = reserved + ?, updated_at = ? WHERE variant_id = ? AND location_id = ?', [$quantity, $quantity, $stamp, $move->variant_id, $move->location_id]);
                if ($affected !== 1) {
                    throw InventoryException::invariant("no stock level to put back variant {$move->variant_id}");
                }
                DB::table('stock_movements')->insert([
                    'variant_id' => $move->variant_id, 'location_id' => $move->location_id, 'delta' => $quantity, 'reason' => 'shipment_cancelled',
                    'ref_type' => 'shipment', 'ref_id' => $shipmentRef, 'note' => null, 'created_by' => $actorId, 'created_at' => $stamp,
                ]);

                $active = DB::table('stock_reservations')->where('owner_type', 'order')->where('owner_ref', $orderRef)->where('variant_id', $move->variant_id)->where('location_id', $move->location_id)->where('status', 'active')->lockForUpdate()->first();
                if ($active !== null) {
                    DB::table('stock_reservations')->where('id', $active->id)->update(['quantity' => $active->quantity + $quantity, 'updated_at' => $stamp]);
                } else {
                    DB::table('stock_reservations')->insert([
                        'public_id' => strtolower((string) Str::ulid()), 'variant_id' => $move->variant_id, 'location_id' => $move->location_id, 'quantity' => $quantity,
                        'owner_type' => 'order', 'owner_ref' => $orderRef, 'status' => 'active', 'expires_at' => null, 'created_at' => $stamp, 'updated_at' => $stamp,
                    ]);
                }
            }
        });
    }
}
