<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\CountStatus;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Hands the count in. Every product must have been counted. What the books say at this moment is set against what was counted, and the difference of each
 * line is fixed as the correction a SECOND person will approve; nothing in stock changes yet. A count where nothing differs needs nobody's approval.
 */
final class SubmitStockCount
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws StockOpsException */
    public function handle(Actor $actor, string $countPublicId): StockCount
    {
        return Transactions::run(function () use ($actor, $countPublicId): StockCount {
            $count = StockCount::query()->where('public_id', $countPublicId)->lockForUpdate()->first() ?? throw new StockOpsException('count_not_found');
            if ($count->status !== CountStatus::Open) {
                throw new StockOpsException('count_not_open', ['status' => $count->status->value]);
            }

            $lines = DB::table('stock_count_lines')->where('count_id', $count->id)->orderBy('variant_id')->get();
            $left = $lines->whereNull('counted_qty')->count();
            if ($lines->isEmpty() || $left > 0) {
                throw new StockOpsException('lines_uncounted', ['left' => $left]);
            }

            $differences = 0;
            foreach ($lines as $line) {
                $system = (int) DB::table('stock_levels')->where('variant_id', $line->variant_id)->where('location_id', $count->location_id)->lockForUpdate()->value('on_hand');
                $variance = (int) $line->counted_qty - $system;
                DB::table('stock_count_lines')->where('id', $line->id)->update(['system_qty' => $system, 'variance' => $variance]);
                $differences += $variance === 0 ? 0 : 1;
            }

            $now = $this->clock->now();
            $clean = $differences === 0;
            DB::table('stock_counts')->where('id', $count->id)->where('status', CountStatus::Open->value)->update(
                ['status' => $clean ? CountStatus::Approved->value : CountStatus::Submitted->value, 'submitted_by' => $actor->id, 'submitted_at' => $now, 'updated_at' => $now]
                + ($clean ? ['decided_at' => $now, 'decision_note' => 'No differences'] : []),
            );
            $done = StockCount::query()->findOrFail($count->id);
            $this->audit->record('stock.count_submitted', $done, ['status' => 'open'], ['status' => $done->status->value, 'differences' => $differences], $actor);

            return $done;
        });
    }
}
