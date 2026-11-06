<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SendBookingReminders;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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
    $this->as = fn (User $u) => $this->actingAs($u, 'web');
});

describe('my bookings (signed in)', function () {
    it('lists my own bookings, coming up first, and nobody else\'s', function () {
        $mine = ($this->booked)('10:00', $this->owner);
        ($this->booked)('11:00', $this->other, $this->tailor->public_id);
        $done = ($this->booked)('09:00', $this->owner);
        DB::table('service_bookings')->where('public_id', $done)->update(['status' => 'completed']);

        $page = ($this->as)($this->owner)->get('/en/account/bookings')->assertOk();

        $page->assertSee('My bookings')->assertSee('Coming up')->assertSee('Earlier')->assertSee('Confirmed')->assertSee('Done')->assertSee('500.00')
            ->assertSee('/en/account/bookings/'.$mine, false)->assertDontSee('11:00 AM')->assertSee('1 Dec 2026, 10:00 AM');
        expect(substr_count($page->getContent(), '<h3'))->toBe(2);
    });

    it('needs a sign-in', function () {
        ($this->booked)('10:00', $this->owner);

        $this->get('/en/account/bookings')->assertRedirect();
    });

    it('does not ask more questions for more bookings', function () {
        ($this->booked)('09:00', $this->owner);
        ($this->as)($this->owner)->get('/en/account/bookings')->assertOk();   // warm the caches the page shares with every page
        DB::enableQueryLog();
        ($this->as)($this->owner)->get('/en/account/bookings')->assertOk();
        $few = count(DB::getQueryLog());

        ($this->booked)('10:00', $this->owner);
        ($this->booked)('11:00', $this->owner);
        DB::flushQueryLog();
        ($this->as)($this->owner)->get('/en/account/bookings')->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    });

    it('is in the account menu and reads in Bangla', function () {
        ($this->booked)('10:00', $this->owner);

        ($this->as)($this->owner)->get('/en/account/bookings')->assertSee('/en/account/bookings', false);
        ($this->as)($this->owner)->get('/bn/account/bookings')->assertOk()->assertSee('আমার বুকিং')->assertSee('নিশ্চিত');
    });
});

describe('one booking', function () {
    it('shows what cancelling would cost before anything is done, and changes nothing by looking', function () {
        $id = ($this->booked)();

        ($this->as)($this->owner)->get('/en/account/bookings/'.$id)->assertOk()->assertSee('cancel for free')->assertSee('500.00');
        $this->travel(1)->minutes();
        ($this->as)($this->owner)->get('/en/account/bookings/'.$id)->assertOk()->assertSee('50%')->assertSee('250.00')->assertSee('Our team approves the refund');
        expect(($this->row)($id)->status)->toBe('confirmed')->and(DB::table('booking_cancellations')->count())->toBe(0);
    });

    it('is not found for somebody else\'s booking or one that does not exist', function () {
        $id = ($this->booked)();

        ($this->as)($this->other)->get('/en/account/bookings/'.$id)->assertNotFound();
        ($this->as)($this->owner)->get('/en/account/bookings/01zzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
    });

    it('cancels, tells the customer what is due, and cannot be done twice or for another customer', function () {
        $id = ($this->booked)();
        $this->travel(1)->minutes();

        ($this->as)($this->other)->post('/en/account/bookings/'.$id.'/cancel')->assertRedirect();
        expect(($this->row)($id)->status)->toBe('confirmed');

        ($this->as)($this->owner)->post('/en/account/bookings/'.$id.'/cancel', ['reason' => 'plans changed'])->assertRedirect('/en/account/bookings/'.$id)->assertSessionHas('status', 'Your booking was cancelled.');
        $row = DB::table('booking_cancellations')->first();
        expect(($this->row)($id)->status)->toBe('cancelled')->and($row->by_type)->toBe('customer')->and((int) $row->fee_minor)->toBe(25000)->and((int) $row->refund_due_minor)->toBe(25000)->and($row->reason)->toBe('plans changed');

        ($this->as)($this->owner)->get('/en/account/bookings/'.$id)->assertSee('This booking was cancelled')->assertSee('Due back to you')->assertSee('250.00');
        ($this->as)($this->owner)->post('/en/account/bookings/'.$id.'/cancel')->assertSessionHasErrors('booking');
        expect(DB::table('booking_cancellations')->count())->toBe(1);
    });

    it('moves to one of the really free times, counts the move, and refuses a time that is not offered', function () {
        $id = ($this->booked)();
        ($this->booked)('11:00', $this->other);   // somebody else has 11:00

        $page = ($this->as)($this->owner)->get('/en/account/bookings/'.$id.'?date=2026-12-01')->assertOk()->assertSee('2 more time(s)');
        $page->assertSee('9:00 AM')->assertSee('9:30 AM')->assertDontSee('11:00 AM');   // the own old time counts as free; the other booking does not

        ($this->as)($this->owner)->post('/en/account/bookings/'.$id.'/move', ['start' => ($this->local)('11:00')])->assertSessionHasErrors('booking');
        ($this->as)($this->owner)->post('/en/account/bookings/'.$id.'/move', ['start' => ($this->local)('09:30')])->assertRedirect()->assertSessionHas('status', 'Your booking was moved.');
        expect(CarbonImmutable::parse(($this->row)($id)->starts_at, 'UTC')->format('H:i'))->toBe('03:30')->and((int) ($this->row)($id)->reschedule_count)->toBe(1);
        ($this->as)($this->other)->post('/en/account/bookings/'.$id.'/move', ['start' => ($this->local)('09:00')]);
        expect(CarbonImmutable::parse(($this->row)($id)->starts_at, 'UTC')->format('H:i'))->toBe('03:30');   // not by somebody else
    });

    it('does not offer a move once it is too late, and says why', function () {
        $id = ($this->booked)();
        $this->travel(1)->minutes();

        ($this->as)($this->owner)->get('/en/account/bookings/'.$id)->assertOk()->assertSee('can no longer be moved')->assertDontSee('Show times');
        ($this->as)($this->owner)->post('/en/account/bookings/'.$id.'/move', ['start' => ($this->local)('09:30')])->assertSessionHasErrors('booking');
    });

    it('can be marked as done once it has started, and not before', function () {
        $id = ($this->booked)();

        ($this->as)($this->owner)->get('/en/account/bookings/'.$id)->assertDontSee('Mark as done');
        ($this->as)($this->owner)->post('/en/account/bookings/'.$id.'/complete')->assertSessionHasErrors('booking');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:30:00', 'UTC'));
        ($this->as)($this->owner)->get('/en/account/bookings/'.$id)->assertSee('Mark as done');
        ($this->as)($this->owner)->post('/en/account/bookings/'.$id.'/complete')->assertRedirect()->assertSessionHas('status');

        expect(($this->row)($id)->status)->toBe('completed')->and(($this->row)($id)->completed_by)->toBe('customer');
    });

    it('cannot be marked as done by somebody else, even once it has started', function () {
        $id = ($this->booked)();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:30:00', 'UTC'));

        ($this->as)($this->other)->post('/en/account/bookings/'.$id.'/complete')->assertRedirect();
        expect(($this->row)($id)->status)->toBe('confirmed')->and(($this->row)($id)->completed_by)->toBeNull();
    });

    it('is in Bangla too', function () {
        $id = ($this->booked)();

        ($this->as)($this->owner)->get('/bn/account/bookings/'.$id)->assertOk()->assertSee('এই বুকিং বাতিল করুন')->assertSee('এই বুকিং সরান');
    });
});

describe('my bookings (a guest)', function () {
    beforeEach(function () {
        $this->id = ($this->booked)('10:00', null, null, 'g', true);
        $this->order = $this->lastOrder;
        $this->link = fn (string $route = 'bookings.guest', ?string $order = null, $expires = null) => URL::temporarySignedRoute($route, $expires ?? now()->addDay(), ['locale' => 'en', 'order' => $order ?? $this->order->public_id]);
        $this->code = function () {
            $this->post(($this->link)('bookings.guest.code'))->assertRedirect();

            return $this->sms->lastCode();
        };
        $this->open = function () {
            $this->post(($this->link)('bookings.guest.verify'), ['code' => ($this->code)()])->assertRedirect();
        };
    });

    it('needs the link we signed, unchanged and not expired, for an order that has a booking', function () {
        $this->get("/en/orders/{$this->order->public_id}/bookings")->assertForbidden();
        $this->get(($this->link)().'x')->assertForbidden();
        $this->get(($this->link)('bookings.guest', null, now()->subMinute()))->assertForbidden();
        $this->get(($this->link)('bookings.guest', '01zzzzzzzzzzzzzzzzzzzzzzzz'))->assertNotFound();
        $this->get(($this->link)())->assertOk()->assertSee('Send me a code')->assertSee('ending in 678')->assertDontSee('SVC-30');
    });

    it('opens its bookings only after the right code, and a wrong one says the same thing each time', function () {
        $code = ($this->code)();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->post(($this->link)('bookings.guest.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
        $this->get("/en/orders/{$this->order->public_id}/bookings/{$this->id}")->assertForbidden();
        $this->post(($this->link)('bookings.guest.verify'), ['code' => $code])->assertRedirect();

        $this->get(($this->link)())->assertOk()->assertSee('SVC-30')->assertSee('/bookings/'.$this->id, false);
        $this->get("/en/orders/{$this->order->public_id}/bookings/{$this->id}")->assertOk()->assertSee('cancel for free');
    });

    it('lets the guest cancel their own booking, and nothing of any other order', function () {
        $stranger = ($this->booked)('11:00', null, null, 'h', true);
        $strangerOrder = $this->lastOrder;
        ($this->open)();
        $base = "/en/orders/{$this->order->public_id}/bookings";

        $this->get("{$base}/{$stranger}")->assertNotFound();
        $this->post("{$base}/{$stranger}/cancel")->assertRedirect();   // refused: it is not this order's
        expect(($this->row)($stranger)->status)->toBe('confirmed');
        $this->post("/en/orders/{$strangerOrder->public_id}/bookings/{$stranger}/cancel")->assertForbidden();   // no session for that order

        $this->post("{$base}/{$this->id}/cancel", ['reason' => 'sorry'])->assertRedirect("{$base}/{$this->id}");
        $row = DB::table('booking_cancellations')->first();
        expect(($this->row)($this->id)->status)->toBe('cancelled')->and($row->by_type)->toBe('customer')->and(DB::table('booking_events')->where('actor_type', 'guest')->where('event', 'cancelled')->count())->toBe(1);
    });

    it('lets the guest move it, but never ask for a fee waiver or to cancel after the start', function () {
        ($this->open)();
        $base = "/en/orders/{$this->order->public_id}/bookings/{$this->id}";

        $this->post("{$base}/move", ['start' => ($this->local)('09:30')])->assertRedirect();
        expect(CarbonImmutable::parse(($this->row)($this->id)->starts_at, 'UTC')->format('H:i'))->toBe('03:30');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 03:45:00', 'UTC'));
        $this->withSession(['bookings.guest' => [$this->order->public_id => now()->addMinutes(10)->getTimestamp()]])->post("{$base}/cancel")->assertSessionHasErrors('booking');
        expect(($this->row)($this->id)->status)->toBe('confirmed');
    });
});

describe('the reminders', function () {
    it('carry a link to the booking: the account page for a customer, a signed one for a guest', function () {
        ($this->booked)('10:00', $this->owner);
        ($this->booked)('10:00', null, $this->room->public_id, 'g', true);

        app(SendBookingReminders::class)->handle();

        $texts = array_values(array_filter($this->sms->sent(), fn ($m) => str_starts_with((string) ($m['meta']['purpose'] ?? ''), 'booking.')));
        expect($texts)->toHaveCount(2)->and($texts[0]['message'])->toContain('/en/account/bookings/')->and($texts[1]['message'])->toContain('/en/orders/')->and($texts[1]['message'])->toContain('signature=');
    });
});
