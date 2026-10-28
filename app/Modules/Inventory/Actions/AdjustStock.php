<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Services\StockAlerts;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way on_hand changes (besides CommitReservation): a conditional UPDATE plus a ledger row in one transaction.
 * Stock can never be pushed below what is reserved or below zero.
 */
final class AdjustStock
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly StockAlerts $alerts) {}

    public function handle(Actor $actor, int $variantId, string $locationCode, int $delta, string $reason, ?string $note = null, ?string $refType = null, ?string $refId = null): void
    {
        if ($delta === 0) {
            throw InventoryException::invalidQuantity();
        }

        $locationId = (int) (DB::table('stock_locations')->where('code', $locationCode)->where('is_active', true)->value('id')
            ?? throw InventoryException::unknownLocation($locationCode));

        Transactions::run(function () use ($actor, $variantId, $locationId, $delta, $reason, $note, $refType, $refId): void {
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');

            if ($delta > 0) {
                DB::table('stock_levels')->insertOrIgnore(['variant_id' => $variantId, 'location_id' => $locationId, 'on_hand' => 0, 'reserved' => 0]);
            }

            $level = DB::table('stock_levels')->where('variant_id', $variantId)->where('location_id', $locationId)->lockForUpdate()->first();
            $availableBefore = $level === null ? 0 : (int) $level->on_hand - (int) $level->reserved;

            $affected = DB::update(
                'UPDATE stock_levels SET on_hand = on_hand + ?, updated_at = ? WHERE variant_id = ? AND location_id = ? AND on_hand + ? >= reserved AND on_hand + ? >= 0',
                [$delta, $now, $variantId, $locationId, $delta, $delta],
            );

            if ($affected !== 1) {
                throw InventoryException::belowReserved($variantId);
            }

            DB::table('stock_movements')->insert([
                'variant_id' => $variantId, 'location_id' => $locationId, 'delta' => $delta, 'reason' => $reason,
                'ref_type' => $refType, 'ref_id' => $refId, 'note' => $note, 'created_by' => $actor->id, 'created_at' => $now,
            ]);

            $this->audit->record('stock.adjusted', 'variant', after: ['delta' => $delta, 'reason' => $reason, 'location_id' => $locationId, 'ref' => $refType ? "{$refType}:{$refId}" : null], actor: $actor, subjectId: $variantId);
            $this->alerts->afterChange($variantId, $locationId, $availableBefore);
        });
    }
}
