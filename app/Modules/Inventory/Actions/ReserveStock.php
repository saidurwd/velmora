<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\StockAlerts;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Promises stock to a cart or order (ADR-013: reserve now, take it out of on_hand at shipment).
 *
 * The decision "is there enough?" is ONE conditional UPDATE (… WHERE on_hand − reserved >= :q); 0 rows means no.
 * There is never a read-then-write, so two buyers of the last unit cannot both succeed.
 */
final class ReserveStock
{
    public function __construct(private readonly Clock $clock, private readonly StockAlerts $alerts) {}

    public function handle(int $variantId, int $quantity, string $ownerType, string $ownerRef, ?int $ttlSeconds = null, ?int $locationId = null): StockReservation
    {
        return Transactions::run(fn () => $this->reserveLine($variantId, $quantity, $ownerType, $ownerRef, $ttlSeconds, $locationId));
    }

    /**
     * All-or-nothing reservation for a whole basket. Lines are processed in ascending variant id so concurrent baskets
     * always lock rows in the same order (no deadlock cycles). If any line is short, nothing stays reserved.
     *
     * @param  array<int, int>  $lines  variant id => quantity
     * @return list<StockReservation>
     */
    public function forLines(array $lines, string $ownerType, string $ownerRef, ?int $ttlSeconds = null): array
    {
        ksort($lines);

        return Transactions::run(function () use ($lines, $ownerType, $ownerRef, $ttlSeconds): array {
            $out = [];

            foreach ($lines as $variantId => $quantity) {
                $out[] = $this->reserveLine((int) $variantId, (int) $quantity, $ownerType, $ownerRef, $ttlSeconds, null);
            }

            return $out;
        });
    }

    private function reserveLine(int $variantId, int $quantity, string $ownerType, string $ownerRef, ?int $ttlSeconds, ?int $locationId): StockReservation
    {
        if ($quantity < 1) {
            throw InventoryException::invalidQuantity();
        }

        $now = $this->clock->now();
        $stamp = $now->format('Y-m-d H:i:s.u');

        foreach ($this->candidateLocations($variantId, $quantity, $locationId) as $candidate) {
            $before = $this->available($variantId, $candidate);

            $affected = DB::update(
                'UPDATE stock_levels SET reserved = reserved + ?, updated_at = ? WHERE variant_id = ? AND location_id = ? AND on_hand - reserved >= ?',
                [$quantity, $stamp, $variantId, $candidate, $quantity],
            );

            if ($affected === 1) {
                $reservation = StockReservation::query()->create([
                    'variant_id' => $variantId, 'location_id' => $candidate, 'quantity' => $quantity, 'owner_type' => $ownerType, 'owner_ref' => $ownerRef,
                    'status' => 'active', 'expires_at' => $now->addSeconds($ttlSeconds ?? (int) config('inventory.default_reservation_ttl_seconds'))->format('Y-m-d H:i:s.u'),
                ]);
                $this->alerts->afterChange($variantId, $candidate, $before);

                return $reservation;
            }
        }

        throw InventoryException::insufficient($variantId, $quantity, $this->totalAvailable($variantId));
    }

    /** @return list<int> locations worth trying, most stock first (stable order) */
    private function candidateLocations(int $variantId, int $quantity, ?int $locationId): array
    {
        if ($locationId !== null) {
            return [$locationId];
        }

        return array_values(array_map('intval', DB::table('stock_levels')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->where('stock_locations.is_active', true)->where('stock_levels.variant_id', $variantId)
            ->whereRaw('stock_levels.on_hand - stock_levels.reserved >= ?', [$quantity])
            ->orderByRaw('(stock_levels.on_hand - stock_levels.reserved) DESC')->orderBy('stock_levels.location_id')
            ->pluck('stock_levels.location_id')->all()));
    }

    private function available(int $variantId, int $locationId): int
    {
        $row = DB::table('stock_levels')->where('variant_id', $variantId)->where('location_id', $locationId)->first();

        return $row === null ? 0 : (int) $row->on_hand - (int) $row->reserved;
    }

    private function totalAvailable(int $variantId): int
    {
        return max(0, (int) DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->where('stock_locations.is_active', true)->where('stock_levels.variant_id', $variantId)
            ->selectRaw('COALESCE(SUM(stock_levels.on_hand - stock_levels.reserved), 0) AS a')->value('a'));
    }
}
