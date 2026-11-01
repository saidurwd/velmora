<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * The other place has the goods. Staff say how many of each product really arrived (by default all of it); that goes onto the shelf with a ledger row each, and
 * what did not arrive is recorded as lost on the line (set once) so it is never silently forgotten. Once; the first to press wins (the row is locked).
 */
final class ReceiveStockTransfer
{
    public function __construct(private readonly AdjustStock $adjust, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @param  array<int, int>|null  $received  variant id => quantity that arrived (default: everything sent)
     *
     * @throws StockOpsException
     */
    public function handle(Actor $actor, string $transferPublicId, ?array $received = null): StockTransfer
    {
        return Transactions::run(function () use ($actor, $transferPublicId, $received): StockTransfer {
            $transfer = StockTransfer::query()->where('public_id', $transferPublicId)->lockForUpdate()->first() ?? throw new StockOpsException('transfer_not_found');
            if ($transfer->status !== TransferStatus::InTransit) {
                throw new StockOpsException('transfer_not_open', ['status' => $transfer->status->value]);
            }

            $toCode = (string) DB::table('stock_locations')->where('id', $transfer->to_location_id)->value('code');
            $lost = [];
            foreach ($transfer->items()->orderBy('variant_id')->get() as $item) {
                $arrived = $received[$item->variant_id] ?? $item->sent_qty;
                if ($arrived < 0 || $arrived > $item->sent_qty) {
                    throw new StockOpsException('received_invalid', ['max' => $item->sent_qty]);
                }
                DB::table('stock_transfer_items')->where('id', $item->id)->whereNull('received_qty')->update(['received_qty' => $arrived, 'lost_qty' => $item->sent_qty - $arrived]);
                if ($arrived > 0) {
                    $this->adjust->handle($actor, $item->variant_id, $toCode, $arrived, 'transfer_in', 'Transfer received', 'stock_transfer', $transfer->public_id);
                }
                if ($arrived < $item->sent_qty) {
                    $lost[$item->variant_id] = $item->sent_qty - $arrived;
                }
            }

            $now = $this->clock->now();
            DB::table('stock_transfers')->where('id', $transfer->id)->where('status', TransferStatus::InTransit->value)->update(['status' => TransferStatus::Received->value, 'received_by' => $actor->id, 'received_at' => $now, 'updated_at' => $now]);
            $done = StockTransfer::query()->findOrFail($transfer->id);
            $this->audit->record('stock.transfer_received', $done, ['status' => 'in_transit'], ['status' => 'received', 'lost' => $lost], $actor);

            return $done;
        });
    }
}
