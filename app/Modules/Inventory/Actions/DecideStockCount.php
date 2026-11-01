<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\CountStatus;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * A SECOND person approves or refuses a count that found differences (the database refuses the same person too). Approving corrects stock by each line's
 * difference, one ledger row each, all or none; if a correction would take stock below what is promised to orders nothing changes and a person must look.
 * The first decision wins (the count row is locked). Who may approve (`inventory.count`) is decided by the caller.
 */
final class DecideStockCount
{
    public function __construct(private readonly AdjustStock $adjust, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws StockOpsException */
    public function approve(Actor $actor, string $countPublicId): StockCount
    {
        return Transactions::run(function () use ($actor, $countPublicId): StockCount {
            $count = $this->pending($countPublicId);
            if ($actor->id === null || $actor->id === $count->submitted_by) {
                throw new StockOpsException('second_person_required');
            }

            $code = (string) DB::table('stock_locations')->where('id', $count->location_id)->value('code');
            foreach (DB::table('stock_count_lines')->where('count_id', $count->id)->where('variance', '!=', 0)->orderBy('variant_id')->get() as $line) {
                try {
                    $this->adjust->handle($actor, (int) $line->variant_id, $code, (int) $line->variance, 'count', 'Stock-take correction', 'stock_count', $count->public_id);
                } catch (InventoryException) {
                    throw new StockOpsException('variance_conflicts', ['variant' => (int) $line->variant_id]);
                }
            }

            return $this->close($actor, $count, CountStatus::Approved, null);
        });
    }

    /** @throws StockOpsException */
    public function reject(Actor $actor, string $countPublicId, string $reason): StockCount
    {
        $reason = mb_substr(trim(BanglaText::input($reason)), 0, 255);
        if ($reason === '') {
            throw new StockOpsException('reason_required');
        }

        return Transactions::run(function () use ($actor, $countPublicId, $reason): StockCount {
            $count = $this->pending($countPublicId);
            if ($actor->id === null || $actor->id === $count->submitted_by) {
                throw new StockOpsException('second_person_required');
            }

            return $this->close($actor, $count, CountStatus::Rejected, $reason);
        });
    }

    /** @throws StockOpsException */
    private function pending(string $publicId): StockCount
    {
        $count = StockCount::query()->where('public_id', $publicId)->lockForUpdate()->first() ?? throw new StockOpsException('count_not_found');
        if ($count->status !== CountStatus::Submitted) {
            throw new StockOpsException('count_not_pending', ['status' => $count->status->value]);
        }

        return $count;
    }

    private function close(Actor $actor, StockCount $count, CountStatus $to, ?string $note): StockCount
    {
        $now = $this->clock->now();
        DB::table('stock_counts')->where('id', $count->id)->where('status', CountStatus::Submitted->value)->update(['status' => $to->value, 'decided_by' => $actor->id, 'decided_at' => $now, 'decision_note' => $note, 'updated_at' => $now]);
        $done = StockCount::query()->findOrFail($count->id);
        $this->audit->record('stock.count_'.$to->value, $done, ['status' => 'submitted'], ['status' => $to->value, 'note' => $note], $actor);

        return $done;
    }
}
