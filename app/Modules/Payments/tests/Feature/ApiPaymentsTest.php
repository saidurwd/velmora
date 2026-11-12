<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\DigitalProducts\Database\Seeders\RefundPolicySeeder;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\CancelReason;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Phase 11, slice 5: paying from the app. Money. The app opens the gateway page and then ASKS this server; the server asks the gateway (server to server) and
 * records the answer. Nothing the app says changes what is recorded. The gateway here is the in-memory fake: no real gateway is ever called.
 */

beforeEach(function () {
    $this->withoutMiddleware([ThrottleRequests::class, EnforceSessionPolicy::class]);
    Notification::fake();
    shopWorld($this);
    $this->seed([GeographySeeder::class, ShippingSeeder::class, RefundPolicySeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->gateway = app(FakeGateway::class);

    $dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->address = ['recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => (int) DB::table('districts')->where('id', $dhaka)->value('division_id'), 'district_id' => $dhaka, 'upazila_id' => (int) DB::table('upazilas')->where('district_id', $dhaka)->value('id'), 'address_line' => 'House 5, Road 2', 'postal_code' => '1205'];
    $this->amina = User::factory()->verified()->create(['email' => 'amina@example.com']);
    $this->anon = function (array $extra = []) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return $extra;
    };
    $this->as = function (User $u) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
    };
    $this->key = fn () => 'pay-'.bin2hex(random_bytes(8));
    // a guest places an online (or cash) order for two tees through the real checkout; returns its id, token and total
    $this->place = function (string $payment = 'online', int $qty = 2): array {
        $token = bin2hex(random_bytes(20));
        app(AddToCart::class)->handle(CartOwner::guest($token), $this->tee['variantId'], $qty);
        $body = ['address' => $this->address, 'shipping_method' => 'standard', 'payment_method' => $payment];
        $total = test()->postJson('/api/v1/checkout/quote', $body, ['X-Cart-Token' => $token])->assertOk()->json('data.totals.grand_total.amount_minor');
        $res = test()->postJson('/api/v1/checkout/orders', $body + ['expected_total' => $total], ['X-Cart-Token' => $token, 'Idempotency-Key' => ($this->key)()])->assertCreated();

        return ['id' => $res->json('data.order.id'), 'token' => $res->json('data.order_token'), 'total' => $total, 'next' => $res->json('data.next')];
    };
    $this->h = fn (array $o, array $extra = []) => ($this->anon)(['X-Order-Token' => $o['token']] + $extra);
    $this->start = fn (array $o, ?string $key = null) => $this->postJson("/api/v1/orders/{$o['id']}/payments", [], ($this->h)($o, ['Idempotency-Key' => $key ?? ($this->key)()]));
    $this->paid = fn (string $ref, int $minor) => new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of($minor), 'BANK-'.$ref, 'VAL-'.$ref);
    $this->ref = fn () => (string) DB::table('payment_transactions')->orderByDesc('id')->value('merchant_ref');
});

describe('starting a payment', function () {
    it('places an online order unpaid and says the next step is to pay; starting gives the gateway address', function () {
        $o = ($this->place)();
        expect($o['next'])->toBe('pay');

        $res = ($this->start)($o);
        $res->assertOk()->assertJsonPath('data.method', 'online')->assertJsonPath('data.redirect_url', fn ($u) => is_string($u) && $u !== '');
        assertMatchesOpenApi($res, 'POST', "/api/v1/orders/{$o['id']}/payments");
        expect($this->gateway->initiated)->toHaveCount(1)->and($this->gateway->initiated[0]->amount->amountMinor)->toBe($o['total']);
    });

    it('needs the Idempotency-Key; the same key starts one attempt however often it is sent, a new key is a retry', function () {
        $o = ($this->place)();

        $this->postJson("/api/v1/orders/{$o['id']}/payments", [], ($this->h)($o))->assertStatus(400)->assertJsonPath('code', 'idempotency.key_required');
        $key = ($this->key)();
        $first = ($this->start)($o, $key);
        $again = ($this->start)($o, $key);
        expect($again->headers->get('Idempotency-Replayed'))->toBe('true')->and($again->json('data.redirect_url'))->toBe($first->json('data.redirect_url'))->and($this->gateway->initiated)->toHaveCount(1);

        // a second tap with a NEW key while the first attempt is still open reuses that attempt: two open attempts for one order would let a person pay twice
        ($this->start)($o)->assertOk();
        expect($this->gateway->initiated)->toHaveCount(1)->and(DB::table('payment_transactions')->count())->toBe(1);
    });

    it('refuses a cash order, a paid order and a cancelled order, and a gateway that is down is a clear 503', function () {
        $cash = ($this->place)('cod');
        ($this->start)($cash)->assertStatus(409)->assertJsonPath('code', 'order.already_paid');

        $o = ($this->place)();
        $this->gateway->failNext('initiate');
        ($this->start)($o)->assertStatus(503)->assertJsonPath('code', 'payment.unavailable');
        expect(Order::query()->where('public_id', $o['id'])->value('payment_status'))->not->toBe('paid');

        $order = Order::query()->where('public_id', $o['id'])->firstOrFail();
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Cancelled, 'test', CancelReason::Other);
        ($this->start)($o)->assertStatus(409)->assertJsonPath('code', 'order.already_paid');
    });

    it('is for the order’s own holder: no token, or another order’s, is 404 and starts nothing', function () {
        $a = ($this->place)();
        $b = ($this->place)();

        $this->postJson("/api/v1/orders/{$a['id']}/payments", [], ($this->anon)(['Idempotency-Key' => ($this->key)()]))->assertNotFound();
        $this->postJson("/api/v1/orders/{$a['id']}/payments", [], ($this->anon)(['X-Order-Token' => $b['token'], 'Idempotency-Key' => ($this->key)()]))->assertNotFound();
        expect($this->gateway->initiated)->toBe([]);
    });

    it('works for the signed-in owner', function () {
        $token = bin2hex(random_bytes(20));
        app(AddToCart::class)->handle(CartOwner::user($this->amina->id), $this->tee['variantId'], 1);
        $body = ['address' => $this->address, 'shipping_method' => 'standard', 'payment_method' => 'online'];
        $total = $this->postJson('/api/v1/checkout/quote', $body, ($this->as)($this->amina))->json('data.totals.grand_total.amount_minor');
        $id = $this->postJson('/api/v1/checkout/orders', $body + ['expected_total' => $total], ($this->as)($this->amina) + ['Idempotency-Key' => ($this->key)()])->assertCreated()->json('data.order.id');

        $this->postJson("/api/v1/orders/{$id}/payments", [], ($this->as)($this->amina) + ['Idempotency-Key' => ($this->key)()])->assertOk();
    });
});

describe('paid means the gateway said so', function () {
    it('is not paid when the app opens the page, not paid when the gateway has not answered, and nothing the app sends can change it', function () {
        $o = ($this->place)();
        ($this->start)($o)->assertOk();

        $status = $this->getJson("/api/v1/orders/{$o['id']}/payment", ($this->h)($o));
        $status->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.can_pay', true)->assertJsonPath('data.attempts', 1)->assertJsonPath('data.amount.amount_minor', $o['total']);
        assertMatchesOpenApi($status, 'GET', "/api/v1/orders/{$o['id']}/payment");

        // the app claiming success, in every way an app could: there is no way to say it
        $this->postJson("/api/v1/orders/{$o['id']}/payment/verify", ['paid' => true, 'status' => 'VALID', 'val_id' => 'FAKE', 'amount' => $o['total']], ($this->h)($o))->assertOk()->assertJsonPath('data.paid', false);
        $this->putJson("/api/v1/orders/{$o['id']}/payment", ['paid' => true], ($this->h)($o))->assertStatus(405);
        expect(Order::query()->where('public_id', $o['id'])->value('payment_status'))->not->toBe('paid');
    });

    it('becomes paid only after the server asked the gateway and it confirmed the full amount; asking again changes nothing', function () {
        $o = ($this->place)();
        ($this->start)($o)->assertOk();
        $this->gateway->answer(($this->ref)(), ($this->paid)(($this->ref)(), $o['total']));

        $res = $this->postJson("/api/v1/orders/{$o['id']}/payment/verify", [], ($this->h)($o));
        $res->assertOk()->assertJsonPath('data.paid', true)->assertJsonPath('data.state', 'paid')->assertJsonPath('data.can_pay', false);
        assertMatchesOpenApi($res, 'POST', "/api/v1/orders/{$o['id']}/payment/verify");
        expect($this->gateway->verifications)->not->toBeEmpty()->and(Order::query()->where('public_id', $o['id'])->value('payment_status'))->toBe(PaymentStatus::Paid);

        foreach ([1, 2, 3] as $_) {
            $this->postJson("/api/v1/orders/{$o['id']}/payment/verify", [], ($this->h)($o))->assertOk()->assertJsonPath('data.paid', true);
        }
        expect((int) DB::table('payments')->sum('captured_minor'))->toBe($o['total'])->and(DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->count())->toBeLessThanOrEqual(1);
        ($this->start)($o)->assertStatus(409)->assertJsonPath('code', 'order.already_paid');
    });

    it('stays unpaid when the gateway says failed, and the person can try again', function () {
        $o = ($this->place)();
        ($this->start)($o)->assertOk();
        $this->gateway->answer(($this->ref)(), new VerifiedPayment(VerifiedStatus::Failed, ($this->ref)(), null));

        $this->postJson("/api/v1/orders/{$o['id']}/payment/verify", [], ($this->h)($o))->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.state', 'failed')->assertJsonPath('data.can_pay', true);
        ($this->start)($o)->assertOk();
        expect(DB::table('payment_transactions')->count())->toBe(2);
    });

    it('does not accept less than the full amount, or another currency, as paid', function () {
        $o = ($this->place)();
        ($this->start)($o)->assertOk();
        $this->gateway->answer(($this->ref)(), ($this->paid)(($this->ref)(), $o['total'] - 100));

        $this->postJson("/api/v1/orders/{$o['id']}/payment/verify", [], ($this->h)($o))->assertOk()->assertJsonPath('data.paid', false);
        expect(Order::query()->where('public_id', $o['id'])->value('payment_status'))->not->toBe(PaymentStatus::Paid);

        $this->gateway->answer(($this->ref)(), new VerifiedPayment(VerifiedStatus::Valid, ($this->ref)(), Money::of($o['total'], 'USD'), 'B', 'V'));
        $this->postJson("/api/v1/orders/{$o['id']}/payment/verify", [], ($this->h)($o))->assertOk()->assertJsonPath('data.paid', false);
    });

    it('reads as nothing yet for an order with no payment, and for a cash order', function () {
        $cash = ($this->place)('cod');
        $res = $this->getJson("/api/v1/orders/{$cash['id']}/payment", ($this->h)($cash));

        $res->assertOk()->assertJsonPath('data.state', 'none')->assertJsonPath('data.paid', false)->assertJsonPath('data.can_pay', false)->assertJsonPath('data.attempts', 0);
        $this->getJson("/api/v1/orders/{$cash['id']}/payment", ($this->anon)())->assertNotFound();
    });
});
