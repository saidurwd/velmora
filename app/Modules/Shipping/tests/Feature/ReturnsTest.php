<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\Settings;
use App\Modules\Shipping\Actions\DecideReturn;
use App\Modules\Shipping\Actions\InspectReturnItem;
use App\Modules\Shipping\Actions\ReceiveReturn;
use App\Modules\Shipping\Actions\RequestReturn;
use App\Modules\Shipping\Enums\ReturnStatus;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\ReturnRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    shippingWorld($this);
    $this->seed(PaymentMethodSeeder::class);
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->stranger = User::factory()->create(['email' => 'stranger@example.com']);
    $this->me = new Actor(ActorType::Customer, $this->owner->id, $this->owner->public_id, 'Owner');
    $this->other = new Actor(ActorType::Customer, $this->stranger->id, $this->stranger->public_id, 'Stranger');
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(ShipmentException::class, $reason);

    /** A cash order of two tees and a mug, owned by $owner, all in one parcel that was delivered. Returns the order row. */
    $this->delivered = function (string $method = 'cod') {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1], $method);
        DB::table('orders')->where('id', $order->id)->update(['user_id' => $this->owner->id]);
        $parcel = ($this->parcel)($order, [$this->tee => 2, $this->mug => 1]);
        ($this->say)($parcel, 'DELIVERED', now()->addMinute()->format('Y-m-d H:i:s'));
        $this->parcelRow = $parcel;

        return DB::table('orders')->where('id', $order->id)->first();
    };
    $this->item = fn (object $order, int $variantId) => ($this->itemOf)($order->id, $variantId);
    $this->ask = fn (object $order, array $byVariant, ?Actor $by = null, string $reason = 'damaged') => app(RequestReturn::class)->handle($by ?? $this->me, $order->public_id, collect($byVariant)->mapWithKeys(fn ($q, $v) => [($this->item)($order, $v) => $q])->all(), $reason, 'It arrived broken');
    $this->allow = fn (object $return, string $payer = 'shop') => app(DecideReturn::class)->authorize($this->staff, $return->public_id, $payer);
    $this->back = fn (object $return, ?array $received = null) => app(ReceiveReturn::class)->handle($this->staff, $return->public_id, $received);
    $this->look = fn (object $return, object $order, int $variantId, string $outcome) => app(InspectReturnItem::class)->handle($this->staff, $return->public_id, ($this->item)($order, $variantId), $outcome);
    $this->stock = fn (int $variantId, string $location = 'main') => DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')->where('variant_id', $variantId)->where('stock_locations.code', $location)->first(['on_hand', 'reserved']);
    $this->fulfilment = fn (int $orderId) => DB::table('orders')->where('id', $orderId)->value('fulfillment_status');
});

describe('asking to send goods back', function () {
    it('lets the customer return part of a delivered order, and records who asked and why', function () {
        $order = ($this->delivered)();

        $return = ($this->ask)($order, [$this->tee => 1]);

        expect($return->status)->toBe(ReturnStatus::Requested)->and($return->kind)->toBe('customer')->and($return->requested_by_type)->toBe('customer')->and($return->requested_by)->toBe($this->owner->id)->and($return->reason_code)->toBe('damaged')
            ->and($return->items()->count())->toBe(1)->and(DB::table('return_items')->value('quantity'))->toBe(1)->and(DB::table('audit_events')->where('action', 'return.requested')->count())->toBe(1)
            ->and(DB::table('order_items')->where('order_id', $order->id)->sum('returned_qty'))->toEqual(0);   // nothing is back yet
    });

    it('treats another customer\'s order, a guest and an unknown order alike: not found', function () {
        $order = ($this->delivered)();

        ($this->refuses)(fn () => ($this->ask)($order, [$this->tee => 1], $this->other), 'order_not_found');
        ($this->refuses)(fn () => ($this->ask)($order, [$this->tee => 1], Actor::guest('x')), 'order_not_found');
        ($this->refuses)(fn () => app(RequestReturn::class)->handle($this->me, '01HZZZZZZZZZZZZZZZZZZZZZZZ', [1 => 1], 'damaged', null), 'order_not_found');

        expect(DB::table('return_requests')->count())->toBe(0);
    });

    it('refuses goods that were not delivered, and more than was delivered or is already out on returns', function () {
        $undelivered = ($this->order)([$this->tee => 1]);
        DB::table('orders')->where('id', $undelivered->id)->update(['user_id' => $this->owner->id]);
        ($this->parcel)($undelivered, [$this->tee => 1]);   // handed over, not delivered
        ($this->refuses)(fn () => ($this->ask)($undelivered, [$this->tee => 1]), 'not_delivered');
        ($this->refuses)(fn () => app(RequestReturn::class)->handle($this->me, $undelivered->public_id, [($this->item)($undelivered, $this->tee) => 1], 'damaged', null), 'not_delivered');

        $order = ($this->delivered)();
        ($this->refuses)(fn () => ($this->ask)($order, [$this->tee => 3]), 'return_exceeds_delivered');
        ($this->ask)($order, [$this->tee => 2]);
        ($this->refuses)(fn () => ($this->ask)($order, [$this->tee => 1]), 'return_exceeds_delivered');   // both units are already asked for
        expect(DB::table('return_requests')->count())->toBe(1);
    });

    it('frees the units again when a return is rejected or withdrawn', function () {
        $order = ($this->delivered)();
        $first = ($this->ask)($order, [$this->tee => 2]);

        app(DecideReturn::class)->reject($this->staff, $first->public_id, 'Not eligible');
        $second = ($this->ask)($order, [$this->tee => 2]);
        app(DecideReturn::class)->cancel($this->staff, $second->public_id, 'Customer changed their mind');

        expect(($this->ask)($order, [$this->tee => 2])->status)->toBe(ReturnStatus::Requested);
    });

    it('keeps the return window for customers only, and lets the shop change it', function () {
        $order = ($this->delivered)();
        $this->travel(8 * 24 * 60)->minutes();

        ($this->refuses)(fn () => ($this->ask)($order, [$this->tee => 1]), 'return_window_closed');
        expect(($this->ask)($order, [$this->tee => 1], $this->staff)->requested_by_type)->toBe('staff');

        app(Settings::class)->set('shipping.return_window_days', 30);
        expect(($this->ask)($order, [$this->mug => 1])->status)->toBe(ReturnStatus::Requested);
    });

    it('refuses a missing reason, no lines, a zero quantity and a digital line', function () {
        $order = ($this->delivered)();
        $tee = ($this->item)($order, $this->tee);

        ($this->refuses)(fn () => app(RequestReturn::class)->handle($this->me, $order->public_id, [$tee => 1], 'because', null), 'reason_invalid');
        ($this->refuses)(fn () => app(RequestReturn::class)->handle($this->me, $order->public_id, [], 'damaged', null), 'no_lines');
        ($this->refuses)(fn () => app(RequestReturn::class)->handle($this->me, $order->public_id, [$tee => 0], 'damaged', null), 'quantity_invalid');
        ($this->refuses)(fn () => app(RequestReturn::class)->handle($this->me, $order->public_id, [999999 => 1], 'damaged', null), 'line_not_in_order');
    });
});

describe('deciding', function () {
    it('allows with who pays the postage, or refuses with a reason; the first decision wins', function () {
        $order = ($this->delivered)();
        $a = ($this->ask)($order, [$this->tee => 1]);
        $b = ($this->ask)($order, [$this->mug => 1]);

        $allowed = ($this->allow)($a, 'customer');
        $rejected = app(DecideReturn::class)->reject($this->staff, $b->public_id, 'Used item');

        expect($allowed->status)->toBe(ReturnStatus::Authorized)->and($allowed->shipping_cost_payer)->toBe('customer')->and((int) $allowed->decided_by)->toBe($this->staff->id)->and($rejected->status)->toBe(ReturnStatus::Rejected)->and($rejected->decision_note)->toBe('Used item');
        ($this->refuses)(fn () => app(DecideReturn::class)->reject($this->staff, $a->public_id, 'Changed my mind'), 'return_not_open');
        ($this->refuses)(fn () => ($this->allow)($b), 'return_not_open');
        ($this->refuses)(fn () => app(DecideReturn::class)->reject($this->staff, ($this->ask)($order, [$this->mug => 1])->public_id, '  '), 'reason_required');
        ($this->refuses)(fn () => app(DecideReturn::class)->authorize($this->staff, $a->public_id, 'the moon'), 'payer_invalid');
        ($this->refuses)(fn () => ($this->allow)((object) ['public_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']), 'return_not_found');
    });
});

describe('receiving the goods', function () {
    it('counts them as returned on the order and moves the order to partly or fully returned', function () {
        $order = ($this->delivered)();
        $partial = ($this->allow)(($this->ask)($order, [$this->tee => 1]));

        ($this->back)($partial);
        expect(($this->fulfilment)($order->id))->toBe('partially_returned')->and((int) DB::table('order_items')->where('id', ($this->item)($order, $this->tee))->value('returned_qty'))->toBe(1)
            ->and(DB::table('return_requests')->where('id', $partial->id)->value('status'))->toBe('received');

        $rest = ($this->allow)(($this->ask)($order, [$this->tee => 1, $this->mug => 1]));
        ($this->back)($rest);

        expect(($this->fulfilment)($order->id))->toBe('returned')->and(DB::table('order_items')->where('order_id', $order->id)->sum('returned_qty'))->toEqual(3);
    });

    it('takes what really came back, and a return where nothing came back closes at once', function () {
        $order = ($this->delivered)();
        $some = ($this->allow)(($this->ask)($order, [$this->tee => 2]));

        ($this->refuses)(fn () => ($this->back)($some, [($this->item)($order, $this->tee) => 3]), 'received_invalid');
        ($this->back)($some, [($this->item)($order, $this->tee) => 1]);
        expect((int) DB::table('return_items')->where('return_id', $some->id)->value('received_qty'))->toBe(1)->and(DB::table('order_items')->where('id', ($this->item)($order, $this->tee))->value('returned_qty'))->toBe(1);

        $none = ($this->allow)(($this->ask)($order, [$this->mug => 1]));
        $closed = ($this->back)($none, [($this->item)($order, $this->mug) => 0]);

        expect($closed->status)->toBe(ReturnStatus::Closed)->and($closed->refund_suggested_minor)->toBe(0)->and(DB::table('return_items')->where('return_id', $none->id)->value('outcome'))->toBe('not_received');
    });

    it('needs the return allowed first, and happens once', function () {
        $order = ($this->delivered)();
        $return = ($this->ask)($order, [$this->tee => 1]);

        ($this->refuses)(fn () => ($this->back)($return), 'return_not_authorized');
        ($this->allow)($return);
        ($this->back)($return);
        ($this->refuses)(fn () => ($this->back)($return), 'return_not_open');
        expect(DB::table('order_items')->where('id', ($this->item)($order, $this->tee))->value('returned_qty'))->toBe(1);
    });
});

describe('looking at the goods', function () {
    it('puts a restocked unit back on the shelf where it left from, for sale again, with a ledger row', function () {
        $order = ($this->delivered)();
        $before = ($this->stock)($this->tee)->on_hand;
        $return = ($this->allow)(($this->ask)($order, [$this->tee => 1]));
        ($this->back)($return);
        $availableBefore = app(Availability::class)->forVariant($this->tee);

        ($this->look)($return, $order, $this->tee, 'restock');

        expect(($this->stock)($this->tee)->on_hand)->toBe($before + 1)->and(app(Availability::class)->forVariant($this->tee))->toBe($availableBefore + 1)
            ->and(DB::table('stock_movements')->where('ref_type', 'return')->where('ref_id', $return->public_id)->where('reason', 'return_restock')->sum('delta'))->toEqual(1)
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('keeps a quarantined unit apart: counted, traceable, never for sale and never reserved', function () {
        $order = ($this->delivered)();
        $availableBefore = app(Availability::class)->forVariant($this->tee);
        $return = ($this->allow)(($this->ask)($order, [$this->tee => 1]));
        ($this->back)($return);

        ($this->look)($return, $order, $this->tee, 'quarantine');

        expect(($this->stock)($this->tee, 'quarantine')->on_hand)->toBe(1)->and(app(Availability::class)->forVariant($this->tee))->toBe($availableBefore)
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
        // a buyer cannot be given the quarantined unit
        $stockMain = ($this->stock)($this->tee)->on_hand;
        expect(fn () => app(StockReservations::class)->holdForOrder([$this->tee => $stockMain - ($this->stock)($this->tee)->reserved + 1], '01HQUARANTINEXXXXXXXXXXXXX'))->toThrow(DomainException::class);
    });

    it('writes a unit off by counting it in and out again, so the ledger shows it and nothing is left', function () {
        $order = ($this->delivered)();
        $return = ($this->allow)(($this->ask)($order, [$this->mug => 1]));
        ($this->back)($return);

        ($this->look)($return, $order, $this->mug, 'write_off');

        $rows = DB::table('stock_movements')->where('ref_type', 'return')->where('ref_id', $return->public_id)->orderBy('id')->get();
        expect($rows->pluck('reason')->all())->toBe(['return_restock', 'write_off'])->and($rows->sum('delta'))->toEqual(0)->and(($this->stock)($this->mug, 'quarantine')->on_hand)->toBe(0)
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('decides each line once, closes the return after the last, and only suggests a refund', function () {
        $order = ($this->delivered)();
        $return = ($this->allow)(($this->ask)($order, [$this->tee => 1, $this->mug => 1]));
        ($this->back)($return);

        $mid = ($this->look)($return, $order, $this->tee, 'restock');
        expect($mid->status)->toBe(ReturnStatus::Received)->and($mid->refund_suggested_minor)->toBeNull();
        ($this->refuses)(fn () => ($this->look)($return, $order, $this->tee, 'quarantine'), 'already_inspected');

        $done = ($this->look)($return, $order, $this->mug, 'write_off');

        expect($done->status)->toBe(ReturnStatus::Closed)->and($done->closed_at)->not->toBeNull()->and($done->refund_suggested_minor)->toBe(65000 + 35000)   // what was paid for one tee and one mug; delivery is not included
            ->and(DB::table('refunds')->count())->toBe(0)->and((int) DB::table('payments')->sum('refunded_minor'))->toBe(0)
            ->and(DB::table('audit_events')->whereIn('action', ['return.received', 'return.inspected', 'return.closed'])->count())->toBe(4);
    });

    it('refuses before the goods are received, and an outcome that is not one', function () {
        $order = ($this->delivered)();
        $return = ($this->allow)(($this->ask)($order, [$this->tee => 1]));

        ($this->refuses)(fn () => ($this->look)($return, $order, $this->tee, 'restock'), 'return_not_received');
        ($this->back)($return);
        ($this->refuses)(fn () => ($this->look)($return, $order, $this->tee, 'not_received'), 'outcome_invalid');
        ($this->refuses)(fn () => ($this->look)($return, $order, $this->tee, 'burn'), 'outcome_invalid');
        ($this->refuses)(fn () => app(InspectReturnItem::class)->handle($this->staff, $return->public_id, 999999, 'restock'), 'line_not_in_return');
    });
});

describe('a parcel the courier brings back', function () {
    it('opens a return for everything in it, once, already allowed', function () {
        $order = ($this->order)([$this->tee => 1], 'online');
        $parcel = ($this->parcel)($order, [$this->tee => 1]);

        ($this->say)($parcel, 'DELIVERY_FAILED', now()->addMinute()->format('Y-m-d H:i:s'));
        ($this->say)($parcel, 'RETURNED', now()->addMinutes(2)->format('Y-m-d H:i:s'));
        DB::table('outbox_messages')->where('event_name', 'shipping.returned')->update(['status' => 'pending', 'available_at' => now()->subMinute()]);
        $this->artisan('outbox:dispatch', ['--limit' => 10])->assertSuccessful();

        $return = DB::table('return_requests')->first();
        expect(DB::table('return_requests')->count())->toBe(1)->and($return->kind)->toBe('origin')->and($return->status)->toBe('authorized')->and($return->shipment_id)->toBe($parcel->id)->and($return->shipping_cost_payer)->toBe('shop')
            ->and(DB::table('return_items')->where('return_id', $return->id)->sum('quantity'))->toEqual(1);
    });

    it('is received and looked at like any return, resolves the parcel\'s alert, and suggests the goods\' value for a prepaid order only', function () {
        $prepaid = ($this->order)([$this->tee => 1], 'online');
        $p1 = ($this->parcel)($prepaid, [$this->tee => 1]);
        ($this->say)($p1, 'RETURNED', now()->addMinutes(2)->format('Y-m-d H:i:s'));
        $cash = ($this->order)([$this->mug => 1]);
        $p2 = ($this->parcel)($cash, [$this->mug => 1]);
        ($this->say)($p2, 'RETURNED', now()->addMinutes(2)->format('Y-m-d H:i:s'));
        expect(DB::table('shipment_exceptions')->where('type', 'returned_to_origin')->where('status', 'open')->count())->toBe(2);

        foreach ([[$prepaid, $this->tee, $p1], [$cash, $this->mug, $p2]] as [$order, $variant, $parcel]) {
            $return = ReturnRequest::query()->where('shipment_id', $parcel->id)->firstOrFail();
            ($this->back)($return);
            ($this->look)($return, $order, $variant, 'restock');
        }

        $suggested = DB::table('return_requests')->orderBy('id')->pluck('refund_suggested_minor')->map(fn ($v) => (int) $v)->all();
        expect($suggested)->toBe([65000, 0])   // the prepaid customer paid and never got the goods; the cash customer paid nothing
            ->and(DB::table('shipment_exceptions')->where('type', 'returned_to_origin')->where('status', 'resolved')->count())->toBe(2)->and(($this->fulfilment)($prepaid->id))->toBe('returned')
            ->and(($this->stock)($this->tee)->on_hand)->toBe(50)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('the database', function () {
    it('allows exactly the return moves the enum allows', function () {
        $order = ($this->delivered)();
        foreach (ReturnStatus::cases() as $from) {
            foreach (ReturnStatus::cases() as $to) {
                if ($from === $to) {
                    continue;
                }
                $id = DB::table('return_requests')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'order_id' => $order->id, 'kind' => 'customer', 'status' => $from->value, 'reason_code' => 'other', 'requested_by_type' => 'staff', 'currency' => 'BDT', 'created_at' => now(), 'updated_at' => now(), 'closed_at' => $from === ReturnStatus::Closed ? now() : null, 'received_at' => $from === ReturnStatus::Closed ? now() : null, 'refund_suggested_minor' => $from === ReturnStatus::Closed ? 0 : null]);
                $extra = $to === ReturnStatus::Closed ? ['closed_at' => now(), 'received_at' => now(), 'refund_suggested_minor' => 0] : [];
                try {
                    DB::table('return_requests')->where('id', $id)->update(['status' => $to->value] + $extra);
                    $moved = true;
                } catch (QueryException) {
                    $moved = false;
                }

                expect($moved)->toBe($from->canMoveTo($to), "{$from->value} -> {$to->value}");
            }
        }
    });

    it('freezes what came back and what was decided once set, and never deletes', function () {
        $order = ($this->delivered)();
        $return = ($this->allow)(($this->ask)($order, [$this->tee => 1]));
        ($this->back)($return);
        ($this->look)($return, $order, $this->tee, 'restock');
        $item = DB::table('return_items')->first();

        expect(fn () => DB::table('return_items')->where('id', $item->id)->update(['outcome' => 'write_off']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('return_items')->where('id', $item->id)->update(['received_qty' => 0]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('return_items')->where('id', $item->id)->update(['quantity' => 9]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('return_items')->where('id', $item->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('return_requests')->where('id', $return->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('return_requests')->where('id', $return->id)->update(['order_id' => 999]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('return_requests')->where('id', $return->id)->update(['status' => 'received']))->toThrow(QueryException::class);   // a closed return never reopens
    });

    it('never counts more as returned than was shipped', function () {
        $order = ($this->delivered)();

        expect(fn () => DB::table('order_items')->where('id', ($this->item)($order, $this->tee))->update(['returned_qty' => 3]))->toThrow(QueryException::class);
    });
});
