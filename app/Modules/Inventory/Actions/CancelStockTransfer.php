<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** A transfer that never left is called off: every unit goes back to the place it came from, with a ledger row each. Only while it is on its way. */
final class CancelStockTransfer
{
    public function __construct(private readonly AdjustStock $adjust, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws StockOpsException */
    public function handle(Actor $actor, string $transferPublicId, string $reason): StockTransfer
    {
        $reason = mb_substr(trim(BanglaText::input($reason)), 0, 255);
        if ($reason === '') {
            throw new StockOpsException('reason_required');
        }

        return Transactions::run(function () use ($actor, $transferPublicId, $reason): StockTransfer {
            $transfer = StockTransfer::query()->where('public_id', $transferPublicId)->lockForUpdate()->first() ?? throw new StockOpsException('transfer_not_found');
            if ($transfer->status !== TransferStatus::InTransit) {
                throw new StockOpsException('transfer_not_open', ['status' => $transfer->status->value]);
            }

            $fromCode = (string) DB::table('stock_locations')->where('id', $transfer->from_location_id)->value('code');
            foreach ($transfer->items()->orderBy('variant_id')->get() as $item) {
                $this->adjust->handle($actor, $item->variant_id, $fromCode, $item->sent_qty, 'transfer_in', 'Transfer cancelled: put back', 'stock_transfer', $transfer->public_id);
            }

            $now = $this->clock->now();
            DB::table('stock_transfers')->where('id', $transfer->id)->where('status', TransferStatus::InTransit->value)->update(['status' => TransferStatus::Cancelled->value, 'cancelled_at' => $now, 'updated_at' => $now]);
            $done = StockTransfer::query()->findOrFail($transfer->id);
            $this->audit->record('stock.transfer_cancelled', $done, ['status' => 'in_transit'], ['status' => 'cancelled', 'reason' => $reason], $actor);

            return $done;
        });
    }
}
