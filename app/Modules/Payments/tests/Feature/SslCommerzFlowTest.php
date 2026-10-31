<?php

declare(strict_types=1);

use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\ProcessPaymentEventAction;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\VerifyOutcome;
use App\Modules\Payments\Jobs\ProcessPaymentEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Providers\PaymentsServiceProvider;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * The real SSLCommerz adapter (HTTP faked, answers shaped like the documentation's examples) driving the real payment and order code.
 * Nothing here touches a network or a real account.
 */

const FLOW_PASS = 'F10wS3cretPassw0rd';
const FLOW_STORE = 'flowshop@ssl';
const FLOW_REF = 'VM-TEST-000001-1';

function flowFixture(string $name, array $over = []): array
{
    return array_replace(json_decode((string) file_get_contents(__DIR__.'/../Fixtures/sslcommerz/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR), $over);
}

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => true]);   // provider stays "sslcommerz"
    (app()->getProvider(PaymentsServiceProvider::class))->registerSslCommerz(app(GatewayRegistry::class), ['store_id' => FLOW_STORE, 'store_password' => FLOW_PASS, 'sandbox' => true], false, false);
    $this->logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $this->logs[] = $e->message.' '.json_encode($e->context);
    });

    /** @param array<string, array|Closure> $routes answered by URL fragment; a list of answers is used one after the other */
    $this->api = function (array $routes) {
        $this->app->forgetInstance(HttpFactory::class);
        Http::clearResolvedInstance(HttpFactory::class);
        $calls = [];
        Http::fake(function (HttpRequest $request) use ($routes, &$calls) {
            foreach ($routes as $fragment => $answer) {
                if (str_contains($request->url(), $fragment)) {
                    $calls[$fragment] = ($calls[$fragment] ?? -1) + 1;
                    $one = is_array($answer) && array_is_list($answer) && $answer !== [] && is_array($answer[0]) ? $answer[min($calls[$fragment], count($answer) - 1)] : $answer;

                    return $one instanceof Closure ? $one() : Http::response($one, 200);
                }
            }

            return Http::response('unexpected call to '.$request->url(), 500);
        });
    };
    $this->requests = fn () => Http::recorded()->map(fn ($p) => $p[0]);

    fakeOrders([1], ['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]);
    $this->order = new OrderPaymentRequest(1, (string) DB::table('orders')->where('id', 1)->value('public_id'), 'VM-TEST-000001', 150000, 'BDT', 'online', 'Rahim Uddin', '+8801712345678', 'rahim@example.com', 'House 5', 'Dhaka', '1205', 2, true, 'Tee ×2', 'en');
    ($this->api)(['/gwprocess/v4/api.php' => flowFixture('initiation_success')]);
    $this->redirect = app(InitiatePayment::class)->handle($this->order);

    $this->ipn = fn (array $over = []) => $this->postJson('/api/v1/webhooks/payments/sslcommerz', array_replace(flowFixture('ipn_valid'), ['tran_id' => FLOW_REF, 'amount' => '1500.00', 'currency_amount' => '1500.00', 'val_id' => 'VAL-FLOW-1', 'bank_tran_id' => 'BANK-FLOW-1', 'tran_date' => '2026-10-31 10:00:00'], $over));
    $this->validated = fn (array $over = []) => flowFixture('validation_validated', $over + ['tran_id' => FLOW_REF, 'amount' => '1500.00', 'currency_amount' => '1500.00', 'val_id' => 'VAL-FLOW-1', 'bank_tran_id' => 'BANK-FLOW-1', 'status' => 'VALID']);   // overrides win
    $this->none = ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []];
    $this->payment = fn () => Payment::query()->where('order_id', 1)->firstOrFail();
    $this->order_ = fn () => DB::table('orders')->where('id', 1)->first(['status', 'payment_status', 'needs_review']);
    $this->events = fn (string $name) => DB::table('outbox_messages')->where('event_name', $name)->count();
});

it('starts a real-shaped session at the sandbox gateway and sends the customer to SSLCommerz\'s page', function () {
    $session = ($this->requests)()->first();

    expect($this->redirect)->toBe('https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=PAY&SESSIONKEY=F298BC45B0688E02768900C4F6B28C8B')->and($session->url())->toBe('https://sandbox.sslcommerz.com/gwprocess/v4/api.php')
        ->and($session->data()['tran_id'])->toBe(FLOW_REF)->and($session->data()['total_amount'])->toBe('1500.00')->and($session->data()['ipn_url'])->toEndWith('/api/v1/webhooks/payments/sslcommerz')
        ->and(DB::table('payment_transactions')->value('provider_session'))->toBe('F298BC45B0688E02768900C4F6B28C8B')->and(($this->payment)()->status)->toBe(PaymentState::Pending);
});

it('pays and confirms the order when SSLCommerz\'s own validation confirms this reference and amount', function () {
    ($this->api)(['validationserverAPI.php' => ($this->validated)()]);

    ($this->ipn)()->assertOk();

    $tx = DB::table('payment_transactions')->first();
    expect(($this->payment)()->status)->toBe(PaymentState::Paid)->and(($this->payment)()->captured_minor)->toBe(150000)->and($tx->status)->toBe('succeeded')->and($tx->provider_txn_id)->toBe('BANK-FLOW-1')
        ->and($tx->provider_validation_id)->toBe('VAL-FLOW-1')->and(($this->order_)()->payment_status)->toBe('paid')->and(($this->order_)()->status)->toBe('confirmed')->and(($this->events)('payments.succeeded'))->toBe(1);
    expect(($this->requests)()->last()->url())->toStartWith('https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php?');
});

it('is not fooled by a notification that claims "VALID" for a payment that SSLCommerz does not have', function () {
    ($this->api)(['validationserverAPI.php' => ['status' => 'INVALID_TRANSACTION', 'APIConnect' => 'FAILED'], 'merchantTransIDvalidationAPI.php' => ($this->none)]);

    ($this->ipn)(['val_id' => 'FORGED-1'])->assertOk();

    expect(($this->payment)()->status)->toBe(PaymentState::Pending)->and(($this->payment)()->captured_minor)->toBe(0)->and(($this->order_)()->payment_status)->toBe('pending')->and(($this->events)('payments.succeeded'))->toBe(0);
});

it('is not fooled by borrowing the validation id of another real, paid order: our own reference is asked instead', function () {
    ($this->api)([
        'validationserverAPI.php' => ($this->validated)(['tran_id' => 'VM-TEST-000777-1', 'amount' => '1500.00']),   // genuinely paid, but order 777's money
        'merchantTransIDvalidationAPI.php' => ($this->none),
    ]);

    ($this->ipn)(['val_id' => 'VAL-OF-ORDER-777'])->assertOk();

    expect(($this->payment)()->status)->toBe(PaymentState::Pending)->and(($this->payment)()->captured_minor)->toBe(0)->and(($this->events)('payments.succeeded'))->toBe(0)->and(DB::table('reconciliation_exceptions')->count())->toBe(0);   // not even a false alarm
});

it('holds a payment whose amount SSLCommerz reports differently, and a payment in another currency', function () {
    ($this->api)(['validationserverAPI.php' => ($this->validated)(['amount' => '1000.00', 'currency_amount' => '1000.00'])]);
    ($this->ipn)(['event_marker' => 'a'])->assertOk();
    expect(($this->payment)()->status)->toBe(PaymentState::NeedsReview)->and(($this->payment)()->captured_minor)->toBe(0)->and(DB::table('reconciliation_exceptions')->value('type'))->toBe('amount_mismatch');
});

it('holds a payment whose two BDT amount fields disagree, instead of guessing which is right', function () {
    ($this->api)(['validationserverAPI.php' => ($this->validated)(['amount' => '1500.00', 'currency_amount' => '1400.00'])]);

    ($this->ipn)()->assertOk();

    expect(($this->payment)()->status)->toBe(PaymentState::NeedsReview)->and(($this->payment)()->captured_minor)->toBe(0)->and(DB::table('reconciliation_exceptions')->value('type'))->toBe('currency_mismatch');
});

it('holds a payment paid in a different currency', function () {
    ($this->api)(['validationserverAPI.php' => ($this->validated)(['currency_type' => 'USD', 'currency_amount' => '15.00', 'amount' => '1500.00'])]);

    ($this->ipn)()->assertOk();

    expect(($this->payment)()->status)->toBe(PaymentState::NeedsReview)->and(($this->payment)()->captured_minor)->toBe(0)->and(DB::table('reconciliation_exceptions')->value('type'))->toBe('currency_mismatch');
});

it('still records a success that comes after a failed try in the same gateway session (it must never be lost)', function () {
    // 1. the customer's first card is declined: the gateway reports it as failed
    ($this->api)(['merchantTransIDvalidationAPI.php' => flowFixture('query_by_tran_id', ['element' => [array_replace(flowFixture('query_by_tran_id')['element'][0], ['tran_id' => FLOW_REF, 'status' => 'FAILED'])], 'no_of_trans_found' => 1]), 'validationserverAPI.php' => ['status' => 'INVALID_TRANSACTION']]);
    ($this->ipn)(['status' => 'FAILED', 'val_id' => 'VAL-FAILED-1', 'event_marker' => 'first'])->assertOk();

    expect(DB::table('payment_transactions')->value('status'))->toBe('failed')->and(($this->payment)()->status)->toBe(PaymentState::Failed)->and(($this->order_)()->payment_status)->toBe('failed');

    // 2. in the same session the customer pays with another card
    ($this->api)(['validationserverAPI.php' => ($this->validated)()]);
    ($this->ipn)(['status' => 'VALID', 'val_id' => 'VAL-FLOW-1', 'event_marker' => 'second'])->assertOk();

    $tx = DB::table('payment_transactions')->first();
    expect($tx->status)->toBe('succeeded')->and($tx->provider_txn_id)->toBe('BANK-FLOW-1')->and(($this->payment)()->status)->toBe(PaymentState::Paid)->and(($this->payment)()->captured_minor)->toBe(150000)
        ->and(($this->order_)()->payment_status)->toBe('paid')->and(($this->order_)()->status)->toBe('confirmed')->and(($this->events)('payments.succeeded'))->toBe(1);
});

it('does not turn a paid attempt back into a failed one, however late a "failed" notice arrives', function () {
    ($this->api)(['validationserverAPI.php' => ($this->validated)()]);
    ($this->ipn)(['status' => 'VALID'])->assertOk();

    ($this->api)(['merchantTransIDvalidationAPI.php' => flowFixture('query_by_tran_id', ['element' => [array_replace(flowFixture('query_by_tran_id')['element'][0], ['tran_id' => FLOW_REF, 'status' => 'FAILED'])]])]);
    ($this->ipn)(['status' => 'FAILED', 'val_id' => 'VAL-FAILED-2', 'event_marker' => 'late'])->assertOk();

    expect(DB::table('payment_transactions')->value('status'))->toBe('succeeded')->and(($this->payment)()->status)->toBe(PaymentState::Paid)->and(($this->order_)()->payment_status)->toBe('paid');
});

it('stops asking about an attempt that closed unpaid more than a week ago', function () {
    DB::table('payment_transactions')->update(['status' => 'failed']);
    DB::table('payments')->update(['status' => 'failed']);
    $this->travel(8)->days();   // the attempt is now eight days old
    ($this->api)([]);

    $outcome = app(VerifyPayment::class)->handle(FLOW_REF);

    expect($outcome)->toBe(VerifyOutcome::Failed)->and(($this->requests)())->toHaveCount(0);
});

it('pays a high-risk payment but holds the order for a person instead of confirming it', function () {
    ($this->api)(['validationserverAPI.php' => ($this->validated)(['risk_level' => '1', 'risk_title' => 'Risky'])]);

    ($this->ipn)()->assertOk();

    expect(($this->payment)()->status)->toBe(PaymentState::Paid)->and(($this->order_)()->payment_status)->toBe('paid')->and(($this->order_)()->needs_review)->toBe(1)->and(($this->order_)()->status)->toBe('pending')
        ->and(DB::table('order_notes')->where('order_id', 1)->value('body'))->toContain('high risk');
});

it('flags extra money when SSLCommerz reports more than one successful payment for our one reference', function () {
    $q = flowFixture('query_by_tran_id');
    $one = array_replace($q['element'][1], ['tran_id' => FLOW_REF, 'amount' => '1500.00', 'currency_amount' => '1500.00']);
    $two = array_replace($one, ['val_id' => 'VAL-2', 'bank_tran_id' => 'BANK-2']);
    ($this->api)(['validationserverAPI.php' => ['status' => 'INVALID_TRANSACTION'], 'merchantTransIDvalidationAPI.php' => ['APIConnect' => 'DONE', 'no_of_trans_found' => 2, 'element' => [$one, $two]]]);

    ($this->ipn)(['val_id' => 'NOT-A-REAL-ONE'])->assertOk();

    expect(($this->payment)()->status)->toBe(PaymentState::Paid)->and(($this->payment)()->captured_minor)->toBe(150000)->and(DB::table('reconciliation_exceptions')->where('type', 'duplicate_payment')->count())->toBe(1)
        ->and(json_decode((string) DB::table('reconciliation_exceptions')->value('details'), true)['successful_payments'])->toBe(2);
});

it('leaves everything waiting when SSLCommerz cannot be asked, and the retry then succeeds', function () {
    ($this->api)(['validationserverAPI.php' => fn () => Http::response('', 503)]);
    Queue::fake();
    ($this->ipn)()->assertOk();
    Queue::assertPushed(ProcessPaymentEvent::class);
    $eventId = (int) DB::table('payment_webhook_events')->value('id');
    $job = fn () => (new ProcessPaymentEvent($eventId))->handle(app(ProcessPaymentEventAction::class));

    expect($job)->toThrow(RuntimeException::class);
    expect(($this->payment)()->status)->toBe(PaymentState::Pending)->and(DB::table('payment_webhook_events')->value('status'))->toBe('received');

    ($this->api)(['validationserverAPI.php' => ($this->validated)()]);
    $job();
    expect(($this->payment)()->status)->toBe(PaymentState::Paid)->and(DB::table('payment_webhook_events')->value('status'))->toBe('processed');
});

it('refuses to treat a rejected shop account as "no payment": it throws, so nothing is concluded', function () {
    ($this->api)(['validationserverAPI.php' => ['status' => 'INVALID_TRANSACTION'], 'merchantTransIDvalidationAPI.php' => ['APIConnect' => 'FAILED', 'no_of_trans_found' => 0]]);

    expect(app(VerifyPayment::class)->handle(FLOW_REF, 'VAL-X'))->toBe(VerifyOutcome::ProviderError)->and(($this->payment)()->status)->toBe(PaymentState::Pending);
});

it('never stores or logs the store password or id anywhere: not in notifications, attempts, exceptions, events, notes or logs', function () {
    ($this->api)(['validationserverAPI.php' => ($this->validated)(['amount' => '1000.00', 'currency_amount' => '1000.00'])]);
    ($this->ipn)(['store_passwd' => FLOW_PASS, 'password' => FLOW_PASS])->assertOk();   // even if SSLCommerz echoed it back
    ($this->api)(['merchantTransIDvalidationAPI.php' => fn () => throw new ConnectionException('timeout for https://x/?store_passwd='.FLOW_PASS.'&store_id='.FLOW_STORE)]);
    app(VerifyPayment::class)->handle(FLOW_REF);

    $everything = json_encode([
        DB::table('payment_webhook_events')->get(), DB::table('payment_transactions')->get(), DB::table('payments')->get(), DB::table('reconciliation_exceptions')->get(),
        DB::table('outbox_messages')->get(), DB::table('order_notes')->get(), DB::table('audit_events')->get(), DB::table('order_status_history')->get(),
    ]).implode("\n", $this->logs);
    expect($everything)->not->toContain(FLOW_PASS)->not->toContain(FLOW_STORE);
});

it('can be started again after a failed attempt, as a new attempt with its own reference', function () {
    ($this->api)(['merchantTransIDvalidationAPI.php' => flowFixture('query_by_tran_id', ['element' => [array_replace(flowFixture('query_by_tran_id')['element'][0], ['tran_id' => FLOW_REF, 'status' => 'FAILED'])]]), 'validationserverAPI.php' => ['status' => 'INVALID_TRANSACTION']]);
    ($this->ipn)(['status' => 'FAILED', 'val_id' => 'V-F'])->assertOk();
    $this->travel(121)->seconds();
    ($this->api)(['/gwprocess/v4/api.php' => flowFixture('initiation_success')]);

    app(InitiatePayment::class)->handle($this->order);

    expect(DB::table('payment_transactions')->orderBy('attempt')->pluck('merchant_ref', 'attempt')->all())->toBe([1 => 'VM-TEST-000001-1', 2 => 'VM-TEST-000001-2'])->and(($this->requests)()->first()->data()['tran_id'])->toBe('VM-TEST-000001-2');
});
