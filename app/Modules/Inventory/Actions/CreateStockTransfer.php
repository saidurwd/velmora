<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends stock from one place to another. The units leave the source at once (a ledger row each, and only units that are not promised to an order can leave),
 * and wait in transit until the other place receives them. All lines or none. Who may do this (`inventory.transfer`) is decided by the caller.
 */
final class CreateStockTransfer
{
    public function __construct(private readonly AdjustStock $adjust, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @param  array<int, int>  $lines  variant id => quantity
     *
     * @throws StockOpsException
     */
    public function handle(Actor $actor, string $fromCode, string $toCode, array $lines, ?string $note = null): StockTransfer
    {
        if ($fromCode === $toCode) {
            throw new StockOpsException('same_location');
        }
        if ($lines === []) {
            throw new StockOpsException('no_lines');
        }
        foreach ($lines as $quantity) {
            if ($quantity < 1) {
                throw new StockOpsException('quantity_invalid');
            }
        }
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 255) ?: null);
        ksort($lines); // rows are always taken in the same order, so two transfers never wait on each other in a circle

        return Transactions::run(function () use ($actor, $fromCode, $toCode, $lines, $note): StockTransfer {
            $from = DB::table('stock_locations')->where('code', $fromCode)->where('is_active', true)->value('id') ?? throw new StockOpsException('location_unknown');
            $to = DB::table('stock_locations')->where('code', $toCode)->where('is_active', true)->value('id') ?? throw new StockOpsException('location_unknown');

            $now = $this->clock->now();
            $publicId = strtolower((string) Str::ulid());
            $id = DB::table('stock_transfers')->insertGetId(['public_id' => $publicId, 'from_location_id' => $from, 'to_location_id' => $to, 'status' => 'in_transit', 'note' => $note, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);

            foreach ($lines as $variantId => $quantity) {
                try {
                    $this->adjust->handle($actor, $variantId, $fromCode, -$quantity, 'transfer_out', "Transfer to {$toCode}", 'stock_transfer', $publicId);
                } catch (InventoryException) {
                    throw new StockOpsException('not_enough_stock', ['variant' => $variantId]); // short, or the rest is promised to orders: nothing leaves
                }
                DB::table('stock_transfer_items')->insert(['transfer_id' => $id, 'variant_id' => $variantId, 'sent_qty' => $quantity, 'created_at' => $now]);
            }

            $transfer = StockTransfer::query()->findOrFail($id);
            $this->audit->record('stock.transfer_created', $transfer, [], ['from' => $fromCode, 'to' => $toCode, 'lines' => $lines], $actor);

            return $transfer;
        });
    }
}
