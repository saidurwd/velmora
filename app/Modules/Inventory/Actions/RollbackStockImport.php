<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Undoes an applied stock file with a COMPENSATING batch: new ledger rows that put back what the import moved, never a delete. Only rows whose stock has not been touched since
 * the import are undone (anything else moved on, and putting it back would erase someone else's work); a row whose undo would take stock below what orders are promised is kept
 * too. Each row says which it was. This can be done once. Who may (`inventory.adjust`) is decided by the caller.
 */
final class RollbackStockImport
{
    public function __construct(private readonly AdjustStock $adjust, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @return array{reverted: int, kept: int}
     *
     * @throws StockOpsException import_not_found | import_not_applied
     */
    public function handle(Actor $actor, string $publicId): array
    {
        return Transactions::run(function () use ($actor, $publicId): array {
            $import = DB::table('stock_imports')->where('public_id', $publicId)->lockForUpdate()->first() ?? throw new StockOpsException('import_not_found');   // unique public_id
            if ($import->status !== 'applied') {
                throw new StockOpsException('import_not_applied');
            }

            $code = (string) DB::table('stock_locations')->where('id', $import->location_id)->value('code');
            $reverted = 0;
            $kept = 0;
            foreach (DB::table('stock_import_rows')->where('import_id', $import->id)->where('status', 'ok')->where('applied_delta', '!=', 0)->orderBy('variant_id')->get() as $row) {
                DB::table('stock_levels')->where('variant_id', $row->variant_id)->where('location_id', $import->location_id)->lockForUpdate()->first();   // primary key: nobody moves it meanwhile
                $mine = (int) DB::table('stock_movements')->where('variant_id', $row->variant_id)->where('location_id', $import->location_id)->where('ref_type', 'stock_import')->where('ref_id', $import->public_id)->max('id');   // index (variant_id, location_id, id)
                $later = DB::table('stock_movements')->where('variant_id', $row->variant_id)->where('location_id', $import->location_id)->where('id', '>', $mine)->exists();   // index (variant_id, location_id, id)

                $state = 'kept_changed';
                if (! $later) {
                    try {
                        $this->adjust->handle($actor, (int) $row->variant_id, $code, -(int) $row->applied_delta, 'adjustment', 'Undo of stock file '.strtoupper(substr((string) $import->public_id, -8)), 'stock_import_rollback', (string) $import->public_id);
                        $state = 'reverted';
                    } catch (InventoryException) {
                        $state = 'kept_blocked';
                    }
                }
                DB::table('stock_import_rows')->where('id', $row->id)->update(['rollback_state' => $state]);
                $state === 'reverted' ? $reverted++ : $kept++;
            }

            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            DB::table('stock_imports')->where('id', $import->id)->update(['status' => 'rolled_back', 'rolled_back_by' => $actor->id, 'rolled_back_at' => $now, 'updated_at' => $now]);
            $this->audit->record('stock.import_rolled_back', 'stock_import', after: ['reverted' => $reverted, 'kept' => $kept], actor: $actor, subjectId: (int) $import->id);

            return ['reverted' => $reverted, 'kept' => $kept];
        });
    }
}
