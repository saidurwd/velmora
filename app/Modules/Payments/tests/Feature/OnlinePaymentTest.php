<?php

declare(strict_types=1);

use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\ExpireUnpaidPayments;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Enums\VerifyOutcome;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Models\Payment;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->gateway = app(FakeGateway::class);

    // an online order of ৳1,500.00 (the order number is VM-TEST-00000N)
    $this->order = function (int $id = 1, array $over = []): OrderPaymentRequest {
        fakeOrders([$id], array_replace(['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000], $over));

        return new OrderPaymentRequest($id, (string) DB::table('orders')->where('id', $id)->value('public_id'), sprintf('VM-TEST-%06d', $id), 150000, 'BDT', 'online', 'Rahim Uddin', '+8801712345678', 'rahim@example.com', 'House 5, Road 2', 'Dhaka', '1205', 2, true, 'Tee ×2', 'en');
    };
    $this->start = fn (OrderPaymentRequest $o) => app(InitiatePayment::class)->handle($o);
    $this->ref = fn (int $id = 1, int $attempt = 1) => sprintf('VM-TEST-%06d-%d', $id, $attempt);
    $this->paid = fn (string $ref, int $minor = 150000, string $bankId = 'BANK-1', string $currency = 'BDT') => new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of($minor, $currency), $bankId, 'VAL-'.$bankId);
    $this->verify = fn (string $ref, ?string $hint = null) => app(VerifyPayment::class)->handle($ref, $hint);
    $this->payment = fn (int $id = 1) => Payment::query()->where('order_id', $id)->firstOrFail();
    $this->events = fn (string $name) => DB::table('outbox_messages')->where('event_name', $name)->count();
    $this->exceptions = fn () => DB::table('reconciliation_exceptions')->pluck('type')->all();
});

describe('starting a payment', function () {
    it('opens a payment and a numbered attempt, asks the gateway, and says where to send the customer', function () {
        $url = ($this->start)(($this->order)());

        $p = ($this->payment)();
        $tx = DB::table('payment_transactions')->where('payment_id', $p->id)->first();
        expect($url)->toBe('https://fake-gateway.test/pay/VM-TEST-000001-1')->and($p->status)->toBe(PaymentState::Pending)->and($p->amount_minor)->toBe(150000)->and($p->provider)->toBe('fake')
            ->and($p->expires_at->isFuture())->toBeTrue()->and($p->expires_at->diffInMinutes(now(), true))->toBeLessThanOrEqual(30)
            ->and($tx->merchant_ref)->toBe('VM-TEST-000001-1')->and($tx->attempt)->toBe(1)->and($tx->status)->toBe('pending')->and($tx->provider_session)->toBe('FAKE-VM-TEST-000001-1')
            ->and(($this->events)('payments.initiated'))->toBe(1);

        $sent = $this->gateway->initiated[0];
        expect($sent->amount->amountMinor)->toBe(150000)->and($sent->merchantRef)->toBe('VM-TEST-000001-1')->and($sent->customerName)->toBe('Rahim Uddin')
            ->and($sent->notifyUrl)->toEndWith('/api/v1/webhooks/payments/fake');
        foreach ([$sent->successUrl, $sent->failUrl, $sent->cancelUrl] as $back) {
            expect($back)->toContain('/en/pay/return/'.$p->public_id.'/')->and($back)->toContain('signature=')->and($back)->toContain('expires=');
        }
        expect($sent->successUrl)->toContain('/success')->and($sent->failUrl)->toContain('/fail')->and($sent->cancelUrl)->toContain('/cancel');
    });

    it('is a new attempt on the same payment after a failure, with a fresh time limit', function () {
        $order = ($this->order)();
        ($this->start)($order);
        $this->gateway->answer(($this->ref)(), new VerifiedPayment(VerifiedStatus::Failed, ($this->ref)(), null));
        ($this->verify)(($this->ref)());
        expect(($this->payment)()->status)->toBe(PaymentState::Failed);
        DB::table('payments')->where('order_id', 1)->update(['expires_at' => now()->addMinute()]);

        $url = ($this->start)($order);

        $p = ($this->payment)();
        expect($url)->toBe('https://fake-gateway.test/pay/VM-TEST-000001-2')->and(Payment::count())->toBe(1)->and($p->status)->toBe(PaymentState::Pending)
            ->and(DB::table('payment_transactions')->where('payment_id', $p->id)->orderBy('attempt')->pluck('status', 'attempt')->all())->toBe([1 => 'failed', 2 => 'pending'])
            ->and($p->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(20);
    });

    it('gives the same payment page to a second press within two minutes, not a second gateway session', function () {
        $order = ($this->order)();
        $first = ($this->start)($order);

        $second = ($this->start)($order);   // a double-click, or the same order page in two tabs

        expect($second)->toBe($first)->and(DB::table('payment_transactions')->count())->toBe(1)->and(count($this->gateway->initiated))->toBe(1)->and(($this->events)('payments.initiated'))->toBe(1);

        $this->travel(121)->seconds();
        expect(($this->start)($order))->toContain('VM-TEST-000001-2')->and(DB::table('payment_transactions')->count())->toBe(2);   // after that, trying again is a real retry
    });

    it('refuses what cannot be paid online, and says why', function () {
        $order = ($this->order)();
        $refuse = fn (string $reason, ?callable $arrange = null) => (function () use ($order, $reason, $arrange) {
            $arrange && $arrange();
            expect(fn () => ($this->start)($order))->toThrow(PaymentException::class, $reason);
        })();

        $refuse('payments_frozen', fn () => app(Settings::class)->set('payments.online_frozen', true));
        app(Settings::class)->set('payments.online_frozen', false);
        $refuse('method_unavailable', fn () => DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => false]));
        DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => true]);
        $refuse('amount_out_of_range', fn () => DB::table('payment_methods')->where('code', 'online')->update(['min_minor' => 200000]));
        DB::table('payment_methods')->where('code', 'online')->update(['min_minor' => null, 'max_minor' => 100000]);
        expect(fn () => ($this->start)($order))->toThrow(PaymentException::class, 'amount_out_of_range');
        DB::table('payment_methods')->where('code', 'online')->update(['max_minor' => null]);
        $refuse('unknown_provider', fn () => DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'nowhere']));

        expect(Payment::count())->toBe(0);
        $cash = new OrderPaymentRequest(1, 'x', 'VM-TEST-000001', 150000, 'BDT', 'cod', 'R', '+8801712345678', null, null, null, null, 1, true, 'x', 'en');
        expect(fn () => ($this->start)($cash))->toThrow(PaymentException::class, 'method_unavailable');
    });

    it('respects the gateway\'s own amount limits (a gateway that cannot take ৳5)', function () {
        $this->gateway->withCapabilities(new Capabilities(minAmountMinor: 1000, maxAmountMinor: 50_000_000));
        DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake']);
        fakeOrders([1], ['payment_method_code' => 'online']);
        $tiny = new OrderPaymentRequest(1, 'x', 'VM-TEST-000001', 500, 'BDT', 'online', 'R', '+8801712345678', null, null, null, null, 1, true, 'x', 'en');

        expect(fn () => ($this->start)($tiny))->toThrow(PaymentException::class, 'amount_out_of_range');
    });

    it('will not start a second payment for an order that is paid, expired, or being looked at', function () {
        $id = 10;
        foreach (['paid' => 'already_paid', 'needs_review' => 'under_review', 'expired' => 'payment_expired'] as $state => $reason) {
            $order = ($this->order)(++$id);
            DB::table('payments')->insert([
                'public_id' => strtolower((string) Str::ulid()), 'order_id' => $id, 'method_code' => 'online', 'provider' => 'fake', 'amount_minor' => 150000, 'currency' => 'BDT', 'status' => $state,
                'captured_minor' => $state === 'paid' ? 150000 : 0, 'paid_at' => $state === 'paid' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);

            expect(fn () => ($this->start)($order))->toThrow(PaymentException::class, $reason);
            expect(DB::table('payment_transactions')->count())->toBe(0);   // no attempt was opened
        }
    });

    it('records a failed start honestly and lets the customer try again', function () {
        $order = ($this->order)();
        $this->gateway->failNext('initiate');

        expect(fn () => ($this->start)($order))->toThrow(PaymentException::class, 'initiation_failed');

        $p = ($this->payment)();
        expect($p->status)->toBe(PaymentState::Failed)->and(DB::table('payment_transactions')->where('payment_id', $p->id)->value('status'))->toBe('failed')
            ->and(DB::table('payment_transactions')->where('payment_id', $p->id)->value('failure_code'))->toBe('provider_unavailable')->and(($this->events)('payments.initiated'))->toBe(0);
        expect(($this->start)($order))->toContain('VM-TEST-000001-2');
    });

    it('cannot have two open payments for one order, whatever happens at the same instant', function () {
        ($this->order)();
        $insert = fn () => DB::table('payments')->insert(['public_id' => strtolower((string) Str::ulid()), 'order_id' => 1, 'method_code' => 'online', 'provider' => 'fake', 'amount_minor' => 150000, 'currency' => 'BDT', 'status' => 'initiated', 'created_at' => now(), 'updated_at' => now()]);
        $insert();

        expect($insert)->toThrow(QueryException::class);
    });
});

describe('verifying a payment (server to server)', function () {
    beforeEach(function () {
        ($this->start)(($this->order)());
        $this->ref1 = ($this->ref)();
    });

    it('marks it paid only when the gateway confirms this reference, this amount, this currency', function () {
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1));

        expect(($this->verify)($this->ref1, 'VAL-HINT'))->toBe(VerifyOutcome::Paid);

        $p = ($this->payment)();
        $tx = DB::table('payment_transactions')->where('merchant_ref', $this->ref1)->first();
        expect($p->status)->toBe(PaymentState::Paid)->and($p->captured_minor)->toBe(150000)->and($p->paid_at)->not->toBeNull()
            ->and($tx->status)->toBe('succeeded')->and($tx->provider_txn_id)->toBe('BANK-1')->and($tx->provider_validation_id)->toBe('VAL-BANK-1')->and($tx->verified_at)->not->toBeNull()
            ->and(($this->events)('payments.succeeded'))->toBe(1)->and($this->gateway->verifications[0]->providerValidationId)->toBe('VAL-HINT');
    });

    it('does nothing the second time: no second event, and the gateway is not asked again', function () {
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1));
        ($this->verify)($this->ref1);
        $asked = count($this->gateway->verifications);

        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::Paid)->and(($this->verify)($this->ref1))->toBe(VerifyOutcome::Paid)
            ->and(($this->events)('payments.succeeded'))->toBe(1)->and(count($this->gateway->verifications))->toBe($asked)->and(($this->payment)()->captured_minor)->toBe(150000);
    });

    it('holds a payment whose amount is not what we asked for, and pays nothing', function () {
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1, 100000));

        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::NeedsReview);

        $p = ($this->payment)();
        expect($p->status)->toBe(PaymentState::NeedsReview)->and($p->captured_minor)->toBe(0)->and(DB::table('payment_transactions')->where('merchant_ref', $this->ref1)->value('status'))->toBe('pending')
            ->and(($this->exceptions)())->toBe(['amount_mismatch'])->and(($this->events)('payments.succeeded'))->toBe(0)->and(($this->events)('payments.needs_review'))->toBe(1)
            ->and(json_decode((string) DB::table('reconciliation_exceptions')->value('details'), true))->toMatchArray(['expected_minor' => 150000, 'got_minor' => 100000, 'reason' => 'amount_mismatch']);
    });

    it('holds a payment in the wrong currency', function () {
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1, 150000, 'BANK-1', 'USD'));

        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::NeedsReview)->and(($this->exceptions)())->toBe(['currency_mismatch'])->and(($this->payment)()->captured_minor)->toBe(0);
    });

    it('holds an answer that is about some other transaction', function () {
        $this->gateway->answer($this->ref1, ($this->paid)('VM-OTHER-000009-1'));

        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::NeedsReview)->and(($this->payment)()->status)->toBe(PaymentState::NeedsReview)->and(($this->exceptions)())->toBe(['unmatched_provider_txn']);
    });

    it('holds a success that comes without the bank transaction id (it could never be refunded)', function () {
        $this->gateway->answer($this->ref1, new VerifiedPayment(VerifiedStatus::Valid, $this->ref1, Money::of(150000), null, 'VAL-1'));

        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::NeedsReview)->and(($this->payment)()->captured_minor)->toBe(0);
    });

    it('does not believe anything until the gateway has an answer: unknown, still pending, or unreachable change nothing', function () {
        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::NoChange);   // the gateway knows nothing about it

        $this->gateway->answer($this->ref1, new VerifiedPayment(VerifiedStatus::Pending, $this->ref1, null));
        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::Pending);

        $this->gateway->failNext('verify');
        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::ProviderError);

        expect(($this->payment)()->status)->toBe(PaymentState::Pending)->and(($this->payment)()->captured_minor)->toBe(0)->and(($this->events)('payments.succeeded'))->toBe(0)->and(($this->events)('payments.failed'))->toBe(0);
    });

    it('does not even ask the gateway about a reference that is not ours', function () {
        $before = count($this->gateway->verifications);

        expect(($this->verify)('NOT-OURS-1'))->toBe(VerifyOutcome::UnknownReference)->and(count($this->gateway->verifications))->toBe($before);
    });

    it('fails the payment when the gateway says it failed or was cancelled, and the customer may try again', function () {
        $this->gateway->answer($this->ref1, new VerifiedPayment(VerifiedStatus::Cancelled, $this->ref1, null));
        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::Cancelled);
        expect(($this->payment)()->status)->toBe(PaymentState::Cancelled)->and(DB::table('payment_transactions')->where('merchant_ref', $this->ref1)->value('status'))->toBe('cancelled')
            ->and(($this->events)('payments.failed'))->toBe(1);

        $this->travel(121)->seconds();
        ($this->start)(($this->order)(1));   // the same order again
        $ref2 = ($this->ref)(1, 2);
        $this->gateway->answer($ref2, new VerifiedPayment(VerifiedStatus::Failed, $ref2, null));
        expect(($this->verify)($ref2))->toBe(VerifyOutcome::Failed)->and(($this->payment)()->status)->toBe(PaymentState::Failed);
    });

    it('does not fail the payment while another attempt is still in flight', function () {
        $this->travel(121)->seconds();   // a press within two minutes would reuse attempt 1's page
        ($this->start)(($this->order)(1));   // attempt 2, while attempt 1 is still open
        $this->gateway->answer($this->ref1, new VerifiedPayment(VerifiedStatus::Failed, $this->ref1, null));

        ($this->verify)($this->ref1);

        expect(($this->payment)()->status)->toBe(PaymentState::Pending)->and(($this->events)('payments.failed'))->toBe(0);
    });

    it('records money that arrives after the time ran out as a late payment for a person, never as paid and never lost', function () {
        DB::table('payments')->where('order_id', 1)->update(['status' => 'expired']);
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1));

        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::NeedsReview);

        $p = ($this->payment)();
        expect($p->status)->toBe(PaymentState::NeedsReview)->and($p->captured_minor)->toBe(150000)->and(DB::table('payment_transactions')->where('merchant_ref', $this->ref1)->value('status'))->toBe('succeeded')
            ->and(($this->exceptions)())->toBe(['late_success'])->and(($this->events)('payments.succeeded'))->toBe(0)->and(($this->events)('payments.needs_review'))->toBe(1)
            ->and(json_decode((string) DB::table('reconciliation_exceptions')->value('details'), true)['payment_was'])->toBe('expired');
    });

    it('treats money for an order that was cancelled meanwhile the same way', function () {
        DB::table('orders')->where('id', 1)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1));

        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::NeedsReview)->and(($this->payment)()->captured_minor)->toBe(150000)->and(($this->exceptions)())->toBe(['late_success']);
    });

    it('accepts a late success on a payment that had failed, when the order is still open (the money is simply late)', function () {
        $this->gateway->answer($this->ref1, new VerifiedPayment(VerifiedStatus::Pending, $this->ref1, null));
        ($this->verify)($this->ref1);
        DB::table('payments')->where('order_id', 1)->update(['status' => 'failed']);   // e.g. the customer pressed "cancel" then paid anyway in another tab
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1));

        expect(($this->verify)($this->ref1))->toBe(VerifyOutcome::Paid)->and(($this->payment)()->status)->toBe(PaymentState::Paid);
    });

    it('refuses a bank transaction id that already belongs to another attempt', function () {
        $this->travel(121)->seconds();   // a press within two minutes would reuse attempt 1's page
        ($this->start)(($this->order)(1));   // attempt 2
        $ref2 = ($this->ref)(1, 2);
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1, 150000, 'BANK-SAME'));
        ($this->verify)($this->ref1);
        $this->gateway->answer($ref2, ($this->paid)($ref2, 150000, 'BANK-SAME'));

        $outcome = ($this->verify)($ref2);

        expect($outcome)->toBe(VerifyOutcome::NeedsReview)->and(($this->payment)()->captured_minor)->toBe(150000)->and(($this->events)('payments.succeeded'))->toBe(1)
            ->and(DB::table('payment_transactions')->where('merchant_ref', $ref2)->value('status'))->toBe('pending');
    });

    it('records a second paid attempt as a duplicate to give back, and does not capture twice', function () {
        $this->travel(121)->seconds();   // a press within two minutes would reuse attempt 1's page
        ($this->start)(($this->order)(1));   // attempt 2
        $ref2 = ($this->ref)(1, 2);
        $this->gateway->answer($this->ref1, ($this->paid)($this->ref1, 150000, 'BANK-A'));
        $this->gateway->answer($ref2, ($this->paid)($ref2, 150000, 'BANK-B'));

        ($this->verify)($this->ref1);
        ($this->verify)($ref2);

        expect(($this->payment)()->captured_minor)->toBe(150000)->and(($this->events)('payments.succeeded'))->toBe(1)->and(($this->exceptions)())->toBe(['duplicate_payment'])
            ->and(DB::table('payment_transactions')->where('merchant_ref', $ref2)->value('status'))->toBe('succeeded');
    });
});

describe('payments that ran out of time', function () {
    beforeEach(function () {
        $this->due = function (int $id = 1) {
            ($this->start)(($this->order)($id));
            DB::table('payments')->where('order_id', $id)->update(['expires_at' => now()->subMinute()]);
        };
    });

    it('expires an unpaid payment and tells Orders', function () {
        ($this->due)();

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(1);

        expect(($this->payment)()->status)->toBe(PaymentState::Expired)->and(($this->events)('payments.expired'))->toBe(1)
            ->and(DB::table('payment_transactions')->value('status'))->toBe('pending');   // left open on purpose: a late success can still be recorded
    });

    it('asks the gateway first, so a customer who did pay is never expired', function () {
        ($this->due)();
        $this->gateway->answer(($this->ref)(), ($this->paid)(($this->ref)()));

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(0);

        expect(($this->payment)()->status)->toBe(PaymentState::Paid)->and(($this->events)('payments.expired'))->toBe(0)->and(($this->events)('payments.succeeded'))->toBe(1);
    });

    it('leaves the payment alone when the gateway cannot be asked, and tries again next time', function () {
        ($this->due)();
        $this->gateway->failNext('verify');

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(0);
        expect(($this->payment)()->status)->toBe(PaymentState::Pending);

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(1);
    });

    it('does not touch payments that still have time, or ones already settled', function () {
        ($this->start)(($this->order)(1));
        ($this->start)(($this->order)(2));
        DB::table('payments')->where('order_id', 2)->update(['expires_at' => now()->subMinute(), 'status' => 'failed']);

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(0)->and(Payment::query()->where('status', 'expired')->count())->toBe(0);
    });

    it('expires a payment that was opened but never reached the gateway', function () {
        fakeOrders([1], ['payment_method_code' => 'online']);
        DB::table('payments')->insert(['public_id' => strtolower((string) Str::ulid()), 'order_id' => 1, 'method_code' => 'online', 'provider' => 'fake', 'amount_minor' => 150000, 'currency' => 'BDT', 'status' => 'initiated', 'expires_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(1);
    });
});
