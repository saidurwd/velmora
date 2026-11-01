<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Actions\CancelStockCount;
use App\Modules\Inventory\Actions\CancelStockTransfer;
use App\Modules\Inventory\Actions\CreateStockTransfer;
use App\Modules\Inventory\Actions\DecideStockCount;
use App\Modules\Inventory\Actions\OpenStockCount;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Actions\RecordStockCount;
use App\Modules\Inventory\Actions\SubmitStockCount;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Enums\CountStatus;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Services\LowStockReport;
use App\Modules\Inventory\Services\StockAgingReport;
use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    shippingWorld($this);   // two products with 50 each in "main", and the quarantine place
    DB::table('stock_locations')->insert(['public_id' => strtolower((string) Str::ulid()), 'code' => 'shop', 'name' => 'Dhanmondi shop', 'type' => 'store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->amina = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
    $this->babul = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Babul');
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(StockOpsException::class, $reason);
    $this->level = fn (int $variantId, string $code = 'main') => DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')->where('variant_id', $variantId)->where('stock_locations.code', $code)->first(['on_hand', 'reserved']);
    $this->send = fn (array $lines, string $from = 'main', string $to = 'shop', ?Actor $by = null) => app(CreateStockTransfer::class)->handle($by ?? $this->amina, $from, $to, $lines, 'Restock the shop');
    $this->hold = fn (int $variantId, int $qty) => app(StockReservations::class)->holdForOrder([$variantId => $qty], 'ORDER'.Str::random(6));
});

describe('transfers between places', function () {
    it('takes the units out of the source at once, one ledger row each, and waits for the other place to receive them', function () {
        $t = ($this->send)([$this->tee => 10, $this->mug => 5]);

        expect($t->status)->toBe(TransferStatus::InTransit)->and($t->items()->count())->toBe(2)->and(($this->level)($this->tee)->on_hand)->toBe(40)->and(($this->level)($this->mug)->on_hand)->toBe(45)
            ->and(DB::table('stock_levels')->where('location_id', DB::table('stock_locations')->where('code', 'shop')->value('id'))->count())->toBe(0)   // nothing has arrived yet
            ->and(DB::table('stock_movements')->where('ref_type', 'stock_transfer')->where('ref_id', $t->public_id)->where('reason', 'transfer_out')->sum('delta'))->toEqual(-15)
            ->and(DB::table('audit_events')->where('action', 'stock.transfer_created')->count())->toBe(1)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('moves only units that are not promised to an order, all lines or none', function () {
        ($this->hold)($this->tee, 45);   // 5 tees are free, 45 are promised

        ($this->refuses)(fn () => ($this->send)([$this->tee => 10]), 'not_enough_stock');
        ($this->refuses)(fn () => ($this->send)([$this->mug => 5, $this->tee => 6]), 'not_enough_stock');   // the mugs would go, the tees cannot: neither moves
        expect(DB::table('stock_transfers')->count())->toBe(0)->and(($this->level)($this->mug)->on_hand)->toBe(50)->and(DB::table('stock_movements')->where('reason', 'transfer_out')->count())->toBe(0);

        ($this->send)([$this->tee => 5]);
        expect(($this->level)($this->tee))->toMatchArray(['on_hand' => 45, 'reserved' => 45]);
    });

    it('is received in full: onto the shelf with a ledger row each, once', function () {
        $t = ($this->send)([$this->tee => 10]);

        $done = app(ReceiveStockTransfer::class)->handle($this->babul, $t->public_id);

        expect($done->status)->toBe(TransferStatus::Received)->and($done->received_by)->toBe($this->babul->id)->and(($this->level)($this->tee, 'shop')->on_hand)->toBe(10)->and(($this->level)($this->tee)->on_hand)->toBe(40)
            ->and(DB::table('stock_transfer_items')->first()->lost_qty)->toBe(0)->and(DB::table('stock_movements')->where('ref_id', $t->public_id)->where('reason', 'transfer_in')->sum('delta'))->toEqual(10)
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
        ($this->refuses)(fn () => app(ReceiveStockTransfer::class)->handle($this->babul, $t->public_id), 'transfer_not_open');
        expect(($this->level)($this->tee, 'shop')->on_hand)->toBe(10);
    });

    it('records what did not arrive as lost on the line, so nothing is forgotten', function () {
        $t = ($this->send)([$this->tee => 10, $this->mug => 4]);

        ($this->refuses)(fn () => app(ReceiveStockTransfer::class)->handle($this->babul, $t->public_id, [$this->tee => 11]), 'received_invalid');
        app(ReceiveStockTransfer::class)->handle($this->babul, $t->public_id, [$this->tee => 8]);

        $items = DB::table('stock_transfer_items')->orderBy('variant_id')->get()->keyBy('variant_id');
        expect(($this->level)($this->tee, 'shop')->on_hand)->toBe(8)->and($items[$this->tee]->received_qty)->toBe(8)->and($items[$this->tee]->lost_qty)->toBe(2)->and($items[$this->mug]->received_qty)->toBe(4)->and($items[$this->mug]->lost_qty)->toBe(0)
            ->and(DB::table('audit_events')->where('action', 'stock.transfer_received')->value('after'))->toContain('"lost"');
    });

    it('can be called off while on its way: every unit goes back, once', function () {
        $t = ($this->send)([$this->tee => 10]);
        ($this->refuses)(fn () => app(CancelStockTransfer::class)->handle($this->amina, $t->public_id, '  '), 'reason_required');

        $cancelled = app(CancelStockTransfer::class)->handle($this->amina, $t->public_id, 'Sent to the wrong place');

        expect($cancelled->status)->toBe(TransferStatus::Cancelled)->and(($this->level)($this->tee)->on_hand)->toBe(50)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
        ($this->refuses)(fn () => app(CancelStockTransfer::class)->handle($this->amina, $t->public_id, 'Again'), 'transfer_not_open');
        ($this->refuses)(fn () => app(ReceiveStockTransfer::class)->handle($this->babul, $t->public_id), 'transfer_not_open');

        $received = ($this->send)([$this->mug => 3]);
        app(ReceiveStockTransfer::class)->handle($this->babul, $received->public_id);
        ($this->refuses)(fn () => app(CancelStockTransfer::class)->handle($this->amina, $received->public_id, 'Too late'), 'transfer_not_open');
    });

    it('refuses the same place twice, a place that does not exist or is off, no lines and a zero quantity', function () {
        ($this->refuses)(fn () => ($this->send)([$this->tee => 1], 'main', 'main'), 'same_location');
        ($this->refuses)(fn () => ($this->send)([$this->tee => 1], 'main', 'nowhere'), 'location_unknown');
        DB::table('stock_locations')->where('code', 'shop')->update(['is_active' => 0]);
        ($this->refuses)(fn () => ($this->send)([$this->tee => 1]), 'location_unknown');
        DB::table('stock_locations')->where('code', 'shop')->update(['is_active' => 1]);
        ($this->refuses)(fn () => ($this->send)([]), 'no_lines');
        ($this->refuses)(fn () => ($this->send)([$this->tee => 0]), 'quantity_invalid');

        expect(DB::table('stock_transfers')->count())->toBe(0);
    });

    it('lets stock that was kept apart be moved back to the main place once it is fit to sell', function () {
        app(AdjustStock::class)->handle($this->amina, $this->tee, 'quarantine', 3, 'receipt', 'Looked at again: fine');
        $before = app(Availability::class)->forVariant($this->tee);

        $t = ($this->send)([$this->tee => 3], 'quarantine', 'main');
        app(ReceiveStockTransfer::class)->handle($this->babul, $t->public_id);

        expect(app(Availability::class)->forVariant($this->tee))->toBe($before + 3)->and(($this->level)($this->tee, 'quarantine')->on_hand)->toBe(0);
    });
});

describe('stock-take', function () {
    it('starts with what the books say is there, or just the products asked for, one count at a time per place', function () {
        $full = app(OpenStockCount::class)->handle($this->amina, 'main');
        expect($full->status)->toBe(CountStatus::Open)->and($full->lines()->count())->toBe(2)->and(DB::table('stock_count_lines')->where('count_id', $full->id)->pluck('expected_qty')->all())->toBe([50, 50]);

        ($this->refuses)(fn () => app(OpenStockCount::class)->handle($this->amina, 'main'), 'count_already_open');
        $other = app(OpenStockCount::class)->handle($this->amina, 'shop', [$this->tee]);   // another place may have its own
        expect($other->lines()->count())->toBe(1)->and(DB::table('stock_count_lines')->where('count_id', $other->id)->value('expected_qty'))->toBe(0);

        app(CancelStockCount::class)->handle($this->amina, $full->public_id);
        expect(app(OpenStockCount::class)->handle($this->amina, 'main', [$this->mug])->lines()->count())->toBe(1);   // the place is free again
        ($this->refuses)(fn () => app(OpenStockCount::class)->handle($this->amina, 'quarantine'), 'nothing_to_count');
        ($this->refuses)(fn () => app(OpenStockCount::class)->handle($this->amina, 'nowhere'), 'location_unknown');
    });

    it('takes what was counted, can be corrected while open, and takes a product that was not on the list', function () {
        $count = app(OpenStockCount::class)->handle($this->amina, 'main', [$this->tee]);

        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 47);
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 48);   // corrected
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->mug, 51);   // found one that was not on the list

        $lines = DB::table('stock_count_lines')->where('count_id', $count->id)->get()->keyBy('variant_id');
        expect($lines[$this->tee]->counted_qty)->toBe(48)->and($lines[$this->mug]->counted_qty)->toBe(51)->and($lines[$this->mug]->expected_qty)->toBe(50);
        ($this->refuses)(fn () => app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, -1), 'counted_invalid');
        ($this->refuses)(fn () => app(RecordStockCount::class)->handle($this->amina, $count->public_id, 999999, 1), 'variant_unknown');
        ($this->refuses)(fn () => app(RecordStockCount::class)->handle($this->amina, '01HZZZZZZZZZZZZZZZZZZZZZZZ', $this->tee, 1), 'count_not_found');
    });

    it('is handed in only when everything is counted, sets what the books say now against the count, and changes no stock yet', function () {
        $count = app(OpenStockCount::class)->handle($this->amina, 'main');
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 47);
        ($this->refuses)(fn () => app(SubmitStockCount::class)->handle($this->amina, $count->public_id), 'lines_uncounted');

        app(AdjustStock::class)->handle($this->amina, $this->mug, 'main', -2, 'shipment', 'Sold while counting');   // the books move while the counting goes on
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->mug, 48);
        $done = app(SubmitStockCount::class)->handle($this->amina, $count->public_id);

        $lines = DB::table('stock_count_lines')->where('count_id', $count->id)->get()->keyBy('variant_id');
        expect($done->status)->toBe(CountStatus::Submitted)->and($done->submitted_by)->toBe($this->amina->id)->and($lines[$this->tee]->variance)->toBe(-3)->and($lines[$this->mug]->system_qty)->toBe(48)->and($lines[$this->mug]->variance)->toBe(0)
            ->and(($this->level)($this->tee)->on_hand)->toBe(50);   // nothing is corrected until a second person agrees
    });

    it('needs nobody\'s approval when nothing differs', function () {
        $count = app(OpenStockCount::class)->handle($this->amina, 'main', [$this->tee]);
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 50);

        $done = app(SubmitStockCount::class)->handle($this->amina, $count->public_id);

        expect($done->status)->toBe(CountStatus::Approved)->and($done->decided_by)->toBeNull()->and($done->decision_note)->toBe('No differences')->and(DB::table('stock_movements')->where('reason', 'count')->count())->toBe(0);
    });

    it('is approved by a second person, who corrects stock by each difference with a ledger row each', function () {
        $count = app(OpenStockCount::class)->handle($this->amina, 'main');
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 47);
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->mug, 52);
        app(SubmitStockCount::class)->handle($this->amina, $count->public_id);

        ($this->refuses)(fn () => app(DecideStockCount::class)->approve($this->amina, $count->public_id), 'second_person_required');   // not the person who counted
        expect(($this->level)($this->tee)->on_hand)->toBe(50);

        $approved = app(DecideStockCount::class)->approve($this->babul, $count->public_id);

        expect($approved->status)->toBe(CountStatus::Approved)->and($approved->decided_by)->toBe($this->babul->id)->and(($this->level)($this->tee)->on_hand)->toBe(47)->and(($this->level)($this->mug)->on_hand)->toBe(52)
            ->and(DB::table('stock_movements')->where('ref_type', 'stock_count')->where('ref_id', $count->public_id)->where('reason', 'count')->orderBy('variant_id')->pluck('delta')->map(fn ($d) => (int) $d)->all())->toBe([-3, 2])
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
        ($this->refuses)(fn () => app(DecideStockCount::class)->approve($this->babul, $count->public_id), 'count_not_pending');
    });

    it('can be refused with a reason by a second person, and then changes nothing', function () {
        $count = app(OpenStockCount::class)->handle($this->amina, 'main', [$this->tee]);
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 10);
        app(SubmitStockCount::class)->handle($this->amina, $count->public_id);

        ($this->refuses)(fn () => app(DecideStockCount::class)->reject($this->babul, $count->public_id, '  '), 'reason_required');
        ($this->refuses)(fn () => app(DecideStockCount::class)->reject($this->amina, $count->public_id, 'No'), 'second_person_required');
        $rejected = app(DecideStockCount::class)->reject($this->babul, $count->public_id, 'Recount: the shelf was not finished');

        expect($rejected->status)->toBe(CountStatus::Rejected)->and($rejected->decision_note)->toBe('Recount: the shelf was not finished')->and(($this->level)($this->tee)->on_hand)->toBe(50)->and(DB::table('stock_movements')->where('reason', 'count')->count())->toBe(0);
        expect(app(OpenStockCount::class)->handle($this->amina, 'main', [$this->tee])->status)->toBe(CountStatus::Open);   // a rejected count frees the place
    });

    it('changes nothing at all when one correction would take stock below what is promised to orders', function () {
        ($this->hold)($this->tee, 40);
        $count = app(OpenStockCount::class)->handle($this->amina, 'main');
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->mug, 45);   // fine on its own
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 30);   // 30 on the shelf, 40 promised
        app(SubmitStockCount::class)->handle($this->amina, $count->public_id);

        ($this->refuses)(fn () => app(DecideStockCount::class)->approve($this->babul, $count->public_id), 'variance_conflicts');

        expect(($this->level)($this->mug)->on_hand)->toBe(50)->and(($this->level)($this->tee)->on_hand)->toBe(50)->and(DB::table('stock_counts')->where('id', $count->id)->value('status'))->toBe('submitted')->and(DB::table('stock_movements')->where('reason', 'count')->count())->toBe(0);
    });

    it('cannot be changed once handed in, and an open one can be called off', function () {
        $count = app(OpenStockCount::class)->handle($this->amina, 'main', [$this->tee]);
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 49);
        app(SubmitStockCount::class)->handle($this->amina, $count->public_id);

        ($this->refuses)(fn () => app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 1), 'count_not_open');
        ($this->refuses)(fn () => app(SubmitStockCount::class)->handle($this->amina, $count->public_id), 'count_not_open');
        ($this->refuses)(fn () => app(CancelStockCount::class)->handle($this->amina, $count->public_id), 'count_not_open');
    });
});

describe('the reports', function () {
    it('lists products at or below their low level, least left first, never counting quarantined stock', function () {
        DB::table('stock_levels')->where('variant_id', $this->tee)->update(['low_stock_threshold' => 10]);
        DB::table('stock_levels')->where('variant_id', $this->mug)->update(['low_stock_threshold' => 10]);
        expect(app(LowStockReport::class)->rows())->toBe([]);

        ($this->hold)($this->tee, 45);   // 5 left
        ($this->hold)($this->mug, 40);   // 10 left
        app(AdjustStock::class)->handle($this->amina, $this->tee, 'quarantine', 4, 'receipt');
        DB::table('stock_levels')->where('variant_id', $this->tee)->where('location_id', DB::table('stock_locations')->where('code', 'quarantine')->value('id'))->update(['low_stock_threshold' => 100]);

        $rows = app(LowStockReport::class)->rows();

        expect(array_column($rows, 'variant_id'))->toBe([$this->tee, $this->mug])->and($rows[0])->toMatchArray(['location' => 'main', 'available' => 5, 'threshold' => 10])->and(app(LowStockReport::class)->rows('shop'))->toBe([]);
    });

    it('says how long stock has been sitting, by days since the last sale or since it arrived', function () {
        $this->travel(40 * 24 * 60)->minutes();
        app(AdjustStock::class)->handle($this->amina, $this->mug, 'main', -1, 'shipment', 'A sale today');   // the mugs sold just now; the tees have not sold at all

        $report = app(StockAgingReport::class)->report();

        expect($report['buckets']['31-60'])->toBe(['lines' => 1, 'units' => 50])->and($report['buckets']['0-30'])->toBe(['lines' => 1, 'units' => 49])->and($report['buckets']['91+'])->toBe(['lines' => 0, 'units' => 0])
            ->and($report['oldest'][0])->toMatchArray(['variant_id' => $this->tee, 'location' => 'main', 'units' => 50, 'days' => 40]);
    });

    it('does not run more queries for more stock', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(LowStockReport::class)->rows();
            app(StockAgingReport::class)->report();

            return count(DB::getQueryLog());
        };
        $few = $count();
        $extra = collect(range(1, 6))->map(fn ($i) => (function (int $i) {
            $system = Actor::system('t');
            $p = app(SaveProduct::class)->handle($system, null, new ProductData(ProductType::Physical, [new TranslationInput('en', 'Extra '.$i), new TranslationInput('bn', 'অতিরিক্ত '.$i)], categoryIds: [(int) DB::table('categories')->value('id')], defaultVariant: new VariantData('EXTRA-'.$i, trackInventory: true)));
            $id = (int) $p->variants()->value('id');
            app(StockInitialiser::class)->openingStock($system, $id, 3);
            DB::table('stock_levels')->where('variant_id', $id)->update(['low_stock_threshold' => 10]);

            return $id;
        })($i));

        expect($extra)->toHaveCount(6)->and(count(app(LowStockReport::class)->rows()))->toBe(6)->and($count())->toBe($few);
    });
});

describe('the database', function () {
    it('allows exactly the transfer and count moves the enums allow', function () {
        $a = DB::table('stock_locations')->where('code', 'main')->value('id');
        $b = DB::table('stock_locations')->where('code', 'shop')->value('id');
        foreach (TransferStatus::cases() as $from) {
            foreach (TransferStatus::cases() as $to) {
                if ($from === $to) {
                    continue;
                }
                $id = DB::table('stock_transfers')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'from_location_id' => $a, 'to_location_id' => $b, 'status' => $from->value, 'created_at' => now(), 'updated_at' => now(), 'received_at' => $from === TransferStatus::Received ? now() : null, 'cancelled_at' => $from === TransferStatus::Cancelled ? now() : null]);
                try {
                    DB::table('stock_transfers')->where('id', $id)->update(['status' => $to->value, 'received_at' => $to === TransferStatus::Received ? now() : DB::raw('received_at'), 'cancelled_at' => $to === TransferStatus::Cancelled ? now() : DB::raw('cancelled_at')]);
                    $moved = true;
                } catch (QueryException) {
                    $moved = false;
                }
                expect($moved)->toBe($from->canMoveTo($to), "transfer {$from->value} -> {$to->value}");
            }
        }
        foreach (CountStatus::cases() as $from) {
            foreach (CountStatus::cases() as $to) {
                if ($from === $to) {
                    continue;
                }
                $loc = DB::table('stock_locations')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => 'x'.Str::random(8), 'name' => 'X', 'type' => 'warehouse', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $id = DB::table('stock_counts')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'location_id' => $loc, 'status' => $from->value, 'created_at' => now(), 'updated_at' => now()]);
                try {
                    DB::table('stock_counts')->where('id', $id)->update(['status' => $to->value]);
                    $moved = true;
                } catch (QueryException) {
                    $moved = false;
                }
                expect($moved)->toBe($from->canMoveTo($to), "count {$from->value} -> {$to->value}");
            }
        }
    });

    it('refuses by itself a count approved by the person who handed it in, a second active count for a place, and a rewrite of what arrived', function () {
        $count = app(OpenStockCount::class)->handle($this->amina, 'main', [$this->tee]);
        app(RecordStockCount::class)->handle($this->amina, $count->public_id, $this->tee, 40);
        app(SubmitStockCount::class)->handle($this->amina, $count->public_id);

        expect(fn () => DB::table('stock_counts')->where('id', $count->id)->update(['status' => 'approved', 'decided_by' => $this->amina->id]))->toThrow(QueryException::class)   // the same person
            ->and(fn () => DB::table('stock_counts')->insert(['public_id' => strtolower((string) Str::ulid()), 'location_id' => $count->location_id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_count_lines')->where('count_id', $count->id)->update(['variance' => 99]))->toThrow(QueryException::class);   // variance must equal counted − system

        $t = ($this->send)([$this->tee => 5]);
        app(ReceiveStockTransfer::class)->handle($this->babul, $t->public_id, [$this->tee => 4]);
        expect(fn () => DB::table('stock_transfer_items')->where('transfer_id', $t->id)->update(['received_qty' => 5, 'lost_qty' => 0]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_transfer_items')->where('transfer_id', $t->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_transfers')->where('id', $t->id)->delete())->toThrow(QueryException::class);
    });
});
