<?php

declare(strict_types=1);

use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Actions\ExpireUnpaidPayments;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use Illuminate\Support\Facades\DB;

/*
 * The replay suite (Phase 6 gate). Each scenario is a story of hostile or awkward things the outside world does: duplicates, reordering,
 * delays, forgeries, wrong amounts and currencies, unknown references, a gateway that is down. After EVERY scenario the same money
 * invariants are checked, so "double-paid, wrongly paid, or lost" cannot hide behind a scenario's own expectations.
 *
 * Steps:
 *   gateway : what the gateway will say about an attempt from now on (status valid|failed|cancelled|pending|none, amount, currency, bank id, which attempt, optional wrong reference)
 *   ipn     : a notification arrives (claimed status, validation id, event id)
 *   return  : the customer's browser comes back (success|fail|cancel)
 *   expire  : the time limit passes and the expiry job runs
 *   cancel_order : staff cancel the order
 *   days    : time moves on
 *   down    : the gateway cannot be reached for the next question
 *   retry   : the customer starts another attempt
 */

const REPLAY_REF = 'VM-TEST-000001-%d';

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->gateway = app(FakeGateway::class);

    fakeOrders([1], ['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]);
    $this->orderRequest = new OrderPaymentRequest(1, (string) DB::table('orders')->where('id', 1)->value('public_id'), 'VM-TEST-000001', 150000, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en');
    app(InitiatePayment::class)->handle($this->orderRequest);

    $this->run = function (array $step): void {
        $attempt = $step['attempt'] ?? 1;
        $ref = sprintf(REPLAY_REF, $attempt);
        match ($step['do']) {
            'gateway' => $this->gateway->answer($ref, new VerifiedPayment(
                match ($step['status']) {
                    'valid' => VerifiedStatus::Valid, 'failed' => VerifiedStatus::Failed, 'cancelled' => VerifiedStatus::Cancelled, 'pending' => VerifiedStatus::Pending, default => VerifiedStatus::Unknown,
                },
                $step['answer_ref'] ?? $ref,
                $step['status'] === 'valid' ? Money::of($step['amount'] ?? 150000, $step['currency'] ?? 'BDT') : null,
                array_key_exists('bank', $step) ? $step['bank'] : 'BANK-'.$attempt, 'VAL-'.$attempt,
            )),
            'ipn' => $this->postJson('/api/v1/webhooks/payments/fake', ['tran_id' => $step['ref'] ?? $ref, 'status' => $step['claim'] ?? 'VALID', 'val_id' => $step['val'] ?? 'VAL-'.$attempt, 'amount' => '1500.00', 'event_id' => $step['event'] ?? uniqid('evt-', true)])->assertOk(),
            'return' => $this->get(collect($this->gateway->initiated)->last()->{match ($step['kind']) {
                'success' => 'successUrl', 'fail' => 'failUrl', default => 'cancelUrl'
            }})->assertRedirect(),
            'expire' => (function () {
                DB::table('payments')->where('order_id', 1)->whereIn('status', ['initiated', 'pending'])->update(['expires_at' => now()->subMinute()]);
                app(ExpireUnpaidPayments::class)->handle();
            })(),
            'cancel_order' => app(TransitionOrder::class)->status(Actor::system('staff'), 1, OrderStatus::Cancelled, 'Customer asked'),
            'days' => $this->travel($step['n'])->days(),
            'down' => $this->gateway->failNext('verify'),
            'retry' => (function () {
                $this->travel(121)->seconds();
                app(InitiatePayment::class)->handle($this->orderRequest);
            })(),
        };
    };

    /** The money rules that must hold after ANY story. */
    $this->invariants = function (): void {
        $p = DB::table('payments')->where('order_id', 1)->first();
        $txs = DB::table('payment_transactions')->where('payment_id', $p->id)->get();
        $succeededTx = $txs->where('status', 'succeeded');
        $order = DB::table('orders')->where('id', 1)->first();
        $announced = DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->count();

        expect($p->captured_minor)->toBeLessThanOrEqual($p->amount_minor, 'never more captured than asked for')
            ->and($p->refunded_minor)->toBeLessThanOrEqual($p->captured_minor);
        if ($p->captured_minor > 0) {
            expect($succeededTx->count())->toBeGreaterThanOrEqual(1, 'money recorded without a verified attempt');
            expect($p->paid_at)->not->toBeNull();
        }
        foreach ($succeededTx as $tx) {
            expect($tx->provider_txn_id)->not->toBeNull('a paid attempt has its bank transaction id')->and($tx->verified_at)->not->toBeNull();
        }
        if ($p->status === 'paid') {
            expect($p->captured_minor)->toBe($p->amount_minor)->and($succeededTx->count())->toBeGreaterThanOrEqual(1)
                ->and(DB::table('outbox_messages')->where('event_name', 'payments.expired')->count())->toBe(0, 'an order that is paid must not also be told it expired');
        }
        expect($announced)->toBeLessThanOrEqual(1, 'one payment, one "paid" announcement');
        expect($order->payment_status === 'paid')->toBe($p->status === 'paid', 'the order is paid exactly when the payment is');
        expect(DB::table('payments')->where('order_id', 1)->count())->toBe(1);
    };
});

dataset('stories', [
    'a duplicate notification, three times' => [[
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn', 'event' => 'same'], ['do' => 'ipn', 'event' => 'same'], ['do' => 'ipn', 'event' => 'same'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'three different notifications for the same payment' => [[
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn', 'event' => 'a'], ['do' => 'ipn', 'event' => 'b', 'val' => 'VAL-OTHER'], ['do' => 'ipn', 'event' => 'c', 'claim' => 'VALIDATED'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'the browser returns before the notification' => [[
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'return', 'kind' => 'success'], ['do' => 'ipn'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'the notification arrives before the browser' => [[
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn'], ['do' => 'return', 'kind' => 'success'], ['do' => 'return', 'kind' => 'success'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'a failure is reported and then a success in the same session' => [[
        ['do' => 'gateway', 'status' => 'failed'], ['do' => 'ipn', 'claim' => 'FAILED'], ['do' => 'return', 'kind' => 'fail'],
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn', 'claim' => 'VALID'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'a cancellation and then a success in the same session' => [[
        ['do' => 'gateway', 'status' => 'cancelled'], ['do' => 'return', 'kind' => 'cancel'], ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'a late "failed" notice after the payment was confirmed' => [[
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn'], ['do' => 'gateway', 'status' => 'failed'], ['do' => 'ipn', 'claim' => 'FAILED', 'event' => 'late-fail'], ['do' => 'return', 'kind' => 'fail'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'a success that arrives after the time limit ran out' => [[
        ['do' => 'gateway', 'status' => 'none'], ['do' => 'expire'], ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn'],
    ], ['payment' => 'needs_review', 'captured' => 150000, 'order_pay' => 'pending', 'order' => 'cancelled', 'exceptions' => ['late_success']]],

    'a success that arrives after staff cancelled the order' => [[
        ['do' => 'cancel_order'], ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn'],
    ], ['payment' => 'needs_review', 'captured' => 150000, 'order_pay' => 'pending', 'order' => 'cancelled', 'exceptions' => ['late_success']]],

    'a success four days after a failure' => [[
        ['do' => 'gateway', 'status' => 'failed'], ['do' => 'ipn', 'claim' => 'FAILED'], ['do' => 'days', 'n' => 4], ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn', 'claim' => 'VALID', 'event' => 'four-days'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'a notification the gateway cannot back up (forged "VALID")' => [[
        ['do' => 'gateway', 'status' => 'none'], ['do' => 'ipn', 'claim' => 'VALID', 'val' => 'FORGED'], ['do' => 'return', 'kind' => 'success'],
    ], ['payment' => 'pending', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => []]],

    'a forged notification, then the real one' => [[
        ['do' => 'gateway', 'status' => 'none'], ['do' => 'ipn', 'claim' => 'VALID', 'val' => 'FORGED', 'event' => 'forged'],
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn', 'claim' => 'VALID', 'event' => 'real'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'the gateway says the amount is smaller' => [[
        ['do' => 'gateway', 'status' => 'valid', 'amount' => 100000], ['do' => 'ipn'], ['do' => 'return', 'kind' => 'success'],
    ], ['payment' => 'needs_review', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => ['amount_mismatch']]],

    'the gateway says the amount is larger' => [[
        ['do' => 'gateway', 'status' => 'valid', 'amount' => 160000], ['do' => 'ipn'],
    ], ['payment' => 'needs_review', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => ['amount_mismatch']]],

    'the gateway says one paisa less' => [[
        ['do' => 'gateway', 'status' => 'valid', 'amount' => 149999], ['do' => 'ipn'],
    ], ['payment' => 'needs_review', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => ['amount_mismatch']]],

    'the gateway says another currency' => [[
        ['do' => 'gateway', 'status' => 'valid', 'currency' => 'USD'], ['do' => 'ipn'],
    ], ['payment' => 'needs_review', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => ['currency_mismatch']]],

    'the gateway answers about a different transaction' => [[
        ['do' => 'gateway', 'status' => 'valid', 'answer_ref' => 'VM-TEST-000099-1'], ['do' => 'ipn'],
    ], ['payment' => 'needs_review', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => ['unmatched_provider_txn']]],

    'the gateway confirms but gives no bank transaction id' => [[
        ['do' => 'gateway', 'status' => 'valid', 'bank' => null], ['do' => 'ipn'],
    ], ['payment' => 'needs_review', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => ['unmatched_provider_txn']]],

    'a notification for a transaction that is not ours' => [[
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'ipn', 'ref' => 'SOMEONE-ELSES-1', 'event' => 'foreign'],
    ], ['payment' => 'pending', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => []]],

    'the gateway is down, then back' => [[
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'down'], ['do' => 'return', 'kind' => 'success'], ['do' => 'return', 'kind' => 'success'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'the gateway only ever says "pending"' => [[
        ['do' => 'gateway', 'status' => 'pending'], ['do' => 'ipn'], ['do' => 'return', 'kind' => 'success'],
    ], ['payment' => 'pending', 'captured' => 0, 'order_pay' => 'pending', 'order' => 'pending', 'exceptions' => []]],

    'the customer tries again and pays the second attempt' => [[
        ['do' => 'retry'], ['do' => 'gateway', 'status' => 'valid', 'attempt' => 2], ['do' => 'ipn', 'ref' => 'VM-TEST-000001-2', 'val' => 'VAL-2'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],

    'both attempts turn out to be paid' => [[
        ['do' => 'retry'], ['do' => 'gateway', 'status' => 'valid', 'attempt' => 2], ['do' => 'ipn', 'ref' => 'VM-TEST-000001-2', 'val' => 'VAL-2'],
        ['do' => 'gateway', 'status' => 'valid', 'attempt' => 1], ['do' => 'ipn', 'ref' => 'VM-TEST-000001-1', 'val' => 'VAL-1', 'event' => 'first-attempt'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => ['duplicate_payment']]],

    'the same success reported through every channel at once' => [[
        ['do' => 'gateway', 'status' => 'valid'], ['do' => 'return', 'kind' => 'success'], ['do' => 'ipn', 'event' => 'x1'], ['do' => 'expire'], ['do' => 'ipn', 'event' => 'x2'], ['do' => 'return', 'kind' => 'success'],
    ], ['payment' => 'paid', 'captured' => 150000, 'order_pay' => 'paid', 'order' => 'confirmed', 'exceptions' => []]],
]);

it('keeps the money right in this story', function (array $steps, array $expected) {
    foreach ($steps as $i => $step) {
        ($this->run)($step);
        ($this->invariants)();   // after EVERY step, not just the last
    }
    $this->travelBack();

    $p = DB::table('payments')->where('order_id', 1)->first();
    $o = DB::table('orders')->where('id', 1)->first();
    expect($p->status)->toBe($expected['payment'])->and($p->captured_minor)->toBe($expected['captured'])->and($o->payment_status)->toBe($expected['order_pay'])->and($o->status)->toBe($expected['order'])
        ->and(DB::table('reconciliation_exceptions')->pluck('type')->sort()->values()->all())->toBe($expected['exceptions']);
})->with('stories');
