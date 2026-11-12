<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/* Phase 11, slice 6b: booking a time for a service, and managing my bookings, for the app. Same services and Actions as the website. */

beforeEach(function () {
    bookingsWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->seed([PriceListSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    Mail::fake();
    app(SetRegularPrice::class)->handle($this->staff, $this->variant30, Money::of(50000));
    app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->variant30))->firstOrFail());
    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));   // exactly 24 hours before a 10:00 Dhaka start on Tuesday 1 December
    app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'at_provider', 60, 60, 15, true);
    foreach ([$this->tailor, $this->room] as $resource) {
        app(SetWeeklyAvailability::class)->handle($this->staff, $resource, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
    }
    $this->owner = User::factory()->create(['email' => 'o@example.com']);
    $this->other = User::factory()->create(['email' => 'x@example.com']);
    $this->local = fn (string $t) => CarbonImmutable::parse("2026-12-01 {$t}", 'Asia/Dhaka')->utc()->format('Y-m-d H:i:s');

    /** A paid, confirmed booking; for a user, or (when $user is null) for a guest. Returns its public id; $this->lastOrder is the order. */
    $this->booked = function (string $local = '10:00', ?User $user = null, ?string $resource = null, string $token = 'a', bool $guest = false) {
        $user = $guest ? null : ($user ?? $this->owner);
        $owner = $user === null ? CartOwner::guest(str_repeat($token, 40)) : CartOwner::user($user->id);
        app(HoldSlot::class)->handle($owner, 'SVC-30', $resource ?? $this->tailor->public_id, ($this->local)($local));
        $pay = 'online';
        $order = app(PlaceOrder::class)->handle(new PlaceOrderCommand(
            actor: Actor::guest('checkout'), owner: $owner, userId: $user?->id, contactName: 'Rahim Uddin', contactPhone: '01712345678', contactEmail: null, address: null, shippingMethodCode: null, paymentMethodCode: $pay,
            expectedTotalMinor: app(CheckoutPricing::class)->quote(app(CartReader::class)->view($owner, 'en'), null, null, $pay, 'en')->breakdown->grandTotalMinor, placeToken: 'token-'.Str::random(12), locale: 'en',
        ));
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Paid, 'test');
        app(OutboxDispatcher::class)->dispatchDue();
        $this->lastOrder = $order;

        return (string) DB::table('service_bookings')->where('order_item_id', DB::table('order_items')->where('order_id', $order->id)->value('id'))->value('public_id');
    };
    $this->row = fn (string $publicId) => DB::table('service_bookings')->where('public_id', $publicId)->first();
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->api = function (User $u) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
    };
    $this->anon = function (array $extra = []) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return $extra;
    };
});

describe('free times and holding one', function () {
    it('lists the times that are really free on a day, with the price, for anyone, and never from a cache', function () {
        $res = $this->getJson('/api/v1/services/SVC-30/slots?date=2026-12-01', ($this->anon)());

        $res->assertOk()->assertJsonPath('data.sku', 'SVC-30')->assertJsonPath('data.date', '2026-12-01')->assertJsonPath('data.price.amount_minor', 50000);
        assertMatchesOpenApi($res, 'GET', '/api/v1/services/SVC-30/slots?date=2026-12-01');
        $slots = $res->json('data.slots');
        expect($slots)->not->toBeEmpty()->and(array_unique(array_column($slots, 'resource_name')))->toContain('Rahim (tailor)', 'Fitting room')->and($res->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('stops offering a time once it is taken', function () {
        $before = collect($this->getJson('/api/v1/services/SVC-30/slots?date=2026-12-01&resource='.$this->tailor->public_id, ($this->anon)())->json('data.slots'));
        ($this->booked)('10:00');
        $after = collect($this->getJson('/api/v1/services/SVC-30/slots?date=2026-12-01&resource='.$this->tailor->public_id, ($this->anon)())->json('data.slots'));

        expect($before->count())->toBeGreaterThan($after->count())->and($after->pluck('starts_at')->all())->not->toContain(CarbonImmutable::parse(($this->local)('10:00'), 'UTC')->toIso8601String());
    });

    it('refuses a day outside the booking window, a malformed date and an unknown or non-service product', function () {
        $this->getJson('/api/v1/services/SVC-30/slots?date=2026-11-01', ($this->anon)())->assertStatus(422)->assertJsonPath('code', 'request.refused');
        $this->getJson('/api/v1/services/SVC-30/slots?date=2030-01-01', ($this->anon)())->assertStatus(422)->assertJsonPath('code', 'request.refused');
        $this->getJson('/api/v1/services/SVC-30/slots?date=tomorrow', ($this->anon)())->assertStatus(422)->assertJsonValidationErrors(['date']);
        $this->getJson('/api/v1/services/NOPE/slots?date=2026-12-01', ($this->anon)())->assertNotFound();
        $this->getJson('/api/v1/services/TEE-1/slots?date=2026-12-01', ($this->anon)())->assertNotFound();
    });

    it('holds a time for a guest, who gets a cart token, and the booking waits in the cart', function () {
        $slot = $this->getJson('/api/v1/services/SVC-30/slots?date=2026-12-01&resource='.$this->tailor->public_id, ($this->anon)())->json('data.slots.0');
        $res = $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => $slot['resource'], 'start' => $slot['starts_at']], ($this->anon)());

        $res->assertCreated()->assertJsonPath('data.status', 'held');
        assertMatchesOpenApi($res, 'POST', '/api/v1/services/SVC-30/hold');
        $token = $res->json('data.cart_token');
        expect($token)->toMatch('/^[a-f0-9]{40}$/')->and($res->headers->get('X-Cart-Token'))->toBe($token)->and($res->json('data.expires_at'))->not->toBeNull();

        $cart = $this->getJson('/api/v1/cart', ($this->anon)(['X-Cart-Token' => $token]));
        $cart->assertOk()->assertJsonPath('data.item_count', 1)->assertJsonPath('data.lines.0.type', 'service');
    });

    it('holds a time for a signed-in customer without a cart token, and a new hold replaces the old one', function () {
        $slots = $this->getJson('/api/v1/services/SVC-30/slots?date=2026-12-01&resource='.$this->tailor->public_id, ($this->anon)())->json('data.slots');
        $first = $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => $slots[0]['resource'], 'start' => $slots[0]['starts_at']], ($this->api)($this->owner));
        $first->assertCreated()->assertJsonPath('data.cart_token', null);
        $later = end($slots); // a later time that does not overlap the first hold
        $second = $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => $later['resource'], 'start' => $later['starts_at']], ($this->api)($this->owner));
        $second->assertCreated();

        expect(DB::table('service_bookings')->where('public_id', $first->json('data.id'))->value('status'))->toBe('cancelled')->and(DB::table('service_bookings')->where('status', 'held')->count())->toBe(1);
    });

    it('tells the second person the time is gone', function () {
        $slot = $this->getJson('/api/v1/services/SVC-30/slots?date=2026-12-01&resource='.$this->tailor->public_id, ($this->anon)())->json('data.slots.0');
        $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => $slot['resource'], 'start' => $slot['starts_at']], ($this->api)($this->owner))->assertCreated();

        $late = $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => $slot['resource'], 'start' => $slot['starts_at']], ($this->api)($this->other));
        $late->assertStatus(409)->assertJsonPath('code', 'booking.slot_taken');
        expect($late->json('detail'))->not->toBeEmpty();
    });

    it('refuses a time that was never offered, and a malformed request', function () {
        $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => $this->tailor->public_id, 'start' => '2026-12-01T22:00:00Z'], ($this->anon)())->assertStatus(409)->assertJsonPath('code', 'booking.slot_taken');
        $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => $this->tailor->public_id, 'start' => 'banana'], ($this->anon)())->assertStatus(422)->assertJsonPath('code', 'request.refused');
        $this->postJson('/api/v1/services/SVC-30/hold', [], ($this->anon)())->assertStatus(422)->assertJsonValidationErrors(['resource', 'start']);
        $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => 'x', 'start' => '2026-12-01T05:00:00Z'], ($this->anon)())->assertStatus(422);
        expect(DB::table('service_bookings')->count())->toBe(0);
    });

    it('rejects a bad bearer token instead of holding as a guest', function () {
        $slot = $this->getJson('/api/v1/services/SVC-30/slots?date=2026-12-01&resource='.$this->tailor->public_id, ($this->anon)())->json('data.slots.0');

        $this->postJson('/api/v1/services/SVC-30/hold', ['resource' => $slot['resource'], 'start' => $slot['starts_at']], ['Authorization' => 'Bearer 1|nonsense'])->assertStatus(401);
        expect(DB::table('service_bookings')->count())->toBe(0);
    });
});

describe('my bookings', function () {
    it('lists mine with what I may do, and shows one; never anybody else’s', function () {
        $mine = ($this->booked)('10:00');
        $mineOrder = $this->lastOrder;
        ($this->booked)('11:00', $this->other, null, 'b');

        $list = $this->getJson('/api/v1/bookings', ($this->api)($this->owner));
        $list->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine)->assertJsonPath('data.0.can_cancel', true)->assertJsonPath('data.0.can_reschedule', true)->assertJsonPath('data.0.paid.amount_minor', 50000);
        assertMatchesOpenApi($list, 'GET', '/api/v1/bookings');

        $one = $this->getJson("/api/v1/bookings/{$mine}", ($this->api)($this->owner));
        $one->assertOk()->assertJsonPath('data.order.number', $mineOrder->order_number);
        assertMatchesOpenApi($one, 'GET', "/api/v1/bookings/{$mine}");
    });

    it('answers 404 to every route for somebody else’s booking, and changes nothing', function () {
        $mine = ($this->booked)('10:00');
        $h = fn () => ($this->api)($this->other);

        $this->getJson("/api/v1/bookings/{$mine}", $h())->assertNotFound();
        $this->postJson("/api/v1/bookings/{$mine}/cancel", [], $h())->assertNotFound();
        $this->postJson("/api/v1/bookings/{$mine}/reschedule", ['start' => '2026-12-01T06:00:00Z'], $h())->assertNotFound();
        $this->postJson("/api/v1/bookings/{$mine}/complete", [], $h())->assertNotFound();
        expect(($this->row)($mine)->status)->toBe('confirmed');
        $this->getJson('/api/v1/bookings', $h())->assertJsonPath('data', []);
    });

    it('cancels under the terms, and says what it cost', function () {
        $mine = ($this->booked)('10:00');
        $one = $this->getJson("/api/v1/bookings/{$mine}", ($this->api)($this->owner));
        expect($one->json('data.if_cancelled'))->not->toBeNull();

        $this->postJson("/api/v1/bookings/{$mine}/cancel", ['reason' => 'plans changed'], ($this->api)($this->owner))->assertNoContent();
        expect(($this->row)($mine)->status)->toBe('cancelled');
        $after = $this->getJson("/api/v1/bookings/{$mine}", ($this->api)($this->owner));
        $after->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.can_cancel', false);
        $this->postJson("/api/v1/bookings/{$mine}/cancel", [], ($this->api)($this->owner))->assertStatus(422)->assertJsonPath('code', 'request.refused');
    });

    it('moves a booking to another free time while moves are left, and refuses a time that is taken or too soon', function () {
        $mine = ($this->booked)('10:00', null, $this->tailor->public_id);
        $taken = ($this->booked)('11:00', $this->other, $this->tailor->public_id, 'b');
        $free = collect($this->getJson('/api/v1/services/SVC-30/slots?date=2026-12-01&resource='.$this->tailor->public_id, ($this->anon)())->json('data.slots'))->first(fn ($s) => $s['starts_at'] !== CarbonImmutable::parse(($this->local)('10:00'), 'UTC')->toIso8601String());

        $this->postJson("/api/v1/bookings/{$mine}/reschedule", ['start' => CarbonImmutable::parse(($this->local)('11:00'), 'UTC')->toIso8601String()], ($this->api)($this->owner))->assertStatus(409)->assertJsonPath('code', 'booking.slot_taken');
        $this->postJson("/api/v1/bookings/{$mine}/reschedule", ['start' => $free['starts_at']], ($this->api)($this->owner))->assertNoContent();
        expect(CarbonImmutable::parse(($this->row)($mine)->starts_at, 'UTC')->toIso8601String())->toBe($free['starts_at']);
        $this->getJson("/api/v1/bookings/{$mine}", ($this->api)($this->owner))->assertJsonPath('data.reschedules_left', fn ($n) => is_int($n));
        $this->postJson("/api/v1/bookings/{$mine}/reschedule", [], ($this->api)($this->owner))->assertStatus(422)->assertJsonValidationErrors(['start']);
    });

    it('does not let a booking be marked done before its time', function () {
        $mine = ($this->booked)('10:00');

        $this->postJson("/api/v1/bookings/{$mine}/complete", [], ($this->api)($this->owner))->assertStatus(422)->assertJsonPath('code', 'request.refused');
        expect(($this->row)($mine)->status)->toBe('confirmed');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 05:00:00', 'UTC')); // after the 10:00 Dhaka start
        $this->postJson("/api/v1/bookings/{$mine}/complete", [], ($this->api)($this->owner))->assertNoContent();
    });

    it('needs a signed-in customer', function () {
        $this->getJson('/api/v1/bookings', ($this->anon)())->assertStatus(401);
        $this->postJson('/api/v1/bookings/'.str_repeat('0', 26).'/cancel', [], ($this->anon)())->assertStatus(401);
    });
});
