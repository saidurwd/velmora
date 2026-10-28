<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Actions\CommitReservation;
use App\Modules\Inventory\Actions\ExpireReservations;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Inventory\Events\StockLow;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Jobs\VerifyStockLedger;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\FrozenClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    $this->seed([LanguageSeeder::class, StockLocationSeeder::class]);
    $this->actor = Actor::system('test');
    $this->clock = new FrozenClock('2026-06-01 10:00:00');
    app()->instance(Clock::class, $this->clock);

    $category = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'Clothing')]);
    $this->variants = [];
    foreach (range(1, 3) as $i) {
        $product = app(SaveProduct::class)->handle($this->actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', "Item {$i}")], categoryIds: [$category->id], defaultVariant: new VariantData("ITEM-{$i}", trackInventory: true)));
        $this->variants[$i] = (int) $product->variants()->value('id');
    }
    $this->v1 = $this->variants[1];
    $this->v2 = $this->variants[2];
    $this->v3 = $this->variants[3];
    $this->avail = app(Availability::class);
});

function stock(int $variantId, int $qty, string $location = 'main'): void
{
    app(AdjustStock::class)->handle(Actor::system('test'), $variantId, $location, $qty, 'receipt');
}

/** Seeds stock at a location bypassing the action (needed for inactive locations the action rightly refuses). */
function forceStock(int $variantId, int $qty, string $location): void
{
    $locationId = (int) DB::table('stock_locations')->where('code', $location)->value('id');
    DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => $locationId, 'on_hand' => $qty, 'reserved' => 0]);
    DB::table('stock_movements')->insert(['variant_id' => $variantId, 'location_id' => $locationId, 'delta' => $qty, 'reason' => 'initial', 'created_at' => now()]);
}

function level(int $variantId, string $location = 'main'): object
{
    return DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')->where('code', $location)->where('variant_id', $variantId)->first();
}

function addLocation(string $code, bool $active = true): void
{
    DB::table('stock_locations')->insert(['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'name' => ucfirst($code), 'type' => 'warehouse', 'is_active' => $active, 'created_at' => now(), 'updated_at' => now()]);
}

describe('receiving and adjusting', function () {
    it('adds stock and writes a ledger row for every change', function () {
        stock($this->v1, 10);
        app(AdjustStock::class)->handle($this->actor, $this->v1, 'main', -3, 'write_off', 'damaged');

        expect(level($this->v1)->on_hand)->toBe(7)
            ->and(DB::table('stock_movements')->where('variant_id', $this->v1)->orderBy('id')->pluck('delta')->map(fn ($d) => (int) $d)->all())->toBe([10, -3])
            ->and($this->avail->forVariant($this->v1))->toBe(7)
            ->and(DB::table('audit_events')->where('action', 'stock.adjusted')->count())->toBe(2);
    });

    it('never lets on_hand go negative or below what is reserved', function () {
        stock($this->v1, 5);
        app(ReserveStock::class)->handle($this->v1, 3, 'order', 'ORD-1');

        expect(fn () => app(AdjustStock::class)->handle($this->actor, $this->v1, 'main', -3, 'adjustment'))->toThrow(InventoryException::class, 'cannot drop below')
            ->and(fn () => app(AdjustStock::class)->handle($this->actor, $this->v2, 'main', -1, 'adjustment'))->toThrow(InventoryException::class);

        app(AdjustStock::class)->handle($this->actor, $this->v1, 'main', -2, 'adjustment'); // down to exactly the reserved amount: fine
        expect(level($this->v1)->on_hand)->toBe(3)->and(DB::table('stock_movements')->where('variant_id', $this->v1)->count())->toBe(2); // the rejected attempts left no trace
    });

    it('rejects a zero change and an unknown location', function () {
        expect(fn () => app(AdjustStock::class)->handle($this->actor, $this->v1, 'main', 0, 'adjustment'))->toThrow(InventoryException::class)
            ->and(fn () => app(AdjustStock::class)->handle($this->actor, $this->v1, 'nowhere', 5, 'receipt'))->toThrow(InventoryException::class, 'Unknown');
    });

    it('is protected by database CHECK constraints even against direct writes', function () {
        stock($this->v1, 5);

        expect(fn () => DB::table('stock_levels')->where('variant_id', $this->v1)->update(['on_hand' => -1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_levels')->where('variant_id', $this->v1)->update(['reserved' => -1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_levels')->where('variant_id', $this->v1)->update(['reserved' => 6]))->toThrow(QueryException::class);
    });

    it('keeps the ledger append-only', function () {
        stock($this->v1, 5);

        expect(fn () => DB::table('stock_movements')->update(['delta' => 999]))->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::table('stock_movements')->delete())->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::table('stock_movements')->insert(['variant_id' => $this->v1, 'location_id' => level($this->v1)->location_id, 'delta' => 0, 'reason' => 'receipt', 'created_at' => now()]))->toThrow(QueryException::class);
    });
});

describe('reserving', function () {
    it('reserves available stock and shrinks availability', function () {
        stock($this->v1, 10);

        $r = app(ReserveStock::class)->handle($this->v1, 4, 'order', 'ORD-1');

        expect($r->status)->toBe('active')->and($r->quantity)->toBe(4)->and(level($this->v1)->reserved)->toBe(4)->and(level($this->v1)->on_hand)->toBe(10)
            ->and($this->avail->forVariant($this->v1))->toBe(6)
            ->and($r->expires_at->format('Y-m-d H:i:s'))->toBe('2026-06-01 10:20:00'); // default 20-minute hold
    });

    it('refuses to oversell: asking for more than is available fails and changes nothing', function () {
        stock($this->v1, 3);
        app(ReserveStock::class)->handle($this->v1, 2, 'order', 'ORD-1');

        expect(fn () => app(ReserveStock::class)->handle($this->v1, 2, 'order', 'ORD-2'))->toThrow(InventoryException::class, 'Only 1 available')
            ->and(level($this->v1)->reserved)->toBe(2)->and(StockReservation::count())->toBe(1);
    });

    it('takes the last unit exactly once', function () {
        stock($this->v1, 1);

        app(ReserveStock::class)->handle($this->v1, 1, 'order', 'ORD-1');

        expect(fn () => app(ReserveStock::class)->handle($this->v1, 1, 'order', 'ORD-2'))->toThrow(InventoryException::class)
            ->and($this->avail->forVariant($this->v1))->toBe(0);
    });

    it('rejects non-positive quantities and variants with no stock row', function () {
        stock($this->v1, 3);

        expect(fn () => app(ReserveStock::class)->handle($this->v1, 0, 'order', 'X'))->toThrow(InventoryException::class, 'positive')
            ->and(fn () => app(ReserveStock::class)->handle($this->v1, -2, 'order', 'X'))->toThrow(InventoryException::class)
            ->and(fn () => app(ReserveStock::class)->handle($this->v2, 1, 'order', 'X'))->toThrow(InventoryException::class, 'Only 0 available');
    });

    it('picks the location with the most stock and ignores inactive ones', function () {
        addLocation('shop');
        addLocation('closed', active: false);
        stock($this->v1, 2, 'main');
        stock($this->v1, 9, 'shop');
        forceStock($this->v1, 50, 'closed');

        $r = app(ReserveStock::class)->handle($this->v1, 1, 'order', 'ORD-1');

        expect($r->location_id)->toBe((int) level($this->v1, 'shop')->location_id)->and($this->avail->forVariant($this->v1))->toBe(10);
    });

    it('does not split a line across locations', function () {
        addLocation('shop');
        stock($this->v1, 3, 'main');
        stock($this->v1, 3, 'shop');

        expect(fn () => app(ReserveStock::class)->handle($this->v1, 4, 'order', 'ORD-1'))->toThrow(InventoryException::class);
    });

    it('reserves a whole basket all-or-nothing, in one transaction', function () {
        stock($this->v1, 5);
        stock($this->v2, 1);

        expect(fn () => app(ReserveStock::class)->forLines([$this->v2 => 1, $this->v1 => 2, $this->v3 => 1], 'order', 'ORD-1'))->toThrow(InventoryException::class); // v3 has no stock

        expect(level($this->v1)->reserved)->toBe(0)->and(level($this->v2)->reserved)->toBe(0)->and(StockReservation::count())->toBe(0); // nothing leaked

        $ok = app(ReserveStock::class)->forLines([$this->v2 => 1, $this->v1 => 2], 'order', 'ORD-2');
        expect($ok)->toHaveCount(2)->and(level($this->v1)->reserved)->toBe(2);
    });

    it('rolls back reservations when the surrounding order transaction fails', function () {
        stock($this->v1, 5);

        try {
            DB::transaction(function () {
                app(ReserveStock::class)->handle($this->v1, 3, 'order', 'ORD-1');
                throw new RuntimeException('order creation failed after reserving');
            });
        } catch (RuntimeException) {
        }

        expect(level($this->v1)->reserved)->toBe(0)->and(StockReservation::count())->toBe(0);
    });
});

describe('releasing and expiry', function () {
    it('releases stock back and is idempotent', function () {
        stock($this->v1, 5);
        $r = app(ReserveStock::class)->handle($this->v1, 3, 'order', 'ORD-1');

        expect(app(ReleaseReservation::class)->handle($r->public_id))->toBeTrue()
            ->and(app(ReleaseReservation::class)->handle($r->public_id))->toBeFalse()   // double release is a no-op
            ->and(level($this->v1)->reserved)->toBe(0)->and($r->fresh()->status)->toBe('released');
    });

    it('releases everything an order holds', function () {
        stock($this->v1, 5);
        stock($this->v2, 5);
        app(ReserveStock::class)->forLines([$this->v1 => 2, $this->v2 => 3], 'order', 'ORD-9');
        app(ReserveStock::class)->handle($this->v1, 1, 'order', 'ORD-OTHER');

        expect(app(ReleaseReservation::class)->forOwner('order', 'ORD-9'))->toBe(2)
            ->and(level($this->v1)->reserved)->toBe(1)->and(level($this->v2)->reserved)->toBe(0);
    });

    it('expires reservations whose hold ran out and returns the stock', function () {
        stock($this->v1, 5);
        $short = app(ReserveStock::class)->handle($this->v1, 2, 'order', 'ORD-1');
        $long = app(ReserveStock::class)->handle($this->v1, 1, 'order', 'ORD-2', ttlSeconds: 7200);

        expect(app(ExpireReservations::class)->handle())->toBe(0); // nothing due yet

        $this->clock->advanceSeconds(1201);
        expect(app(ExpireReservations::class)->handle())->toBe(1)
            ->and($short->fresh()->status)->toBe('expired')->and($long->fresh()->status)->toBe('active')
            ->and(level($this->v1)->reserved)->toBe(1)->and($this->avail->forVariant($this->v1))->toBe(4)
            ->and(app(ExpireReservations::class)->handle())->toBe(0); // idempotent
    });

    it('rejects committing a reservation that already expired', function () {
        stock($this->v1, 5);
        $r = app(ReserveStock::class)->handle($this->v1, 2, 'order', 'ORD-1');
        $this->clock->advanceSeconds(1201);
        app(ExpireReservations::class)->handle();

        expect(fn () => app(CommitReservation::class)->handle($r->public_id))->toThrow(InventoryException::class, 'expired')
            ->and(level($this->v1)->on_hand)->toBe(5);
    });
});

describe('committing at shipment', function () {
    it('takes units out of on_hand and reserved together, with a ledger row', function () {
        stock($this->v1, 10);
        $r = app(ReserveStock::class)->handle($this->v1, 4, 'order', 'ORD-1');

        expect(app(CommitReservation::class)->handle($r->public_id, 'shipment', 'SHP-1'))->toBeTrue();

        $movement = DB::table('stock_movements')->where('reason', 'shipment')->first();
        expect(level($this->v1)->on_hand)->toBe(6)->and(level($this->v1)->reserved)->toBe(0)->and($r->fresh()->status)->toBe('committed')
            ->and((int) $movement->delta)->toBe(-4)->and($movement->ref_type)->toBe('shipment')->and($movement->ref_id)->toBe('SHP-1')
            ->and($this->avail->forVariant($this->v1))->toBe(6);
    });

    it('is idempotent: committing twice ships once', function () {
        stock($this->v1, 10);
        $r = app(ReserveStock::class)->handle($this->v1, 4, 'order', 'ORD-1');

        app(CommitReservation::class)->handle($r->public_id);

        expect(app(CommitReservation::class)->handle($r->public_id))->toBeFalse()->and(level($this->v1)->on_hand)->toBe(6)
            ->and(DB::table('stock_movements')->where('reason', 'shipment')->count())->toBe(1);
    });

    it('cannot commit a released reservation or an unknown one', function () {
        stock($this->v1, 5);
        $r = app(ReserveStock::class)->handle($this->v1, 2, 'order', 'ORD-1');
        app(ReleaseReservation::class)->handle($r->public_id);

        expect(fn () => app(CommitReservation::class)->handle($r->public_id))->toThrow(InventoryException::class, 'released')
            ->and(fn () => app(CommitReservation::class)->handle('nope'))->toThrow(InventoryException::class);
    });
});

describe('ledger integrity', function () {
    it('keeps on_hand = Σ ledger and reserved = Σ active reservations through hundreds of random operations', function () {
        mt_srand(20261028);
        addLocation('shop');
        foreach ([$this->v1, $this->v2, $this->v3] as $v) {
            stock($v, 50, 'main');
            stock($v, 30, 'shop');
        }
        $open = [];

        foreach (range(1, 300) as $i) {
            $v = [$this->v1, $this->v2, $this->v3][mt_rand(0, 2)];
            try {
                match (mt_rand(1, 7)) {
                    1, 2, 3 => $open[] = app(ReserveStock::class)->handle($v, mt_rand(1, 12), 'order', "ORD-{$i}")->public_id,
                    4 => $open !== [] && app(CommitReservation::class)->handle(array_splice($open, array_rand($open), 1)[0]),
                    5 => $open !== [] && app(ReleaseReservation::class)->handle(array_splice($open, array_rand($open), 1)[0]),
                    6 => app(AdjustStock::class)->handle($this->actor, $v, ['main', 'shop'][mt_rand(0, 1)], mt_rand(-15, 25) ?: 5, 'adjustment'),
                    7 => (function () {
                        test()->clock->advanceSeconds(mt_rand(60, 900));
                        app(ExpireReservations::class)->handle();
                    })(),
                };
            } catch (InventoryException) {
                // refusals are expected; what matters is that the books still balance
            }
        }

        expect(app(StockLedgerVerifier::class)->verify())->toBe([])
            ->and(DB::table('stock_levels')->whereRaw('on_hand < 0 OR reserved < 0 OR reserved > on_hand')->count())->toBe(0);
    });

    it('detects tampering with the counts and alerts critically', function () {
        stock($this->v1, 10);
        app(ReserveStock::class)->handle($this->v1, 2, 'order', 'ORD-1');
        expect(app(StockLedgerVerifier::class)->verify())->toBe([]);

        DB::table('stock_levels')->where('variant_id', $this->v1)->update(['on_hand' => 12]);      // not in the ledger
        DB::table('stock_levels')->where('variant_id', $this->v1)->update(['reserved' => 5]);      // not in the reservations

        $kinds = collect(app(StockLedgerVerifier::class)->verify())->pluck('kind')->sort()->values()->all();
        expect($kinds)->toBe(['on_hand_vs_ledger', 'reserved_vs_reservations']);

        (new VerifyStockLedger)->handle(app(StockLedgerVerifier::class), app(AuditLogger::class));
        expect(DB::table('audit_events')->where('action', 'inventory.ledger_mismatch')->count())->toBe(2);
    });
});

describe('low stock alert', function () {
    it('raises StockLow once when availability crosses the threshold, not on every later change', function () {
        app(OutboxRegistry::class)->register(StockLow::class);
        stock($this->v1, 10);
        DB::table('stock_levels')->where('variant_id', $this->v1)->update(['low_stock_threshold' => 3]);

        app(ReserveStock::class)->handle($this->v1, 5, 'order', 'ORD-1');   // 5 left: above threshold
        expect(DB::table('outbox_messages')->where('event_name', 'inventory.stock_low')->count())->toBe(0);

        app(ReserveStock::class)->handle($this->v1, 3, 'order', 'ORD-2');   // 2 left: crosses
        expect(DB::table('outbox_messages')->where('event_name', 'inventory.stock_low')->count())->toBe(1);

        app(ReserveStock::class)->handle($this->v1, 1, 'order', 'ORD-3');   // 1 left: already below, no repeat
        expect(DB::table('outbox_messages')->where('event_name', 'inventory.stock_low')->count())->toBe(1);

        $payload = json_decode((string) DB::table('outbox_messages')->where('event_name', 'inventory.stock_low')->value('payload'), true);
        expect($payload['available'])->toBe(2)->and($payload['threshold'])->toBe(3);
    });
});

describe('availability contract', function () {
    it('sums active locations and answers for many variants at once', function () {
        addLocation('shop');
        addLocation('closed', active: false);
        stock($this->v1, 4, 'main');
        stock($this->v1, 6, 'shop');
        forceStock($this->v1, 99, 'closed');
        stock($this->v2, 2, 'main');

        expect($this->avail->forVariants([$this->v1, $this->v2, $this->v3]))->toBe([$this->v1 => 10, $this->v2 => 2, $this->v3 => 0])
            ->and($this->avail->forVariants([]))->toBe([]);
    });
});
