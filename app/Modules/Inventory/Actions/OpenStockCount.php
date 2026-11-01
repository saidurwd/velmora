<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a stock-take of one place: everything the books say is there, or just the products asked for (a cycle count). One count at a time per place
 * (a database UNIQUE), so two counts can never correct the same shelf twice. Who may count (`inventory.count`) is decided by the caller.
 */
final class OpenStockCount
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @param  list<int>|null  $variantIds  null = every product the books say is there
     *
     * @throws StockOpsException
     */
    public function handle(Actor $actor, string $locationCode, ?array $variantIds = null, ?string $note = null): StockCount
    {
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 255) ?: null);

        try {
            return Transactions::run(function () use ($actor, $locationCode, $variantIds, $note): StockCount {
                $location = DB::table('stock_locations')->where('code', $locationCode)->where('is_active', true)->value('id') ?? throw new StockOpsException('location_unknown');
                $levels = DB::table('stock_levels')->where('location_id', $location)->when($variantIds === null, fn ($q) => $q->where('on_hand', '>', 0), fn ($q) => $q->whereIn('variant_id', $variantIds ?? []))->pluck('on_hand', 'variant_id');
                $ids = $variantIds ?? $levels->keys()->map(fn ($v): int => (int) $v)->all();
                if ($ids === []) {
                    throw new StockOpsException('nothing_to_count');
                }

                $now = $this->clock->now();
                $publicId = strtolower((string) Str::ulid());
                $id = DB::table('stock_counts')->insertGetId(['public_id' => $publicId, 'location_id' => $location, 'status' => 'open', 'note' => $note, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
                foreach (array_unique($ids) as $variantId) {
                    if (! DB::table('product_variants')->where('id', $variantId)->exists()) {
                        throw new StockOpsException('variant_unknown');
                    }
                    DB::table('stock_count_lines')->insert(['count_id' => $id, 'variant_id' => $variantId, 'expected_qty' => (int) ($levels[$variantId] ?? 0)]);
                }

                $count = StockCount::query()->findOrFail($id);
                $this->audit->record('stock.count_opened', $count, [], ['location' => $locationCode, 'lines' => count(array_unique($ids))], $actor);

                return $count;
            });
        } catch (UniqueConstraintViolationException) {
            throw new StockOpsException('count_already_open'); // this place already has a count in progress
        }
    }
}
