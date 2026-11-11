<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies a previewed stock file, once, all or nothing, each row through the same AdjustStock as the single-product form (so stock can never go below zero or below what is
 * promised to orders). The import row is locked while it is applied, so two people pressing "apply" cannot both win, and "set" rows are worked out against the stock as it is NOW,
 * not as it was at the preview. A file with errors, or one previewed too long ago, is refused. Who may (`inventory.adjust`) is decided by the caller.
 */
final class ApplyStockImport
{
    public function __construct(private readonly AdjustStock $adjust, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws StockOpsException import_not_found | import_not_previewed | import_has_errors | import_expired | import_row_failed */
    public function handle(Actor $actor, string $publicId): void
    {
        Transactions::run(function () use ($actor, $publicId): void {
            $import = DB::table('stock_imports')->where('public_id', $publicId)->lockForUpdate()->first() ?? throw new StockOpsException('import_not_found');   // unique public_id
            if ($import->status !== 'previewed') {
                throw new StockOpsException('import_not_previewed');
            }
            if ((int) $import->error_count > 0) {
                throw new StockOpsException('import_has_errors');
            }
            $now = $this->clock->now();
            if ($now->greaterThan(CarbonImmutable::parse((string) $import->created_at, 'UTC')->addHours(max(1, (int) config('inventory.imports.preview_hours'))))) {
                throw new StockOpsException('import_expired');
            }

            $code = (string) DB::table('stock_locations')->where('id', $import->location_id)->value('code');
            $changed = 0;
            foreach (DB::table('stock_import_rows')->where('import_id', $import->id)->where('status', 'ok')->orderBy('variant_id')->get() as $row) {   // unique (import_id, line)
                $level = DB::table('stock_levels')->where('variant_id', $row->variant_id)->where('location_id', $import->location_id)->lockForUpdate()->first();
                $onHand = $level === null ? 0 : (int) $level->on_hand;
                $delta = $import->mode === 'set' ? (int) $row->quantity - $onHand : (int) $row->quantity;
                if ($delta !== 0) {
                    try {
                        $this->adjust->handle($actor, (int) $row->variant_id, $code, $delta, 'adjustment', (string) $import->note, 'stock_import', (string) $import->public_id);
                    } catch (InventoryException) {
                        throw new StockOpsException('import_row_failed', ['sku' => (string) $row->sku, 'line' => (int) $row->line]);   // stock moved since the preview: nothing is applied
                    }
                    $changed++;
                }
                DB::table('stock_import_rows')->where('id', $row->id)->update(['applied_delta' => $delta, 'after_on_hand' => $onHand + $delta]);
            }

            DB::table('stock_imports')->where('id', $import->id)->update(['status' => 'applied', 'applied_by' => $actor->id, 'applied_at' => $now->format('Y-m-d H:i:s.u'), 'updated_at' => $now->format('Y-m-d H:i:s.u')]);
            $this->audit->record('stock.import_applied', 'stock_import', after: ['rows_changed' => $changed, 'mode' => $import->mode, 'location_id' => (int) $import->location_id], actor: $actor, subjectId: (int) $import->id);
        });
    }
}
