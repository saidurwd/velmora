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

/** A count that was started by mistake is called off, while it is still open. It frees the place for a new count. */
final class CancelStockCount
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
            DB::table('stock_counts')->where('id', $count->id)->where('status', CountStatus::Open->value)->update(['status' => CountStatus::Cancelled->value, 'updated_at' => $this->clock->now()]);
            $done = StockCount::query()->findOrFail($count->id);
            $this->audit->record('stock.count_cancelled', $done, ['status' => 'open'], ['status' => 'cancelled'], $actor);

            return $done;
        });
    }
}
