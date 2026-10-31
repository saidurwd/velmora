<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Listeners\ApplyPaymentEvent;
use App\Modules\Payments\Actions\ApproveRefund;
use App\Modules\Payments\Actions\CompleteManualRefund;
use App\Modules\Payments\Actions\ExecuteRefund;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\PollRefunds;
use App\Modules\Payments\Actions\RejectRefund;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Events\RefundSucceeded;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Payments\Services\RefundLedger;
use App\Modules\Payments\Services\RefundRouting;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->gateway = app(FakeGateway::class)->withCapabilities(new Capabilities(refund: true, partialRefund: true, refundStatus: true, statusQuery: true, sandbox: true, minAmountMinor: 1000, maxAmountMinor: 50_000_000));

    $this->staffA = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
    $this->staffB = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Babul');

    /** A paid online order of ৳1,500.00 (order id $id, attempt 1 paid with bank id BANK-$id). Returns the payment row. */
    $this->paid = function (int $id = 1, array $orderOver = []) {
        fakeOrders([$id], array_replace(['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000], $orderOver));
        app(InitiatePayment::class)->handle(new OrderPaymentRequest($id, (string) DB::table('orders')->where('id', $id)->value('public_id'), sprintf('VM-TEST-%06d', $id), 150000, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', $orderOver['locale'] ?? 'en'));
        $ref = sprintf('VM-TEST-%06d-1', $id);
        $this->gateway->answer($ref, new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of(150000), 'BANK-'.$id, 'VAL-'.$id));
        app(VerifyPayment::class)->handle($ref);

        return DB::table('payments')->where('order_id', $id)->first();
    };
    $this->request = fn (string $paymentId, int $minor, ?Actor $by = null, array $more = []) => app(RequestRefund::class)->handle($by ?? $this->staffA, $paymentId, $minor, 'Customer returned the item', ...$more);
    $this->approve = fn (Refund $r, ?Actor $by = null) => app(ApproveRefund::class)->handle($by ?? $this->staffB, $r->public_id);
    $this->send = fn (Refund $r) => app(ExecuteRefund::class)->handle($this->staffB, $r->public_id);
    $this->poll = fn () => app(PollRefunds::class)->handle();
    $this->fresh = fn (Refund $r) => Refund::query()->findOrFail($r->id);
    $this->pay = fn (int $id = 1) => DB::table('payments')->where('order_id', $id)->first();
    $this->order = fn (int $id = 1) => DB::table('orders')->where('id', $id)->first(['status', 'payment_status']);
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(PaymentException::class, $reason);

    /** The books must always balance: refunded_minor is exactly what is reserved on the main attempt, and never more than was received. */
    $this->books = function (int $id = 1): void {
        $p = DB::table('payments')->where('order_id', $id)->first();
        $primary = DB::table('payment_transactions')->where('payment_id', $p->id)->where('status', 'succeeded')->orderBy('id')->value('id');
        $reserved = (int) DB::table('refunds')->where('payment_id', $p->id)->where('payment_transaction_id', $primary)->whereIn('status', ['requested', 'approved', 'processing', 'succeeded'])->sum('amount_minor');
        expect((int) $p->refunded_minor)->toBe($reserved, 'refunded_minor must equal the reserved refunds')->and((int) $p->refunded_minor)->toBeLessThanOrEqual((int) $p->captured_minor);
    };
});

describe('asking for a refund', function () {
    it('reserves the amount at once, remembers who asked, and picks the gateway for a whole refund', function () {
        $p = ($this->paid)();

        $r = ($this->request)($p->public_id, 150000);

        expect($r->status)->toBe(RefundStatus::Requested)->and($r->method)->toBe(RefundMethod::Gateway)->and($r->amount_minor)->toBe(150000)->and($r->requested_by)->toBe($this->staffA->id)
            ->and($r->refund_ref)->toMatch('/^RF-[A-Z0-9]{20}$/')->and(strlen($r->refund_ref))->toBeLessThanOrEqual(30)->and($r->payment_transaction_id)->not->toBeNull()
            ->and(($this->pay)()->refunded_minor)->toBe(150000)->and(DB::table('audit_events')->where('action', 'refund.requested')->count())->toBe(1);
        ($this->books)();
    });

    it('will not let the total of refunds pass what was received, to the paisa', function () {
        $p = ($this->paid)();
        ($this->request)($p->public_id, 100000);

        ($this->refuses)(fn () => ($this->request)($p->public_id, 50001), 'refund_exceeds_captured');
        expect(($this->request)($p->public_id, 50000)->amount_minor)->toBe(50000);   // exactly what is left
        ($this->refuses)(fn () => ($this->request)($p->public_id, 1), 'refund_exceeds_captured');

        expect(($this->pay)()->refunded_minor)->toBe(150000)->and(Refund::count())->toBe(2);
        ($this->books)();
    });

    it('says how much is left when it refuses', function () {
        $p = ($this->paid)();
        ($this->request)($p->public_id, 100000);

        try {
            ($this->request)($p->public_id, 99999);
            $this->fail('should refuse');
        } catch (PaymentException $e) {
            expect($e->params['left'])->toBe(50000);
        }
    });

    it('refuses nonsense: no amount, no reason, nothing received, an unknown payment', function () {
        $p = ($this->paid)();

        ($this->refuses)(fn () => ($this->request)($p->public_id, 0), 'refund_amount_invalid');
        ($this->refuses)(fn () => ($this->request)($p->public_id, -5), 'refund_amount_invalid');
        ($this->refuses)(fn () => app(RequestRefund::class)->handle($this->staffA, $p->public_id, 1000, '   '), 'refund_reason_required');
        ($this->refuses)(fn () => ($this->request)('01nosuchpayment0000000000000', 1000), 'refund_not_found');

        fakeOrders([2], ['payment_method_code' => 'online']);
        DB::table('payments')->insert(['public_id' => 'p2'.str_repeat('0', 24), 'order_id' => 2, 'method_code' => 'online', 'provider' => 'fake', 'amount_minor' => 150000, 'currency' => 'BDT', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        ($this->refuses)(fn () => ($this->request)('p2'.str_repeat('0', 24), 1000), 'refund_nothing_captured');
        expect(Refund::count())->toBe(0);
    });

    it('can give back a late payment that the order never took (held for review)', function () {
        $p = ($this->paid)();
        DB::table('payments')->where('id', $p->id)->update(['status' => 'needs_review']);

        expect(($this->request)($p->public_id, 150000)->status)->toBe(RefundStatus::Requested);
    });

    it('goes through the gateway only for what it is known to accept, and by hand for the rest', function () {
        $this->gateway->withCapabilities(new Capabilities(refund: true, partialRefund: false, refundStatus: true, statusQuery: true));
        $a = ($this->paid)(1);
        $b = ($this->paid)(2);
        $c = ($this->paid)(3);
        $d = ($this->paid)(4);

        expect(($this->request)($a->public_id, 150000)->method)->toBe(RefundMethod::Gateway)                       // the whole attempt
            ->and(($this->request)($b->public_id, 50000)->method)->toBe(RefundMethod::Manual)                      // a part: not proven to work
            ->and(($this->request)($c->public_id, 150000, null, [null, true])->method)->toBe(RefundMethod::Manual); // asked for by hand

        $first = ($this->request)($d->public_id, 50000);                                                           // a part, then the rest: the rest is no longer "the whole"
        expect($first->method)->toBe(RefundMethod::Manual)->and(($this->request)($d->public_id, 100000)->method)->toBe(RefundMethod::Manual);
    });

    it('goes by hand when the gateway says it cannot refund', function () {
        $this->gateway->withCapabilities(new Capabilities(refund: false));
        $p = ($this->paid)();

        expect(($this->request)($p->public_id, 150000)->method)->toBe(RefundMethod::Manual);
    });

    it('goes by hand when the paid attempt has no bank transaction id to refund against', function () {
        fakeOrders([5], ['payment_method_code' => 'online']);
        $pid = DB::table('payments')->insertGetId(['public_id' => 'p5'.str_repeat('0', 24), 'order_id' => 5, 'method_code' => 'online', 'provider' => 'fake', 'amount_minor' => 150000, 'currency' => 'BDT', 'status' => 'initiated', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_transactions')->insert(['public_id' => strtolower((string) Str::ulid()), 'payment_id' => $pid, 'provider' => 'fake', 'merchant_ref' => 'VM-TEST-000005-1', 'attempt' => 1, 'status' => 'pending', 'amount_minor' => 150000, 'currency' => 'BDT', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_transactions')->where('payment_id', $pid)->update(['status' => 'succeeded', 'verified_at' => now()]);   // paid, but no bank transaction id was recorded
        DB::table('payments')->where('id', $pid)->update(['status' => 'paid', 'captured_minor' => 150000, 'paid_at' => now()]);

        expect(($this->request)('p5'.str_repeat('0', 24), 150000)->method)->toBe(RefundMethod::Manual);
    });

    it('goes by hand when the gateway of that payment is not set up any more', function () {
        $p = ($this->paid)();
        $routing = new RefundRouting(new GatewayRegistry, app(RefundLedger::class));
        $tx = PaymentTransaction::query()->where('payment_id', $p->id)->firstOrFail();

        expect($routing->decide($tx, 150000, false))->toBe(RefundMethod::Manual);
    });

    it('gives back a double payment from its own attempt, limited by that attempt\'s amount, without touching the payment\'s total', function () {
        // a double payment: attempt 2 is started while attempt 1 is still open, then BOTH are paid
        fakeOrders([1], ['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]);
        $order = new OrderPaymentRequest(1, (string) DB::table('orders')->where('id', 1)->value('public_id'), 'VM-TEST-000001', 150000, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en');
        app(InitiatePayment::class)->handle($order);
        $this->travel(121)->seconds();
        app(InitiatePayment::class)->handle($order);
        $this->travelBack();
        foreach ([1 => 'BANK-1', 2 => 'BANK-DUP'] as $attempt => $bank) {
            $this->gateway->answer("VM-TEST-000001-{$attempt}", new VerifiedPayment(VerifiedStatus::Valid, "VM-TEST-000001-{$attempt}", Money::of(150000), $bank, 'VAL-'.$attempt));
            app(VerifyPayment::class)->handle("VM-TEST-000001-{$attempt}");
        }
        $p = DB::table('payments')->where('order_id', 1)->first();
        $extra = PaymentTransaction::query()->where('merchant_ref', 'VM-TEST-000001-2')->firstOrFail();

        $r = ($this->request)($p->public_id, 150000, null, [$extra->public_id]);
        ($this->refuses)(fn () => ($this->request)($p->public_id, 1, null, [$extra->public_id]), 'refund_exceeds_captured');

        expect($r->payment_transaction_id)->toBe($extra->id)->and(($this->pay)()->refunded_minor)->toBe(0)->and(($this->request)($p->public_id, 150000)->payment_transaction_id)->not->toBe($extra->id);   // the main attempt is still fully refundable
        ($this->refuses)(fn () => ($this->request)($p->public_id, 1000, null, ['01notanattempt00000000000000']), 'refund_transaction_invalid');
        ($this->books)();
    });
});

describe('approving', function () {
    it('moves a request to approved and records who approved it', function () {
        $p = ($this->paid)();
        $r = ($this->request)($p->public_id, 100000);

        $a = ($this->approve)($r);

        expect($a->status)->toBe(RefundStatus::Approved)->and($a->approved_by)->toBe($this->staffB->id)->and(DB::table('audit_events')->where('action', 'refund.approved')->count())->toBe(1);
    });

    it('needs a second person for a large refund, and not for a small one', function () {
        $p = ($this->paid)(1);
        $q = ($this->paid)(2);
        app(Settings::class)->set('payments.refund_second_approver_minor', 100000);   // ৳1,000

        $big = ($this->request)($p->public_id, 150000);
        ($this->refuses)(fn () => ($this->approve)($big, $this->staffA), 'refund_needs_second_person');   // the person who asked cannot approve it
        expect(($this->fresh)($big)->status)->toBe(RefundStatus::Requested)->and(($this->approve)($big, $this->staffB)->status)->toBe(RefundStatus::Approved);

        $small = ($this->request)($q->public_id, 100000);   // exactly at the limit is not "above" it
        expect(($this->approve)($small, $this->staffA)->status)->toBe(RefundStatus::Approved);
    });

    it('lets the limit be changed or switched off', function () {
        $p = ($this->paid)(1);
        $q = ($this->paid)(2);

        app(Settings::class)->set('payments.refund_second_approver_minor', 0);
        expect(($this->approve)(($this->request)($p->public_id, 150000), $this->staffA)->status)->toBe(RefundStatus::Approved);

        app(Settings::class)->set('payments.refund_second_approver_minor', 1000);
        ($this->refuses)(fn () => ($this->approve)(($this->request)($q->public_id, 5000), $this->staffA), 'refund_needs_second_person');
    });

    it('can be done once, and only while waiting', function () {
        $p = ($this->paid)();
        $r = ($this->request)($p->public_id, 100000);
        ($this->approve)($r);

        ($this->refuses)(fn () => ($this->approve)($r), 'refund_not_pending');
        ($this->refuses)(fn () => app(ApproveRefund::class)->handle($this->staffB, '01nosuchrefund00000000000000'), 'refund_not_found');
    });
});

describe('rejecting', function () {
    it('gives the reserved amount back, with the reason on record, so it can be asked for again', function () {
        $p = ($this->paid)();
        $r = ($this->request)($p->public_id, 150000);

        $rej = app(RejectRefund::class)->handle($this->staffB, $r->public_id, 'Customer withdrew the request');

        expect($rej->status)->toBe(RefundStatus::Rejected)->and($rej->failure_reason)->toBe('Customer withdrew the request')->and(($this->pay)()->refunded_minor)->toBe(0)->and(($this->request)($p->public_id, 150000)->status)->toBe(RefundStatus::Requested);
        ($this->books)();
    });

    it('needs a reason, and only works on a request that is still waiting', function () {
        $p = ($this->paid)();
        $r = ($this->request)($p->public_id, 100000);

        ($this->refuses)(fn () => app(RejectRefund::class)->handle($this->staffB, $r->public_id, ' '), 'refund_reason_required');
        ($this->approve)($r);
        ($this->refuses)(fn () => app(RejectRefund::class)->handle($this->staffB, $r->public_id, 'too late'), 'refund_not_pending');
        expect(($this->pay)()->refunded_minor)->toBe(100000);
    });
});

describe('sending a refund through the gateway', function () {
    it('asks the gateway once with our refund id and the bank transaction id, and waits for it to say it is done', function () {
        $p = ($this->paid)();
        $r = ($this->approve)(($this->request)($p->public_id, 150000));

        $sent = ($this->send)($r);

        expect($sent->status)->toBe(RefundStatus::Processing)->and($sent->provider_refund_id)->toBe('FAKEREF-'.$r->refund_ref)->and(count($this->gateway->refunds))->toBe(1);
        $call = $this->gateway->refunds[0];
        expect($call->providerTxnId)->toBe('BANK-1')->and($call->refundRef)->toBe($r->refund_ref)->and($call->amount->amountMinor)->toBe(150000)->and($call->reason)->toBe('Customer returned the item');
        expect(($this->order)()->payment_status)->toBe('paid')->and(DB::table('outbox_messages')->where('event_name', 'payments.refund_succeeded')->count())->toBe(0);   // not "refunded" until the gateway says so
        ($this->books)();
    });

    it('is sent once however many times it is pressed', function () {
        $p = ($this->paid)();
        $r = ($this->approve)(($this->request)($p->public_id, 150000));
        ($this->send)($r);

        ($this->refuses)(fn () => ($this->send)($r), 'refund_already_processing');

        expect(count($this->gateway->refunds))->toBe(1);
    });

    it('is only for approved gateway refunds', function () {
        $p = ($this->paid)(1);
        $q = ($this->paid)(2);
        $pending = ($this->request)($p->public_id, 150000);
        $manual = ($this->approve)(($this->request)($q->public_id, 150000, null, [null, true]));

        ($this->refuses)(fn () => ($this->send)($pending), 'refund_not_approved');
        ($this->refuses)(fn () => ($this->send)($manual), 'refund_wrong_method');
        expect(count($this->gateway->refunds))->toBe(0);
    });

    it('is not lost when the gateway cannot be reached: it stays under way and the same call is repeated later', function () {
        $p = ($this->paid)();
        $r = ($this->approve)(($this->request)($p->public_id, 150000));
        $this->gateway->failNext('refund');

        $first = ($this->send)($r);

        expect($first->status)->toBe(RefundStatus::Processing)->and($first->provider_refund_id)->toBeNull()->and(count($this->gateway->refunds))->toBe(0);
        expect(($this->poll)()['resent'])->toBe(0);   // too soon: the first call may still be answering

        $this->travel(2)->minutes();
        expect(($this->poll)()['resent'])->toBe(1);
        $again = ($this->fresh)($r);
        expect($again->provider_refund_id)->toBe('FAKEREF-'.$r->refund_ref)->and(count($this->gateway->refunds))->toBe(1)->and($this->gateway->refunds[0]->refundRef)->toBe($r->refund_ref);   // the SAME refund id
    });

    it('fails cleanly when the gateway refuses, and the money can be asked for again', function () {
        $p = ($this->paid)();
        $r = ($this->approve)(($this->request)($p->public_id, 150000));
        $this->gateway->refuseNextRefund('Refund amount exceeds the transaction amount');

        $sent = ($this->send)($r);

        expect($sent->status)->toBe(RefundStatus::Failed)->and($sent->failure_reason)->toBe('Refund amount exceeds the transaction amount')->and(($this->pay)()->refunded_minor)->toBe(0)
            ->and(($this->request)($p->public_id, 150000, null, [null, true])->method)->toBe(RefundMethod::Manual);
        ($this->books)();
    });
});

describe('following a refund until the gateway says it is done', function () {
    beforeEach(function () {
        $this->sent = function (int $minor = 150000, int $id = 1) {
            $p = ($this->paid)($id);
            $r = ($this->approve)(($this->request)($p->public_id, $minor));

            return ($this->send)($r);
        };
    });

    it('makes the order "refunded" and tells the customer when a full refund is done', function () {
        $r = ($this->sent)();

        expect(($this->poll)())->toBe(['succeeded' => 1, 'failed' => 0, 'resent' => 0, 'stuck' => 0]);

        expect(($this->fresh)($r)->status)->toBe(RefundStatus::Succeeded)->and(($this->order)()->payment_status)->toBe('refunded')->and(DB::table('outbox_messages')->where('event_name', 'payments.refund_succeeded')->count())->toBe(1)
            ->and(collect($this->sms->sent())->contains(fn ($m) => str_contains($m['message'], 'VM-TEST-000001') && str_contains($m['message'], '1,500')))->toBeTrue()
            ->and(DB::table('order_notes')->where('order_id', 1)->where('body', 'like', 'Refund completed%')->count())->toBe(1);
        ($this->books)();
    });

    it('makes it "partly refunded" for a part, and "refunded" once the parts add up', function () {
        $p = ($this->paid)();
        $a = ($this->send)(($this->approve)(($this->request)($p->public_id, 50000)));
        ($this->poll)();
        expect(($this->order)()->payment_status)->toBe('partially_refunded');

        $b = ($this->send)(($this->approve)(($this->request)($p->public_id, 100000)));
        ($this->poll)();
        expect(($this->order)()->payment_status)->toBe('refunded')->and(($this->fresh)($a)->status)->toBe(RefundStatus::Succeeded)->and(($this->fresh)($b)->status)->toBe(RefundStatus::Succeeded);
        ($this->books)();
    });

    it('leaves a refund alone while the gateway says it is still processing', function () {
        $r = ($this->sent)();
        $this->gateway->refundWillBe('FAKEREF-'.$r->refund_ref, 'processing');

        expect(($this->poll)()['succeeded'])->toBe(0);

        expect(($this->fresh)($r)->status)->toBe(RefundStatus::Processing)->and(($this->order)()->payment_status)->toBe('paid')->and(($this->pay)()->refunded_minor)->toBe(150000);
    });

    it('fails a refund the gateway cancelled, and gives the money back to what can be refunded', function () {
        $r = ($this->sent)();
        $this->gateway->refundWillBe('FAKEREF-'.$r->refund_ref, 'cancelled');

        expect(($this->poll)()['failed'])->toBe(1);

        expect(($this->fresh)($r)->status)->toBe(RefundStatus::Failed)->and(($this->fresh)($r)->failure_reason)->toBe('cancelled_by_gateway')->and(($this->pay)()->refunded_minor)->toBe(0)->and(($this->order)()->payment_status)->toBe('paid');
        ($this->books)();
    });

    it('does nothing when the gateway cannot be asked, and finishes the next time', function () {
        $r = ($this->sent)();
        $this->gateway->failNext('refundStatus');

        expect(($this->poll)()['succeeded'])->toBe(0);
        expect(($this->fresh)($r)->status)->toBe(RefundStatus::Processing);

        expect(($this->poll)()['succeeded'])->toBe(1);
    });

    it('does not finish a refund twice, however many times it is polled', function () {
        ($this->sent)();
        ($this->poll)();
        ($this->poll)();
        ($this->poll)();

        expect(DB::table('outbox_messages')->where('event_name', 'payments.refund_succeeded')->count())->toBe(1)->and(DB::table('order_notes')->where('order_id', 1)->where('body', 'like', 'Refund completed%')->count())->toBe(1);
    });

    it('raises a refund that has been "processing" for days for finance, once', function () {
        $r = ($this->sent)();
        $this->gateway->refundWillBe('FAKEREF-'.$r->refund_ref, 'processing');
        $this->travel(4)->days();

        expect(($this->poll)()['stuck'])->toBe(1);
        ($this->poll)();

        expect(DB::table('reconciliation_exceptions')->where('type', 'refund_stuck')->count())->toBe(1);
    });
});

describe('refunds paid by hand', function () {
    it('are completed with the proof of payment, and then the order follows', function () {
        $p = ($this->paid)();
        $r = ($this->approve)(($this->request)($p->public_id, 150000, null, [null, true]));

        $done = app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, '  bKash TrxID 9XK2L81  ');

        expect($done->status)->toBe(RefundStatus::Succeeded)->and($done->proof_ref)->toBe('bKash TrxID 9XK2L81')->and(($this->order)()->payment_status)->toBe('refunded')->and(DB::table('audit_events')->where('action', 'refund.completed_manually')->count())->toBe(1)
            ->and(count($this->gateway->refunds))->toBe(0);
        ($this->books)();
    });

    it('need proof, an approval and the manual method', function () {
        $p = ($this->paid)(1);
        $q = ($this->paid)(2);
        $manual = ($this->request)($p->public_id, 150000, null, [null, true]);
        $viaGateway = ($this->approve)(($this->request)($q->public_id, 150000));

        ($this->refuses)(fn () => app(CompleteManualRefund::class)->handle($this->staffB, $manual->public_id, 'slip 1'), 'refund_not_approved');
        ($this->approve)($manual);
        ($this->refuses)(fn () => app(CompleteManualRefund::class)->handle($this->staffB, $manual->public_id, '   '), 'refund_proof_required');
        ($this->refuses)(fn () => app(CompleteManualRefund::class)->handle($this->staffB, $viaGateway->public_id, 'slip 2'), 'refund_wrong_method');
        expect(($this->fresh)($manual)->status)->toBe(RefundStatus::Approved);
    });

    it('can be completed once only', function () {
        $p = ($this->paid)();
        $r = ($this->approve)(($this->request)($p->public_id, 150000, null, [null, true]));
        app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, 'slip 1');

        ($this->refuses)(fn () => app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, 'slip 1 again'), 'refund_not_approved');
        expect(DB::table('outbox_messages')->where('event_name', 'payments.refund_succeeded')->count())->toBe(1);
    });

    it('let a fully refunded order be cancelled (a paid one still cannot be)', function () {
        $p = ($this->paid)();
        expect(fn () => app(TransitionOrder::class)->status(Actor::system('staff'), 1, OrderStatus::Cancelled, 'x'))->toThrow(OrderException::class, 'cancel_needs_refund');

        $r = ($this->approve)(($this->request)($p->public_id, 150000, null, [null, true]));
        app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, 'slip 1');

        expect(app(TransitionOrder::class)->status(Actor::system('staff'), 1, OrderStatus::Cancelled, 'Refunded and cancelled')->status)->toBe(OrderStatus::Cancelled);
    });

    it('do not stop a partly refunded order from needing the rest back first', function () {
        $p = ($this->paid)();
        $r = ($this->approve)(($this->request)($p->public_id, 50000, null, [null, true]));
        app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, 'slip 1');

        expect(($this->order)()->payment_status)->toBe('partially_refunded')
            ->and(fn () => app(TransitionOrder::class)->status(Actor::system('staff'), 1, OrderStatus::Cancelled, 'x'))->toThrow(OrderException::class, 'cancel_needs_refund');
    });
});

describe('what the order does with a refund', function () {
    it('only notes the return of a double payment or of a late payment, and never errors', function () {
        $p = ($this->paid)(1);
        // a late payment: the order is cancelled and unpaid; the payment waits for review
        fakeOrders([2], ['payment_method_code' => 'online']);
        DB::table('orders')->where('id', 2)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);
        $event = new RefundSucceeded(1, $p->public_id, 2, 150000, 'BDT', 'r1', 'full');
        app(ApplyPaymentEvent::class)->refundSucceeded($event);
        $extra = new RefundSucceeded(1, $p->public_id, 1, 150000, 'BDT', 'r2', 'extra');
        app(ApplyPaymentEvent::class)->refundSucceeded($extra);

        expect(($this->order)(2)->payment_status)->toBe('unpaid')->and(($this->order)(1)->payment_status)->toBe('paid')
            ->and(DB::table('order_notes')->where('body', 'like', 'Refund completed%')->count())->toBe(2)->and(count($this->sms->sent()))->toBe(0);
    });

    it('applies each announcement once even if it is delivered twice', function () {
        $p = ($this->paid)();
        $r = ($this->approve)(($this->request)($p->public_id, 150000, null, [null, true]));
        app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, 'slip 1');
        $row = DB::table('outbox_messages')->where('event_name', 'payments.refund_succeeded')->first();
        $event = RefundSucceeded::fromPayload(json_decode($row->payload, true));
        $event->outboxId = (int) $row->id;
        $before = DB::table('order_notes')->count();

        app(ApplyPaymentEvent::class)->refundSucceeded($event);
        app(ApplyPaymentEvent::class)->refundSucceeded($event);

        expect(DB::table('order_notes')->count())->toBe($before)->and(($this->order)()->payment_status)->toBe('refunded');
    });

    it('tells a Bangla-speaking customer in Bangla', function () {
        $p = ($this->paid)(1, ['locale' => 'bn']);
        $r = ($this->approve)(($this->request)($p->public_id, 150000, null, [null, true]));

        app(CompleteManualRefund::class)->handle($this->staffB, $r->public_id, 'slip 1');

        expect(collect($this->sms->sent())->contains(fn ($m) => str_contains($m['message'], 'ফেরত দেওয়া হয়েছে')))->toBeTrue();
    });
});

describe('the database', function () {
    it('freezes what a refund was for: its amount, payment, attempt and reference', function () {
        $p = ($this->paid)();
        $r = ($this->request)($p->public_id, 100000);

        foreach (['amount_minor' => 1, 'payment_transaction_id' => null, 'refund_ref' => 'OTHER', 'method' => 'manual', 'reason' => 'changed'] as $col => $value) {
            expect(fn () => DB::table('refunds')->where('id', $r->id)->update([$col => $value]), $col)->toThrow(QueryException::class);
        }
        expect(fn () => DB::table('refunds')->where('id', $r->id)->delete())->toThrow(QueryException::class);
    });

    it('refuses a total refunded that is more than was received, whatever the application does', function () {
        $p = ($this->paid)();

        expect(fn () => DB::table('payments')->where('id', $p->id)->update(['refunded_minor' => 150001]))->toThrow(QueryException::class);
    });
});
