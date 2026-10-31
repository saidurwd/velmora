<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Actions\ApproveRefund;
use App\Modules\Payments\Actions\CompleteManualRefund;
use App\Modules\Payments\Actions\RecordCodCollection;
use App\Modules\Payments\Actions\RecordCodRemittance;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\CodCollection;
use App\Modules\Payments\Services\CodAging;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->staffA = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
    $this->staffB = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Babul');

    /** A cash-on-delivery order of ৳1,200.00 that is on its way. Returns the order's public id. */
    $this->cod = function (int $id = 1, array $over = []): string {
        fakeOrders([$id], array_replace(['payment_method_code' => 'cod', 'status' => 'confirmed', 'subtotal_minor' => 120000, 'grand_total_minor' => 120000, 'created_at' => now()->subDays(2), 'placed_at' => now()->subDays(2)], $over));

        return (string) DB::table('orders')->where('id', $id)->value('public_id');
    };
    $this->collect = fn (string $order, array $more = []) => app(RecordCodCollection::class)->handle($this->staffA, $order, ...$more);
    $this->remit = fn (string $courier, string $statement, int $received, array $parcels, ?CarbonImmutable $at = null) => app(RecordCodRemittance::class)->handle(
        $this->staffB, $courier, $statement, $received, $at, array_map(fn (array $p) => ['collection' => $p[0], 'amount_minor' => $p[1]], $parcels),
    );
    $this->order = fn (int $id = 1) => DB::table('orders')->where('id', $id)->first(['status', 'payment_status']);
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(PaymentException::class, $reason);
    $this->exceptions = fn (string $type) => DB::table('reconciliation_exceptions')->where('type', $type)->get();
});

describe('recording the cash', function () {
    it('turns the cash into a normal paid payment with one succeeded attempt, and the order follows', function () {
        $order = ($this->cod)();

        $c = ($this->collect)($order, [null, 'Handed over by Pathao rider']);

        $p = DB::table('payments')->where('order_id', 1)->first();
        $tx = DB::table('payment_transactions')->where('payment_id', $p->id)->first();
        expect($c->amount_minor)->toBe(120000)->and($c->payment_id)->toBe($p->id)->and($c->note)->toBe('Handed over by Pathao rider')
            ->and($p->status)->toBe('paid')->and($p->captured_minor)->toBe(120000)->and($p->method_code)->toBe('cod')->and($p->provider)->toBe('cod')
            ->and($tx->status)->toBe('succeeded')->and($tx->provider_txn_id)->toBe($c->public_id)->and($tx->merchant_ref)->toBe('COD-VM-TEST-000001')
            ->and(($this->order)()->payment_status)->toBe('paid')
            ->and(DB::table('order_status_history')->where('order_id', 1)->where('axis', 'payment')->where('to_state', 'paid')->count())->toBe(1);
    });

    it('takes the amount from the order, so it cannot be mistyped, and does not confirm or complete the order by itself', function () {
        $order = ($this->cod)(1, ['status' => 'processing']);

        ($this->collect)($order);

        expect((int) DB::table('payments')->where('order_id', 1)->value('captured_minor'))->toBe(120000)->and(($this->order)()->status)->toBe('processing');
    });

    it('records a collected date and refuses a date in the future or before the order existed', function () {
        $order = ($this->cod)();
        ($this->refuses)(fn () => ($this->collect)($order, [now()->addDay()->toImmutable()]), 'cod_date_invalid');
        ($this->refuses)(fn () => ($this->collect)($order, [now()->subDays(5)->toImmutable()]), 'cod_date_invalid');

        $c = ($this->collect)($order, [now()->subDay()->toImmutable()]);

        expect($c->collected_at->format('Y-m-d'))->toBe(now()->subDay()->format('Y-m-d'));
    });

    it('refuses an unknown order, an online order, a cancelled order', function () {
        ($this->refuses)(fn () => ($this->collect)('01HZZZZZZZZZZZZZZZZZZZZZZZ'), 'cod_order_not_found');
        $online = ($this->cod)(2, ['payment_method_code' => 'online']);
        ($this->refuses)(fn () => ($this->collect)($online), 'cod_not_cod_order');
        $cancelled = ($this->cod)(3, ['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);
        ($this->refuses)(fn () => ($this->collect)($cancelled), 'cod_order_cancelled');

        expect(DB::table('payments')->count())->toBe(0)->and(CodCollection::query()->count())->toBe(0);
    });

    it('records the cash once: a second try is refused and nothing is doubled', function () {
        $order = ($this->cod)();
        ($this->collect)($order);

        ($this->refuses)(fn () => ($this->collect)($order), 'cod_already_collected');

        expect(CodCollection::query()->count())->toBe(1)->and(DB::table('payments')->count())->toBe(1)->and(DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->count())->toBe(1);
    });

    it('is stopped by the database too when the order check is bypassed', function () {
        $order = ($this->cod)();
        $c = ($this->collect)($order);
        $row = (array) DB::table('cod_collections')->where('id', $c->id)->first();
        unset($row['id']);

        expect(fn () => DB::table('cod_collections')->insert(array_replace($row, ['public_id' => strtolower((string) Str::ulid())])))->toThrow(QueryException::class);
    });

    it('never leaves half a collection behind when a step fails', function () {
        $order = ($this->cod)();
        DB::table('payments')->insert(['public_id' => strtolower((string) Str::ulid()), 'order_id' => 1, 'method_code' => 'cod', 'provider' => 'cod', 'amount_minor' => 1, 'currency' => 'BDT', 'status' => 'initiated', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_transactions')->insert(['public_id' => strtolower((string) Str::ulid()), 'payment_id' => DB::table('payments')->value('id'), 'provider' => 'cod', 'merchant_ref' => 'COD-VM-TEST-000001', 'attempt' => 1, 'status' => 'initiated', 'amount_minor' => 1, 'currency' => 'BDT', 'created_at' => now(), 'updated_at' => now()]);   // the reference is already taken

        expect(fn () => ($this->collect)($order))->toThrow(PaymentException::class);

        expect(CodCollection::query()->count())->toBe(0)->and(DB::table('payments')->count())->toBe(1)->and(DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->count())->toBe(0);
    });

    it('cannot be changed or deleted afterwards', function () {
        $c = ($this->collect)(($this->cod)());

        expect(fn () => DB::table('cod_collections')->where('id', $c->id)->update(['amount_minor' => 1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('cod_collections')->where('id', $c->id)->update(['payment_id' => null]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('cod_collections')->where('id', $c->id)->delete())->toThrow(QueryException::class);
    });

    it('is recorded in the audit trail', function () {
        ($this->collect)(($this->cod)());

        expect(DB::table('audit_events')->where('action', 'cod.collected')->count())->toBe(1);
    });
});

describe('refunding cash that was collected', function () {
    it('works like any other refund, by hand with proof, and the order follows', function () {
        ($this->collect)(($this->cod)());
        $payment = DB::table('payments')->first();

        $r = app(RequestRefund::class)->handle($this->staffA, $payment->public_id, 120000, 'Customer returned the parcel');
        app(ApproveRefund::class)->handle($this->staffB, $r->public_id);
        app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, 'bKash TrxID 9A8B7C');

        expect($r->method->value)->toBe('manual')->and(($this->order)()->payment_status)->toBe('refunded')->and((int) DB::table('payments')->value('refunded_minor'))->toBe(120000);
    });

    it('cannot refund more than the cash collected', function () {
        ($this->collect)(($this->cod)());

        expect(fn () => app(RequestRefund::class)->handle($this->staffA, DB::table('payments')->value('public_id'), 120001, 'Too much'))->toThrow(PaymentException::class, 'refund_exceeds_captured');
    });

    it('lets a fully refunded cash order be cancelled', function () {
        ($this->collect)(($this->cod)());
        $r = app(RequestRefund::class)->handle($this->staffA, DB::table('payments')->value('public_id'), 120000, 'Returned');
        app(ApproveRefund::class)->handle($this->staffB, $r->public_id);
        app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, 'slip 7');

        app(TransitionOrder::class)->status($this->staffA, 1, OrderStatus::Cancelled, 'Returned after delivery');

        expect(($this->order)()->status)->toBe('cancelled');
    });
});

describe('courier remittances', function () {
    beforeEach(function () {
        $this->parcels = [];
        foreach ([1 => 120000, 2 => 80000, 3 => 50000] as $id => $total) {
            $order = ($this->cod)($id, ['subtotal_minor' => $total, 'grand_total_minor' => $total]);
            $this->parcels[$id] = ($this->collect)($order);
        }
    });

    it('reconciles a statement that adds up to what we collected', function () {
        $r = ($this->remit)('Pathao', 'ST-1001', 250000, [[$this->parcels[1]->public_id, 120000], [$this->parcels[2]->public_id, 80000], [$this->parcels[3]->public_id, 50000]]);

        expect($r->status)->toBe('reconciled')->and($r->expected_minor)->toBe(250000)->and($r->amount_minor)->toBe(250000)->and(DB::table('cod_remittance_items')->where('remittance_id', $r->id)->count())->toBe(3)
            ->and(DB::table('reconciliation_exceptions')->count())->toBe(0)->and(DB::table('audit_events')->where('action', 'cod.remitted')->count())->toBe(1);
    });

    it('expects what OUR collections add up to, not what the statement claims', function () {
        $r = ($this->remit)('Pathao', 'ST-1002', 100000, [[$this->parcels[1]->public_id, 50000], [$this->parcels[2]->public_id, 50000]]);   // the courier under-reports both parcels and pays what it reports

        expect($r->expected_minor)->toBe(200000)->and($r->status)->toBe('short');
        $e = ($this->exceptions)('cod_short');
        expect($e)->toHaveCount(1)->and(json_decode($e[0]->details, true)['difference_minor'])->toBe(100000)->and(($this->exceptions)('amount_mismatch'))->toHaveCount(1);
    });

    it('flags a short payment for finance, once', function () {
        $r = ($this->remit)('Pathao', 'ST-1003', 199000, [[$this->parcels[1]->public_id, 120000], [$this->parcels[2]->public_id, 80000]]);

        $e = ($this->exceptions)('cod_short');
        expect($r->status)->toBe('short')->and($e)->toHaveCount(1)->and($e[0]->reference)->toBe('cod-remittance:'.$r->public_id)->and(json_decode($e[0]->details, true)['difference_minor'])->toBe(1000)->and($e[0]->status)->toBe('open');
    });

    it('flags a surplus', function () {
        $r = ($this->remit)('Pathao', 'ST-1004', 201000, [[$this->parcels[1]->public_id, 120000], [$this->parcels[2]->public_id, 80000]]);

        expect($r->status)->toBe('over')->and(($this->exceptions)('cod_over'))->toHaveCount(1);
    });

    it('leaves a statement whose total agrees but whose parcels do not for a person to look at', function () {
        $r = ($this->remit)('Pathao', 'ST-1005', 200000, [[$this->parcels[1]->public_id, 130000], [$this->parcels[2]->public_id, 70000]]);

        $m = ($this->exceptions)('amount_mismatch');
        expect($r->status)->toBe('pending')->and($m)->toHaveCount(1)->and(json_decode($m[0]->details, true)['parcels'])->toHaveKey($this->parcels[1]->public_id);
    });

    it('refuses the same statement twice for one courier, but accepts the same number from another courier', function () {
        ($this->remit)('Pathao', 'ST-2000', 120000, [[$this->parcels[1]->public_id, 120000]]);

        ($this->refuses)(fn () => ($this->remit)('Pathao', 'ST-2000', 80000, [[$this->parcels[2]->public_id, 80000]]), 'cod_statement_duplicate');
        $other = ($this->remit)('Steadfast', 'ST-2000', 80000, [[$this->parcels[2]->public_id, 80000]]);

        expect($other->status)->toBe('reconciled')->and(DB::table('cod_remittances')->count())->toBe(2);
    });

    it('refuses a parcel that is already on another statement, and keeps nothing of the refused statement', function () {
        ($this->remit)('Pathao', 'ST-3000', 120000, [[$this->parcels[1]->public_id, 120000]]);

        ($this->refuses)(fn () => ($this->remit)('Steadfast', 'ST-3001', 200000, [[$this->parcels[1]->public_id, 120000], [$this->parcels[2]->public_id, 80000]]), 'cod_already_remitted');

        expect(DB::table('cod_remittances')->count())->toBe(1)->and(DB::table('cod_remittance_items')->count())->toBe(1)->and(DB::table('reconciliation_exceptions')->count())->toBe(0);
    });

    it('refuses an unknown parcel, a parcel listed twice, an empty list, a blank statement and a negative amount', function () {
        ($this->refuses)(fn () => ($this->remit)('Pathao', 'ST-4000', 1, [['01HZZZZZZZZZZZZZZZZZZZZZZZ', 1]]), 'cod_collection_not_found');
        ($this->refuses)(fn () => ($this->remit)('Pathao', 'ST-4001', 1, [[$this->parcels[1]->public_id, 1], [$this->parcels[1]->public_id, 1]]), 'cod_items_duplicated');
        ($this->refuses)(fn () => ($this->remit)('Pathao', 'ST-4002', 1, []), 'cod_items_required');
        ($this->refuses)(fn () => ($this->remit)('Pathao', '  ', 1, [[$this->parcels[1]->public_id, 1]]), 'cod_statement_invalid');
        ($this->refuses)(fn () => ($this->remit)('', 'ST-4003', 1, [[$this->parcels[1]->public_id, 1]]), 'cod_statement_invalid');
        ($this->refuses)(fn () => ($this->remit)('Pathao', 'ST-4004', -1, [[$this->parcels[1]->public_id, 1]]), 'cod_amount_invalid');
        ($this->refuses)(fn () => ($this->remit)('Pathao', 'ST-4005', 1, [[$this->parcels[1]->public_id, -1]]), 'cod_amount_invalid');

        expect(DB::table('cod_remittances')->count())->toBe(0);
    });

    it('reads Bangla digits in the statement number the same as English ones', function () {
        ($this->remit)('Pathao', 'ST-১০০১', 120000, [[$this->parcels[1]->public_id, 120000]]);

        ($this->refuses)(fn () => ($this->remit)('Pathao', 'ST-1001', 80000, [[$this->parcels[2]->public_id, 80000]]), 'cod_statement_duplicate');
    });
});

describe('where the cash is stuck (aging)', function () {
    it('counts cash we have not collected and cash collected but not yet remitted, by age', function () {
        // not collected: confirmed, processing and completed COD orders; not pending, not cancelled, not online, not already collected
        ($this->cod)(1, ['created_at' => now()->subDays(1)]);
        ($this->cod)(2, ['status' => 'completed', 'created_at' => now()->subDays(6), 'grand_total_minor' => 50000, 'subtotal_minor' => 50000]);
        ($this->cod)(3, ['status' => 'processing', 'created_at' => now()->subDays(20)]);
        ($this->cod)(4, ['status' => 'pending']);
        ($this->cod)(5, ['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);
        ($this->cod)(6, ['payment_method_code' => 'online']);
        // collected: one remitted, one old and not remitted, one recent and not remitted
        $remitted = ($this->collect)(($this->cod)(7, ['grand_total_minor' => 10000, 'subtotal_minor' => 10000]));
        $old = ($this->collect)(($this->cod)(8, ['grand_total_minor' => 70000, 'subtotal_minor' => 70000, 'created_at' => now()->subDays(30)]), [now()->subDays(10)->toImmutable()]);
        ($this->collect)(($this->cod)(9, ['grand_total_minor' => 30000, 'subtotal_minor' => 30000]));
        ($this->remit)('Pathao', 'ST-5000', 10000, [[$remitted->public_id, 10000]]);

        $report = app(CodAging::class)->report();

        expect($report['uncollected'])->toBe(['0-3' => ['count' => 1, 'minor' => 120000], '4-7' => ['count' => 1, 'minor' => 50000], '8-14' => ['count' => 0, 'minor' => 0], '15+' => ['count' => 1, 'minor' => 120000]])
            ->and($report['unremitted'])->toBe(['0-3' => ['count' => 1, 'minor' => 30000], '4-7' => ['count' => 0, 'minor' => 0], '8-14' => ['count' => 1, 'minor' => 70000], '15+' => ['count' => 0, 'minor' => 0]])
            ->and($old->amount_minor)->toBe(70000);
    });

    it('is all zeros when there is nothing outstanding', function () {
        $report = app(CodAging::class)->report();

        expect(array_sum(array_column($report['uncollected'], 'count')))->toBe(0)->and(array_sum(array_column($report['unremitted'], 'minor')))->toBe(0);
    });
});
