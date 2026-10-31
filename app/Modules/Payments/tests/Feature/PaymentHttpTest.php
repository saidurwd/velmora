<?php

declare(strict_types=1);

use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\ProcessPaymentEventAction;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Jobs\ProcessPaymentEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->gateway = app(FakeGateway::class);
    $this->ref = 'VM-TEST-000001-1';

    fakeOrders([1], ['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]);
    $this->orderPublic = (string) DB::table('orders')->where('id', 1)->value('public_id');
    $this->request = new OrderPaymentRequest(1, $this->orderPublic, 'VM-TEST-000001', 150000, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en');
    app(InitiatePayment::class)->handle($this->request);

    $this->paid = fn (int $minor = 150000) => new VerifiedPayment(VerifiedStatus::Valid, $this->ref, Money::of($minor), 'BANK-1', 'VAL-1');
    $this->payment = fn () => Payment::query()->where('order_id', 1)->firstOrFail();
    $this->ipn = fn (array $over = [], string $provider = 'fake') => $this->postJson("/api/v1/webhooks/payments/{$provider}", array_replace(['tran_id' => $this->ref, 'status' => 'VALID', 'val_id' => 'VAL-1', 'amount' => '1500.00', 'event_id' => 'evt-1'], $over));
    $this->events = fn () => DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->count();
});

describe('the gateway\'s notification', function () {
    it('is stored, verified with the gateway, and then the payment is paid', function () {
        $this->gateway->answer($this->ref, ($this->paid)());

        ($this->ipn)()->assertOk()->assertSee('OK');

        $e = DB::table('payment_webhook_events')->first();
        expect($e->provider)->toBe('fake')->and($e->provider_event_id)->toBe('evt-1')->and($e->status)->toBe('processed')->and((bool) $e->verified)->toBeTrue()->and($e->payment_id)->toBe(($this->payment)()->id)->and($e->processed_at)->not->toBeNull()
            ->and(json_decode($e->payload, true))->toMatchArray(['tran_id' => $this->ref, 'status' => 'VALID', 'val_id' => 'VAL-1'])
            ->and(($this->payment)()->status)->toBe(PaymentState::Paid)->and(($this->events)())->toBe(1);
    });

    it('the same notification twice changes nothing the second time', function () {
        $this->gateway->answer($this->ref, ($this->paid)());

        ($this->ipn)()->assertOk();
        $asked = count($this->gateway->verifications);
        ($this->ipn)()->assertOk();
        ($this->ipn)()->assertOk();

        expect(DB::table('payment_webhook_events')->count())->toBe(1)->and(($this->events)())->toBe(1)->and(count($this->gateway->verifications))->toBe($asked);
    });

    it('is not believed on its own: a forged "VALID" for a payment the gateway does not know leaves it unpaid', function () {
        ($this->ipn)(['status' => 'VALID'])->assertOk();   // the gateway has no such payment

        $e = DB::table('payment_webhook_events')->first();
        expect(($this->payment)()->status)->toBe(PaymentState::Pending)->and(($this->payment)()->captured_minor)->toBe(0)->and($e->status)->toBe('processed')->and((bool) $e->verified)->toBeFalse()->and(($this->events)())->toBe(0);
    });

    it('is not believed on its own: a "VALID" whose real amount differs is held for a person', function () {
        $this->gateway->answer($this->ref, ($this->paid)(100));

        ($this->ipn)()->assertOk();

        expect(($this->payment)()->status)->toBe(PaymentState::NeedsReview)->and(($this->payment)()->captured_minor)->toBe(0)->and(($this->events)())->toBe(0);
    });

    it('about a transaction that is not ours is kept, ignored, and answered with 200', function () {
        ($this->ipn)(['tran_id' => 'SOMEONE-ELSES-1', 'event_id' => 'evt-9'])->assertOk();

        $e = DB::table('payment_webhook_events')->first();
        expect($e->status)->toBe('ignored')->and($e->error)->toBe('unknown_reference')->and($e->verified)->toBeNull()->and(($this->payment)()->status)->toBe(PaymentState::Pending);
    });

    it('never stores the store password, and keeps the rest exactly as it came', function () {
        ($this->ipn)(['store_passwd' => 'SECRET-PASSWORD', 'card_no' => '425272XXXXXX3456'])->assertOk();

        $stored = (string) DB::table('payment_webhook_events')->value('payload');
        expect($stored)->not->toContain('SECRET-PASSWORD')->and($stored)->toContain('425272XXXXXX3456');
    });

    it('answers 404 for a provider we do not have and 400 for a body that cannot be read', function () {
        ($this->ipn)([], 'nowhere')->assertNotFound();
        $this->postJson('/api/v1/webhooks/payments/fake', ['status' => 'VALID'])->assertStatus(400);

        expect(DB::table('payment_webhook_events')->count())->toBe(0);
    });

    it('needs no CSRF token or session: it is a server talking to a server', function () {
        $this->post('/api/v1/webhooks/payments/fake', ['tran_id' => $this->ref, 'status' => 'FAILED', 'event_id' => 'evt-form'])->assertOk();   // a plain form post, no token
    });

    it('is queued, and if the gateway cannot be asked right now it stays waiting until a retry works', function () {
        Queue::fake();
        $this->gateway->answer($this->ref, ($this->paid)());

        ($this->ipn)()->assertOk();

        Queue::assertPushed(ProcessPaymentEvent::class, 1);
        $eventId = (int) DB::table('payment_webhook_events')->value('id');
        expect(DB::table('payment_webhook_events')->value('status'))->toBe('received')->and(($this->payment)()->status)->toBe(PaymentState::Pending);

        $this->gateway->failNext('verify');
        expect(fn () => (new ProcessPaymentEvent($eventId))->handle(app(ProcessPaymentEventAction::class)))->toThrow(RuntimeException::class);
        expect(DB::table('payment_webhook_events')->value('status'))->toBe('received');

        (new ProcessPaymentEvent($eventId))->handle(app(ProcessPaymentEventAction::class));   // the retry
        expect(DB::table('payment_webhook_events')->value('status'))->toBe('processed')->and(($this->payment)()->status)->toBe(PaymentState::Paid);
    });

    it('arriving after the customer\'s own return changes nothing', function () {
        $this->gateway->answer($this->ref, ($this->paid)());
        $this->get($this->gateway->initiated[0]->successUrl)->assertRedirect();
        expect(($this->events)())->toBe(1);

        ($this->ipn)()->assertOk();

        expect(($this->events)())->toBe(1)->and(($this->payment)()->captured_minor)->toBe(150000);
    });
});

describe('the customer coming back from the gateway', function () {
    it('is sent to their order page after the payment has been verified server to server', function () {
        $this->gateway->answer($this->ref, ($this->paid)());

        $r = $this->get($this->gateway->initiated[0]->successUrl);

        $r->assertRedirect();
        $to = $r->headers->get('Location');
        expect($to)->toContain("/en/orders/{$this->orderPublic}/thanks")->and($to)->toContain('signature=')->and(($this->payment)()->status)->toBe(PaymentState::Paid);
        $r->assertSessionHas('payment_outcome', 'paid');
        $this->get($to)->assertOk()->assertSee('VM-TEST-000001');
    });

    it('also works as the form POST the gateway sends, with no CSRF token', function () {
        $this->gateway->answer($this->ref, ($this->paid)());

        $this->post($this->gateway->initiated[0]->successUrl, ['status' => 'VALID', 'val_id' => 'VAL-1', 'tran_id' => $this->ref])->assertRedirect();

        expect(($this->payment)()->status)->toBe(PaymentState::Paid)->and($this->gateway->verifications[0]->providerValidationId)->toBe('VAL-1');
    });

    it('does not believe the address it came back to: "success" with no payment at the gateway leaves the order unpaid', function () {
        $r = $this->get($this->gateway->initiated[0]->successUrl);   // the gateway has nothing for this reference

        $r->assertRedirect();
        expect(($this->payment)()->status)->toBe(PaymentState::Pending)->and(($this->payment)()->captured_minor)->toBe(0)->and(($this->events)())->toBe(0)
            ->and(DB::table('orders')->where('id', 1)->value('payment_status'))->toBe('pending');   // started, not paid
    });

    it('does not believe a forged form either: "VALID" in the body, nothing at the gateway', function () {
        $this->post($this->gateway->initiated[0]->successUrl, ['status' => 'VALID', 'val_id' => 'FORGED', 'amount' => '1500.00'])->assertRedirect();

        expect(($this->payment)()->status)->toBe(PaymentState::Pending)->and(($this->events)())->toBe(0);
    });

    it('records a cancellation the gateway confirms', function () {
        $this->gateway->answer($this->ref, new VerifiedPayment(VerifiedStatus::Cancelled, $this->ref, null));

        $this->get($this->gateway->initiated[0]->cancelUrl)->assertRedirect()->assertSessionHas('payment_outcome', 'cancelled');

        expect(($this->payment)()->status)->toBe(PaymentState::Cancelled);
    });

    it('refuses a link without its signature, with a changed one, for another payment, or after it expired', function () {
        $good = $this->gateway->initiated[0]->successUrl;
        $path = parse_url($good, PHP_URL_PATH);

        $this->get($path)->assertForbidden();
        $this->get($good.'x')->assertForbidden();
        $this->get(str_replace('/success', '/fail', $good))->assertForbidden();   // the outcome is part of what was signed
        $this->get(preg_replace('#/pay/return/[^/]+/#', '/pay/return/'.strtolower((string) Str::ulid()).'/', $good))->assertForbidden();
        $this->travel(3)->days();
        $this->get($good)->assertForbidden();
        $this->travelBack();

        expect($this->gateway->verifications)->toBe([]);   // none of them reached the gateway
    });

    it('is a 404 for an outcome we never issued, even with a valid signature', function () {
        $url = URL::temporarySignedRoute('payments.return', now()->addHour(), ['locale' => 'en', 'payment' => ($this->payment)()->public_id, 'outcome' => 'maybe']);

        $this->get($url)->assertNotFound();
    });
});

describe('paying (again) from the order page', function () {
    beforeEach(function () {
        $this->payLink = fn (?string $order = null) => URL::temporarySignedRoute('payments.pay', now()->addDay(), ['locale' => 'en', 'order' => $order ?? $this->orderPublic]);
    });

    it('starts a new attempt and sends the customer to the gateway', function () {
        $this->gateway->answer($this->ref, new VerifiedPayment(VerifiedStatus::Failed, $this->ref, null));
        app(VerifyPayment::class)->handle($this->ref);

        $this->get(($this->payLink)())->assertRedirect('https://fake-gateway.test/pay/VM-TEST-000001-2');

        expect(DB::table('payment_transactions')->count())->toBe(2)->and(($this->payment)()->status)->toBe(PaymentState::Pending);
    });

    it('does nothing for an order that is paid, cancelled, or paid in cash, and goes back to the order page', function () {
        fakeOrders([2, 3], ['payment_method_code' => 'online', 'grand_total_minor' => 150000, 'subtotal_minor' => 150000]);
        fakeOrders([4], ['payment_method_code' => 'cod', 'grand_total_minor' => 150000, 'subtotal_minor' => 150000]);
        DB::table('orders')->where('id', 2)->update(['payment_status' => 'paid']);
        DB::table('orders')->where('id', 3)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);

        foreach ([2, 3, 4] as $id) {
            $public = (string) DB::table('orders')->where('id', $id)->value('public_id');
            $this->get(($this->payLink)($public))->assertRedirect()->assertRedirectContains("/en/orders/{$public}/thanks");
        }
        expect(Payment::query()->whereIn('order_id', [2, 3, 4])->count())->toBe(0);
    });

    it('needs the signed link', function () {
        $this->get('/en/pay/order/'.$this->orderPublic)->assertForbidden();
        $this->get(($this->payLink)().'x')->assertForbidden();
    });

    it('goes back to the order page with a plain message if the gateway cannot be reached', function () {
        $this->travel(121)->seconds();   // the page of the attempt started in beforeEach would otherwise be reused
        $this->gateway->failNext('initiate');
        DB::table('payments')->where('order_id', 1)->update(['status' => 'failed']);

        $this->get(($this->payLink)())->assertRedirectContains('/thanks')->assertSessionHas('payment_error', 'initiation_failed');
    });
});
